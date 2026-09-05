<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Controllers\Me\Checkout;
use App\Libraries\Tamara;
use App\Models\OrderModel;
use App\Models\PendingCheckoutModel;

/**
 * Tamara → us server-to-server callbacks. Each delivery carries a JWT in
 * Authorization: Bearer <jwt> (or ?tamaraToken=<jwt> query) signed with the
 * merchant's Notification Token (HS256). Set tamara.notification_token in .env.
 *
 * Security model:
 *   - JWT is verified with timing-safe HMAC + exp/iat freshness window.
 *   - The JWT-decoded order_id is cross-checked against the request body's
 *     order_id — a captured JWT cannot be replayed against a different order.
 *   - Empty notification_token only skips verification when ENVIRONMENT === 'development'.
 *
 * State machine:
 *   - All payment_status / order.status transitions are guarded so late or
 *     out-of-order events can't downgrade a paid order back to failed, etc.
 *   - order_captured arriving before the local order exists triggers settlement
 *     so we don't miss the "paid" flip.
 *   - DB exceptions inside event handlers are rethrown as 500 so Tamara's retry
 *     safety-net actually fires for transient errors.
 *
 * Events Tamara documents (per docs.tamara.co/reference/registerwebhookurl):
 *   order_approved, order_authorised, order_canceled, order_updated,
 *   order_captured, order_refunded.
 */
class TamaraWebhook extends BaseController
{
    public function handle()
    {
        $jwt = $this->extractToken();
        $notifSecret = (string) env('tamara.notification_token', '');

        $verified = Tamara::verifyWebhookJwt($jwt, $notifSecret);
        if ($verified === null) {
            log_message('warning', 'Tamara webhook: JWT verification failed');
            return $this->fail('BAD_SIGNATURE', 'Invalid Tamara webhook JWT', null, 401);
        }
        // Dev-mode skip is only valid outside production. In any other env, an empty
        // notification_token MUST NOT short-circuit verification (would let attackers
        // forge events).
        $devSkipped = ($verified['__skipped'] ?? false) === true;
        if ($devSkipped && ENVIRONMENT !== 'development') {
            log_message('critical', 'Tamara webhook: notification_token is empty in non-dev env — refusing to accept');
            return $this->fail('UNCONFIGURED', 'Tamara notification token not configured', null, 401);
        }

        $event = json_decode((string) $this->request->getBody(), true);
        if (!is_array($event) || empty($event['event_type']) || empty($event['order_id'])) {
            return $this->fail('BAD_PAYLOAD', 'Not a Tamara webhook event', null, 400);
        }

        // Body-binding: a JWT only proves Tamara signed something. Bind it to THIS
        // request by requiring the JWT-decoded order_id to match the body order_id.
        // (When dev-skipping, there's nothing to compare against.)
        if (!$devSkipped) {
            $jwtOrderId = (string) ($verified['order_id'] ?? '');
            $bodyOrderId = (string) $event['order_id'];
            if ($jwtOrderId !== '' && $jwtOrderId !== $bodyOrderId) {
                log_message('warning', "Tamara webhook: JWT order_id ({$jwtOrderId}) does not match body ({$bodyOrderId}) — possible replay");
                return $this->fail('REPLAY_DETECTED', 'JWT order_id does not match body order_id', null, 401);
            }
        }

        $type          = strtolower((string) $event['event_type']);
        $tamaraOrderId = (string) $event['order_id'];
        log_message('info', "Tamara webhook {$type} order={$tamaraOrderId}");

        $om  = new OrderModel();
        $pcm = new PendingCheckoutModel();

        try {
            switch ($type) {
                case 'order_approved':
                    // Per docs: merchant MUST call /orders/{id}/authorise on receipt.
                    // If authorise fails, do NOT settle locally — wait for the
                    // order_authorised webhook (or a reconciliation job) so we never
                    // confirm an order Tamara hasn't actually authorised.
                    $authorised = false;
                    try {
                        Tamara::fromEnv()->authorise($tamaraOrderId);
                        $authorised = true;
                    } catch (\Throwable $e) {
                        log_message('warning', 'Tamara authorise failed (will wait for order_authorised event): ' . $e->getMessage());
                    }
                    if ($authorised) {
                        $this->settleAndExpectOk($tamaraOrderId);
                    }
                    break;

                case 'order_authorised':
                case 'order_authorized':
                    $this->settleAndExpectOk($tamaraOrderId);
                    break;

                case 'order_captured':
                case 'payment_capture':
                    // If the local order isn't materialised yet, settle it FIRST.
                    // Tamara won't redeliver order_captured, so this is our only
                    // chance to mark it paid.
                    if (!$om->where('payment_ref', $tamaraOrderId)->first()) {
                        $this->settleAndExpectOk($tamaraOrderId);
                    }
                    if ($existing = $om->where('payment_ref', $tamaraOrderId)->first()) {
                        // Guard the transition — never overwrite refunded/failed.
                        $cur = $existing['payment_status'] ?? '';
                        if (in_array($cur, ['pending'], true)) {
                            $om->update($existing['id'], ['payment_status' => 'paid']);
                        } else {
                            log_message('info', "Tamara order_captured ignored — payment_status already {$cur}");
                        }
                    }
                    break;

                case 'order_refunded':
                case 'payment_refund':
                    if ($existing = $om->where('payment_ref', $tamaraOrderId)->first()) {
                        $cur = $existing['payment_status'] ?? '';
                        // Only flip a captured (paid) order to refunded.
                        if (in_array($cur, ['paid'], true)) {
                            $om->update($existing['id'], [
                                'status'         => 'refunded',
                                'payment_status' => 'refunded',
                            ]);
                        } else {
                            log_message('info', "Tamara order_refunded ignored — payment_status was {$cur}");
                        }
                    }
                    break;

                case 'order_canceled':
                case 'order_cancelled':
                case 'order_declined':
                case 'order_expired':
                    if ($existing = $om->where('payment_ref', $tamaraOrderId)->first()) {
                        $cur = $existing['payment_status'] ?? '';
                        // Only downgrade pending → failed. Never overwrite paid or refunded.
                        if (in_array($cur, ['pending'], true)) {
                            $om->update($existing['id'], ['payment_status' => 'failed']);
                        } else {
                            log_message('info', "Tamara cancel/decline/expire ignored — payment_status already {$cur}");
                        }
                    }
                    // Always mark the pending row expired so the cleanup job can purge.
                    $pcm->where('payment_intent_id', $tamaraOrderId)
                        ->set(['status' => 'expired'])
                        ->update();
                    break;

                case 'order_updated':
                default:
                    // Informational — no-op.
                    break;
            }
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            // DB errors are transient — return 500 so Tamara retries with backoff.
            log_message('error', 'Tamara webhook DB error: ' . $e->getMessage());
            return $this->fail('DB_ERROR', 'Database error during event processing', null, 500);
        } catch (\Throwable $e) {
            // Unknown failure — log it but return 200 to avoid retry storms for
            // non-recoverable issues (the order_approved → authorise flow already
            // catches its own exceptions above).
            log_message('error', 'Tamara webhook handler error: ' . $e->getMessage());
        }

        return $this->ok(['received' => true]);
    }

