<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\Jeebly;
use App\Models\OrderModel;
use App\Models\OrderShippingEventModel;

/**
 * Status pushes from Jeebly. Authentication: same X-API-KEY as outbound
 * (per Jeebly's docs they offer no HMAC / IP allowlist). We treat that key as
 * a weak transport check; the join key (shipping_reference) is what couples
 * the event to a local order.
 *
 * State-machine notes:
 *   - shipped_at / delivered_at / cancelled_at are stamped with the EVENT
 *     time (not server now), so an event arriving late records the real
 *     fulfillment moment.
 *   - Side-effects use "set if NULL" semantics so out-of-order arrivals
 *     (Delivered before Pickup Completed) still stamp shipped_at when the
 *     pickup event eventually arrives.
 *   - Unknown statuses are logged at error-level (not warning) so they show
 *     up loudly in monitoring; we don't silently lose terminal transitions.
 *
 * Webhook auth security:
 *   - Dev bypass is gated by ENVIRONMENT=='development' AND an EXPLICIT
 *     env flag `jeebly.allow_unsigned_webhook=true`. Production cannot
 *     accidentally enable it just by leaving jeebly.x_api_key blank.
 */
class JeeblyWebhook extends BaseController
{
    public function handle()
    {
        // ---------- Auth ----------
        $jeebly = Jeebly::fromEnv();
        $providedKey = (string) $this->request->getHeaderLine('X-API-KEY');
        $devSkip = ENVIRONMENT === 'development'
            && !$jeebly->isConfigured()
            && (env('jeebly.allow_unsigned_webhook') === true || env('jeebly.allow_unsigned_webhook') === 'true');
        if ($devSkip) {
            log_message('warning', 'Jeebly webhook: dev-mode UNSIGNED bypass active');
        }
        if (!$devSkip && !$jeebly->verifyWebhookKey($providedKey)) {
            log_message('warning', 'Jeebly webhook: bad X-API-KEY');
            return $this->fail('BAD_KEY', 'Invalid Jeebly API key', null, 401);
        }

        // ---------- Parse ----------
        $payload = json_decode((string) $this->request->getBody(), true);
        if (!is_array($payload) || empty($payload['reference_no']) || empty($payload['status'])) {
            return $this->fail('BAD_PAYLOAD', 'Missing reference_no/status', null, 400);
        }

        $ref     = (string) $payload['reference_no'];
        $status  = substr(trim((string) $payload['status']), 0, 64); // VARCHAR(64) safe
        $eventAt = $this->parseEventAt($payload['event_date_time'] ?? null);
        log_message('info', "Jeebly webhook: {$status} for {$ref}");

        // Find the local order — scope by provider too so future couriers can't collide.
        $om    = new OrderModel();
        $order = $om->where('shipping_provider', 'jeebly')
                    ->where('shipping_reference', $ref)
                    ->first();
        if (!$order) {
            log_message('info', "Jeebly webhook: no local jeebly-provider order for ref {$ref}");
            return $this->ok(['received' => true, 'matched' => false]);
        }

        // ---------- Event insert (dedupe-only catch) ----------
        $events = new OrderShippingEventModel();
        $eventRow = [
            'order_id'       => (int) $order['id'],
            'reference_no'   => $ref,
            'status'         => $status,
            'description'    => substr((string) ($payload['desc'] ?? ''), 0, 500),
            'hub_name'       => substr((string) ($payload['hub_name'] ?? ''), 0, 120),
            'event_at'       => $eventAt,
            'rider_code'     => substr((string) ($payload['rider_code'] ?? ''), 0, 64),
            'rider_name'     => substr((string) ($payload['rider_name'] ?? ''), 0, 120),
            'pod_image_url'  => substr((string) ($payload['pod_image'] ?? ''), 0, 500),
            'failure_reason' => substr((string) ($payload['failure_reason'] ?? ''), 0, 500),
            'raw_payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at'     => date('Y-m-d H:i:s'),
        ];
        try {
            $events->insert($eventRow);
        } catch (\Throwable $e) {
            // Only "duplicate key" (MySQL 1062 / SQLSTATE 23000) is expected here;
            // any other error means corruption — log it but keep processing.
            $msg = $e->getMessage();
            if (stripos($msg, '1062') !== false || stripos($msg, 'duplicate') !== false) {
                log_message('info', "Jeebly webhook dedupe skip for {$ref}/{$status}");
            } else {
                log_message('error', 'Jeebly webhook event insert failed: ' . $msg);
            }
        }

        // ---------- Status transitions ----------
        // Always update the "last known" columns.
        $patch = [
            'shipping_status'        => $status,
            'shipping_last_event_at' => $eventAt,
        ];
        $orderStatus = $order['status'] ?? '';

        // SET-IF-NULL side effects: out-of-order events still fill in missing milestones.
        $isTerminalLocal = in_array($orderStatus, ['delivered', 'refunded', 'cancelled'], true);

        switch ($status) {
            case 'Pickup Completed':
                if (empty($order['shipped_at'])) $patch['shipped_at'] = $eventAt;
                if (in_array($orderStatus, ['placed', 'confirmed'], true)) {
                    $patch['status'] = 'shipped';
                }
                break;

            case 'Delivered':
                if (empty($order['delivered_at'])) $patch['delivered_at'] = $eventAt;
                // Stamp shipped_at too if Pickup Completed never arrived.
                if (empty($order['shipped_at'])) $patch['shipped_at'] = $eventAt;
                if (!in_array($orderStatus, ['delivered', 'refunded'], true)) {
                    $patch['status'] = 'delivered';
                }
                // COD paid-on-delivery. Allow pending OR failed → paid; log if we skip.
                if (($order['payment_method'] ?? '') === 'cod') {
                    $ps = $order['payment_status'] ?? '';
                    if (in_array($ps, ['pending', 'failed'], true)) {
                        $patch['payment_status'] = 'paid';
                    } else {
                        log_message('warning', "Jeebly Delivered for COD order #{$order['id']} but payment_status is '{$ps}' — manual reconcile");
                    }
                }
                break;

            case 'Cancelled':
            case 'Not Picked Up':
            case 'RTO Delivered':
                if (!$isTerminalLocal) {
                    $patch['status']           = 'cancelled';
                    $patch['cancelled_at']     = $eventAt;
                    $patch['cancelled_reason'] = 'Jeebly: ' . $status;
                }
                break;

            // Informational — only update shipping_status (already in patch).
            case 'Undelivered':
            case 'On-Hold':
            case 'On – Hold': // unicode en-dash variant observed in docs
            case 'Rescheduled':
            case 'Order Updated':
            case 'Reached At Hub':
            case 'Inscan At Hub':
            case 'Out For Delivery':
            case 'RTO':
            case 'Pickup Scheduled':
                break;

            default:
                // Loud — a new terminal status from Jeebly must not silently leave
                // an order stuck in 'shipped'. Admin should triage.
                log_message('error', "Jeebly webhook: UNKNOWN status '{$status}' for order #{$order['id']} ref {$ref}");
                break;
        }

        try {
            $om->update($order['id'], $patch);
        } catch (\Throwable $e) {
            log_message('error', 'Jeebly webhook: order update failed: ' . $e->getMessage());
            return $this->fail('DB_ERROR', 'Failed to update order', null, 500);
        }

        return $this->ok(['received' => true, 'order_id' => (int) $order['id'], 'status' => $status]);
    }

    /** Parse Jeebly's "2024-10-01T03:03:09Z" UTC into MySQL DATETIME using the app's configured TZ. */
    private function parseEventAt($raw): string
    {
        if (!is_string($raw) || $raw === '') return date('Y-m-d H:i:s');
        try {
            $dt = new \DateTime($raw);
            // Use the app's configured timezone (Config\App::$appTimezone) so event_at
            // is consistent with everything else stored as DATETIME.
            $appTz = config('App')->appTimezone ?? 'UTC';
            $dt->setTimezone(new \DateTimeZone($appTz));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return date('Y-m-d H:i:s');
        }
    }
}
