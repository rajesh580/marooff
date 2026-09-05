<?php

namespace App\Controllers\Me;

use App\Controllers\BaseController;
use App\Models\OrderAddressModel;
use App\Models\OrderItemModel;
use App\Models\OrderModel;

class Orders extends BaseController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pageParams(20, 60);
        $m = new OrderModel();
        $b = $m->where('user_id', $this->userId())->orderBy('id', 'DESC');
        $total = (clone $b)->countAllResults(false);
        $orders = $b->limit($limit, $offset)->find();

        // Hydrate each order with its line items + shipping address so the storefront
        // can render full details without per-order GETs.
        if ($orders) {
            $ids = array_column($orders, 'id');

            $itemRows = (new OrderItemModel())
                ->whereIn('order_id', $ids)
                ->orderBy('id', 'ASC')
                ->find();
            $itemsByOrder = [];
            foreach ($itemRows as $r) $itemsByOrder[(int) $r['order_id']][] = $r;

            $addrRows = (new OrderAddressModel())
                ->whereIn('order_id', $ids)
                ->where('type', 'ship')
                ->find();
            $shipByOrder = [];
            foreach ($addrRows as $r) $shipByOrder[(int) $r['order_id']] = $r;

            foreach ($orders as &$o) {
                $oid = (int) $o['id'];
                $o['items']            = $itemsByOrder[$oid] ?? [];
                $o['shipping_address'] = $shipByOrder[$oid] ?? null;
            }
            unset($o);
        }

        return $this->ok($orders, [
            'page' => $page, 'limit' => $limit, 'total' => $total,
            'last_page' => $total ? (int) ceil($total / $limit) : 1,
        ]);
    }

    public function show(int $id)
    {
        $om = new OrderModel();
        $o  = $om->find($id);
        if (!$o || (int) $o['user_id'] !== $this->userId()) return $this->notFound('Order not found');
        $o['items']            = (new OrderItemModel())->forOrder($id);
        $o['shipping_address'] = (new OrderAddressModel())->forOrder($id)['ship'] ?? null;
        return $this->ok($o);
    }

    /**
     * After Stripe.js confirms the payment in the browser, the storefront calls this so we
     * can authoritatively verify the PaymentIntent with Stripe and update the order.
     */
    public function confirmStripe(int $id)
    {
        $om = new OrderModel();
        $o  = $om->find($id);
        if (!$o || (int) $o['user_id'] !== $this->userId()) return $this->notFound('Order not found');
        if ($o['payment_method'] !== 'stripe') return $this->fail('NOT_STRIPE', 'Order is not a Stripe payment', null, 400);
        if (!$o['payment_ref']) return $this->fail('NO_INTENT', 'No PaymentIntent associated with this order', null, 400);

        try {
            $stripe = new \App\Libraries\Stripe((string) env('stripe.secret_key', ''));
            $intent = $stripe->retrievePaymentIntent($o['payment_ref']);
        } catch (\Throwable $e) {
            return $this->fail('STRIPE_ERROR', $e->getMessage(), null, 502);
        }

        $status = $intent['status'] ?? 'unknown';
        $intentAmount = (int) ($intent['amount'] ?? 0);
        if ($intentAmount !== (int) $o['grand_total_minor']) {
            return $this->fail('AMOUNT_MISMATCH', 'PaymentIntent amount does not match order total', null, 409);
        }

        if ($status === 'succeeded') {
            $om->update($id, [
                'payment_status' => 'paid',
                'status'         => $o['status'] === 'placed' ? 'confirmed' : $o['status'],
                'confirmed_at'   => $o['confirmed_at'] ?: date('Y-m-d H:i:s'),
            ]);
        } elseif (in_array($status, ['requires_payment_method', 'canceled'], true)) {
            $om->update($id, ['payment_status' => 'failed']);
        }
        // 'processing' / 'requires_action' / 'requires_confirmation' → leave as pending

        return $this->ok([
            'order'                => $om->find($id),
            'stripe_status'        => $status,
        ]);
    }

    public function cancel(int $id)
    {
        $om = new OrderModel();
        $o  = $om->find($id);
        if (!$o || (int) $o['user_id'] !== $this->userId()) return $this->notFound('Order not found');
        if (!in_array($o['status'], ['placed', 'confirmed'], true)) {
            return $this->fail('NOT_CANCELLABLE', 'This order can no longer be cancelled.', null, 409);
        }
        $reason = trim((string) ($this->jsonBody()['reason'] ?? ''));
        $om->update($id, [
            'status'           => 'cancelled',
            'cancelled_at'     => date('Y-m-d H:i:s'),
            'cancelled_reason' => $reason ?: 'Cancelled by customer',
        ]);
        return $this->ok($om->find($id));
    }

    private function userId(): int
    {
        $hdr = $this->request->getHeaderLine('X-Auth-User');
        return (int) (json_decode($hdr, true)['sub'] ?? 0);
    }
}