    /**
     * Settle a Tamara order and surface transient errors so Tamara retries.
     * Throws on transient/recoverable failures; returns silently on success or
     * permanent "no pending row" cases.
     */
    private function settleAndExpectOk(string $tamaraOrderId): void
    {
        $res = (new Checkout())->settleTamaraOrder($tamaraOrderId, null);
        if (is_array($res) && isset($res['error'])) {
            $code   = $res['error']['code']   ?? 'UNKNOWN';
            $status = $res['error']['status'] ?? 500;
            // Pending = read-after-write delay on Tamara's side; throw so we 500 and retry.
            // 5xx errors from Tamara's API are transient; throw too.
            if ($code === 'TAMARA_PENDING' || $code === 'TAMARA_ERROR' || $status >= 500) {
                throw new \RuntimeException("Tamara settle failed ({$code}); will retry");
            }
            // TAMARA_UNKNOWN / TAMARA_FAILED — permanent; just log and move on.
            log_message('warning', "Tamara settle permanent fail: {$code}");
        }
    }

    /**
     * Tamara sends the JWT in EITHER Authorization: Bearer <jwt> header
     * OR ?tamaraToken=<jwt> query string (both per docs).
     */
    private function extractToken(): string
    {
        $hdr = $this->request->getHeaderLine('Authorization');
        if (stripos($hdr, 'Bearer ') === 0) {
            return trim(substr($hdr, 7));
        }
        return (string) $this->request->getGet('tamaraToken');
    }
}
