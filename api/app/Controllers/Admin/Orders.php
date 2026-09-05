<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Jeebly;
use App\Libraries\JeeblyShipmentService;
use App\Models\OrderAddressModel;
use App\Models\OrderItemModel;
use App\Models\OrderModel;
use App\Models\OrderShippingEventModel;

class Orders extends BaseController
{
    private const STATUSES = ['placed', 'confirmed', 'shipped', 'delivered', 'cancelled', 'refunded'];

    public function index()
    {
        [$page, $limit, $offset] = $this->pageParams(20, 100);

        $status = $this->request->getGet('status');
        $q      = trim((string) $this->request->getGet('q'));

        $m = new OrderModel();
        $b = $m;
        if ($status && in_array($status, self::STATUSES, true)) $b = $b->where('status', $status);
        if ($q !== '') {
            $b = $b->groupStart()
                ->like('order_number', $q)
                ->orLike('customer_name', $q)
                ->orLike('customer_email', $q)
                ->orLike('customer_phone', $q)
                ->groupEnd();
        }
        $total = (clone $b)->countAllResults(false);
        $items = $b->orderBy('id', 'DESC')->limit($limit, $offset)->find();

        return $this->ok($items, [
            'page'      => $page,
            'limit'     => $limit,
            'total'     => $total,
            'last_page' => $total ? (int) ceil($total / $limit) : 1,
            'counts'    => (new OrderModel())->countsByStatus(),
        ]);
    }

    public function stats()
    {
        $m       = new OrderModel();
        $counts  = $m->countsByStatus();
        $revenue = (int) ($m->where('status !=', 'cancelled')->selectSum('grand_total_minor')->first()['grand_total_minor'] ?? 0);
        $today   = (int) ($m->where('DATE(placed_at)', date('Y-m-d'))->where('status !=', 'cancelled')->selectSum('grand_total_minor')->first()['grand_total_minor'] ?? 0);
        return $this->ok([
            'counts'           => $counts,
            'revenue_minor'    => $revenue,
            'revenue_today_minor' => $today,
        ]);
    }

    public function show(int $id)
    {
        $om = new OrderModel();
        $o  = $om->find($id);
        if (!$o) return $this->notFound('Order not found');
        $o['items']            = (new OrderItemModel())->forOrder($id);
        $o['shipping_address'] = (new OrderAddressModel())->forOrder($id)['ship'] ?? null;
        return $this->ok($o);
    }

    public function updateStatus(int $id)
    {
        $om = new OrderModel();
        $o  = $om->find($id);
        if (!$o) return $this->notFound('Order not found');

        $body = $this->jsonBody();
        $next = (string) ($body['status'] ?? '');
        if (!in_array($next, self::STATUSES, true)) {
            return $this->validationError(['status' => 'Invalid status']);
        }

        $patch = ['status' => $next];
        $now = date('Y-m-d H:i:s');
        if ($next === 'confirmed' && empty($o['confirmed_at'])) $patch['confirmed_at'] = $now;
        if ($next === 'shipped'   && empty($o['shipped_at']))   $patch['shipped_at']   = $now;
        if ($next === 'delivered') {
            if (empty($o['delivered_at'])) $patch['delivered_at'] = $now;
            $patch['payment_status'] = 'paid';     // COD considered paid on delivery
        }
        if ($next === 'cancelled') {
            $patch['cancelled_at']     = $now;
            $patch['cancelled_reason'] = (string) ($body['reason'] ?? 'Cancelled by admin');

            // If there's a live Jeebly shipment that's still cancellable, try to
            // cancel it upstream too. If Jeebly refuses (already picked up) we
            // surface the conflict so admin can decide whether to force-cancel.
            if (!empty($o['shipping_provider']) && !empty($o['shipping_reference'])) {
                $tooLate = ['Pickup Completed', 'Inscan At Hub', 'Reached At Hub', 'Out For Delivery', 'Delivered', 'RTO Delivered'];
                if (!in_array((string) ($o['shipping_status'] ?? ''), $tooLate, true)) {
                    try {
                        Jeebly::fromEnv()->cancelShipment((string) $o['shipping_reference']);
                        $patch['shipping_status'] = 'Cancelled';
                    } catch (\Throwable $e) {
                        return $this->fail(
                            'JEEBLY_CANCEL_FAILED',
                            'Local cancel blocked: Jeebly refused to cancel the shipment. ' . $e->getMessage(),
                            null, 409,
                        );
                    }
                }
            }
        }
        $om->update($id, $patch);
        return $this->ok($om->find($id));
    }

    // ============================================================
    //   JEEBLY courier integration
    // ============================================================

    /**
     * GET /api/admin/orders/{id}/jeebly/tracking — return the local event timeline
     * plus a fresh live status pulled from Jeebly's track_shipment endpoint.
     */
    public function jeeblyTracking(int $id)
    {
        $o = (new OrderModel())->find($id);
        if (!$o) return $this->notFound('Order not found');
        if (empty($o['shipping_reference'])) {
            return $this->ok([
                'has_shipment' => false,
                'shipping_error' => $o['shipping_error'] ?? null,
            ]);
        }

        $events = (new OrderShippingEventModel())->forOrder($id);

        $live = null;
        try {
            $live = Jeebly::fromEnv()->trackShipment((string) $o['shipping_reference']);
        } catch (\Throwable $e) {
            $live = ['error' => $e->getMessage()];
        }

        return $this->ok([
            'has_shipment'        => true,
            'shipping_reference'  => $o['shipping_reference'],
            'shipping_status'     => $o['shipping_status'],
            'shipping_pickup_date'=> $o['shipping_pickup_date'],
            'last_event_at'       => $o['shipping_last_event_at'],
            'events'              => $events,
            'live'                => $live,
        ]);
    }

    /** POST /api/admin/orders/{id}/jeebly/create — manual retry of shipment creation. */
    public function jeeblyCreate(int $id)
    {
        $o = (new OrderModel())->find($id);
        if (!$o) return $this->notFound('Order not found');
        if (!empty($o['shipping_reference'])) {
            return $this->fail('ALREADY_SHIPPED', 'A Jeebly shipment already exists for this order.', $o['shipping_reference'], 409);
        }
        $awb = JeeblyShipmentService::autoCreate($id);
        if (!$awb) {
            $fresh = (new OrderModel())->find($id);
            return $this->fail('JEEBLY_CREATE_FAILED', $fresh['shipping_error'] ?? 'Unknown error', null, 502);
        }
        return $this->ok((new OrderModel())->find($id));
    }

    /**
     * POST /api/admin/orders/{id}/jeebly/cancel — request cancellation upstream.
     * Jeebly only accepts cancel BEFORE Pickup Completed. On success, we patch the
     * local order immediately so the admin UI reflects reality without waiting for
     * Jeebly's webhook (which may or may not fire for admin-initiated cancels).
     */
    public function jeeblyCancel(int $id)
    {
        $om = new OrderModel();
        $o  = $om->find($id);
        if (!$o) return $this->notFound('Order not found');
        if (empty($o['shipping_reference'])) {
            return $this->fail('NO_SHIPMENT', 'This order has no Jeebly shipment to cancel.', null, 409);
        }
        // Pre-check: once the courier has the package, cancel is impossible courier-side.
        $tooLate = ['Pickup Completed', 'Inscan At Hub', 'Reached At Hub', 'Out For Delivery', 'Delivered', 'RTO Delivered'];
        if (in_array((string) $o['shipping_status'], $tooLate, true)) {
            return $this->fail(
                'TOO_LATE_TO_CANCEL',
                "Jeebly shipment is already at status '{$o['shipping_status']}' — cancellation must be done via Jeebly support.",
                null, 409,
            );
        }
        try {
            $resp = Jeebly::fromEnv()->cancelShipment((string) $o['shipping_reference']);
        } catch (\Throwable $e) {
            return $this->fail('JEEBLY_CANCEL_FAILED', $e->getMessage(), null, 502);
        }
        // Patch local state immediately so the UI doesn't wait for webhook reconciliation.
        $now = date('Y-m-d H:i:s');
        $patch = ['shipping_status' => 'Cancelled'];
        if (!in_array($o['status'] ?? '', ['cancelled', 'refunded', 'delivered'], true)) {
            $patch['status']           = 'cancelled';
            $patch['cancelled_at']     = $now;
            $patch['cancelled_reason'] = 'Cancelled via admin (Jeebly)';
        }
        $om->update($id, $patch);
        return $this->ok(['jeebly' => $resp, 'order' => $om->find($id)]);
    }

    /**
     * GET /api/admin/orders/{id}/jeebly/label — proxies the binary PDF from Jeebly
     * straight to the admin client. Responds with Content-Type: application/pdf.
     */
    public function jeeblyLabel(int $id)
    {
        $o = (new OrderModel())->find($id);
        if (!$o) return $this->notFound('Order not found');
        if (empty($o['shipping_reference'])) {
            return $this->fail('NO_SHIPMENT', 'This order has no Jeebly shipment.', null, 409);
        }
        try {
            $resp = Jeebly::fromEnv()->generateLabel((string) $o['shipping_reference']);
        } catch (\Throwable $e) {
            return $this->fail('JEEBLY_LABEL_FAILED', $e->getMessage(), null, 502);
        }
        if (($resp['__binary'] ?? false) === true) {
            return $this->response
                ->setStatusCode(200)
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'inline; filename="' . $o['shipping_reference'] . '.pdf"')
                ->setBody($resp['pdf']);
        }
        return $this->ok($resp);
    }
}
