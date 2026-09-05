<?php

namespace App\Controllers\Me;

use App\Controllers\BaseController;
use App\Models\AddressModel;
use App\Models\CouponModel;
use App\Models\CustomerModel;
use App\Models\OrderAddressModel;
use App\Models\OrderItemModel;
use App\Models\OrderModel;
use App\Models\PendingCheckoutModel;
use App\Models\ComboModel;
use App\Models\ProductModel;
use App\Models\ProductVariantModel;
use App\Models\ProductVolumeDiscountModel;
use App\Libraries\OrderNotifier;
use App\Libraries\Tamara;
use App\Libraries\JeeblyShipmentService;

class Checkout extends BaseController
{
    private const VAT_PCT             = 5;
    private const COD_FEE_MINOR       = 500;   // 5.00 AED for COD

    // Shipping fee rule comes from admin-managed settings, fetched once per request.
    // Falls back to "free above AED 75, otherwise AED 10" if the rows are missing.
    private function shippingRules(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $sm = new \App\Models\SettingModel();
        $cache = [
            'free_above' => max(0, (int) ($sm->get('shipping_free_above_minor') ?? 7500)),
            'flat_fee'   => max(0, (int) ($sm->get('shipping_flat_fee_minor')   ?? 1000)),
        ];
        return $cache;
    }

    /**
     * POST /api/me/checkout/quote — compute totals for a cart without placing the order.
     */
    public function quote()
    {
        $body = $this->jsonBody();
        $resolved = $this->resolveItems($body['items'] ?? []);
        if ($resolved instanceof \CodeIgniter\HTTP\ResponseInterface) return $resolved;

        $coupon = $this->resolveCoupon($body['coupon_code'] ?? null, $resolved['items']);
        $shipMethod = $this->resolveShippingMethod($body['shipping_method'] ?? null);
        $totals = $this->computeTotals($resolved['items'], (string) ($body['payment_method'] ?? 'cod'), $coupon['discount_minor'] ?? 0);
        if ($shipMethod === 'pickup') {
            $totals['grand_total'] = max(0, $totals['grand_total'] - $totals['shipping']);
            $totals['shipping']    = 0;
        }
        return $this->ok([
            'items'           => $resolved['items'],
            'totals'          => $totals,
            'coupon'          => $coupon,
            'shipping_method' => $shipMethod,
        ]);
    }

    /**
     * Look up a coupon by code, validate against the COUPON-ELIGIBLE cart subtotal,
     * return null or its info.
     *
     * Combo lines (those with combo_id > 0) are deliberately excluded — combos are
     * already priced as a bundle deal, so no coupons stack on top. If a cart has
     * ONLY combos, the coupon silently fails to apply and the customer sees no
     * discount (the storefront highlights this so it isn't confusing).
     */
    private function resolveCoupon(?string $code, array $items): ?array
    {
        if (!$code) return null;
        $cm = new CouponModel();
        $row = $cm->findByCode((string) $code);
        if (!$row) return null;

        // Subtotal that the coupon is actually allowed to discount — combos excluded.
        $eligibleSub = 0;
        $comboSub    = 0;
        foreach ($items as $i) {
            if (!empty($i['combo_id'])) $comboSub    += (int) $i['line_total_minor'];
            else                         $eligibleSub += (int) $i['line_total_minor'];
        }

        // Coupon needs at least one non-combo item to apply to.
        if ($eligibleSub <= 0) return null;

        $r = $cm->validateForSubtotal($row, $eligibleSub);
        if (!$r['ok']) return null;

        $discount = min((int) $r['discount_minor'], $eligibleSub);

        return [
            'id'                => (int) $row['id'],
            'code'              => $row['code'],
            'type'              => $row['type'],
            'value_minor'       => (int) $row['value_minor'],
            'discount_minor'    => $discount,
            'eligible_subtotal' => $eligibleSub,
            'excluded_subtotal' => $comboSub,
            'excludes_combos'   => true,
        ];
    }

    /**
     * POST /api/me/checkout/place — COD ONLY now. Card orders go through prepare/finalize.
     * Creates the order, snapshots line items + shipping address, returns the new order.
     */
    public function place()
    {
        $body = $this->jsonBody();

        // 1) Payment method — only COD is allowed on this endpoint now.
        $payment = (string) ($body['payment_method'] ?? 'cod');
        if ($payment === 'stripe') {
            return $this->fail('WRONG_ENDPOINT', 'Card payments use /checkout/prepare-stripe and /checkout/finalize-stripe.', null, 400);
        }
        if ($payment !== 'cod') {
            return $this->validationError(['payment_method' => 'Unsupported payment method']);
        }

        $built = $this->buildOrderContext($body);
        if ($built instanceof \CodeIgniter\HTTP\ResponseInterface) return $built;

        $order = $this->materialiseOrder($built, [
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'payment_ref'    => null,
            'status'         => 'placed',
            'confirmed_at'   => null,
        ]);

        return $this->created($order);
    }

    /**
     * POST /api/me/checkout/prepare-stripe
     * Validates cart + address + totals, creates a Stripe PaymentIntent, and stores a
     * `pending_checkouts` row holding the full payload that will become the order
     * AFTER the payment succeeds. **No order is inserted at this stage.**
     *
     * Returns: { payment_intent_id, client_secret, publishable_key, grand_total_minor, currency, totals }
     */
    public function prepareStripe()
    {
        $body = $this->jsonBody();
        $built = $this->buildOrderContext($body);
        if ($built instanceof \CodeIgniter\HTTP\ResponseInterface) return $built;

        $userId   = $this->userId();
        $customer = (new CustomerModel())->find($userId);
        $now      = date('Y-m-d H:i:s');

        // Compute totals WITH the coupon discount and honour the pickup rule that
        // /place() and /prepare-tamara already use. Without these two adjustments,
        // Stripe would charge the full (undiscounted) grand_total and every
        // pickup order would still be billed the shipping fee.
        $totals = $this->computeTotals(
            $built['items'],
            'stripe',
            $built['coupon']['discount_minor'] ?? 0,
        );
        if (($built['shipping_method'] ?? 'home_delivery') === 'pickup' && $totals['shipping'] > 0) {
            $totals['grand_total'] = max(0, $totals['grand_total'] - $totals['shipping']);
            $totals['shipping']    = 0;
        }

        try {
            $stripe = new \App\Libraries\Stripe((string) env('stripe.secret_key', ''));
            $intent = $stripe->createPaymentIntent(
                (int) $totals['grand_total'],
                'aed',
                [
                    'description'   => 'Maroof checkout (user #' . $userId . ')',
                    'receipt_email' => ($customer['email'] ?? '') ?: null,
                    'metadata'      => [
                        'customer_id' => (string) $userId,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            return $this->fail('STRIPE_ERROR', $e->getMessage(), null, 502);
        }

        // Persist coupon + shipping_method too so the settled order records
        // coupon usage, marks the coupon consumed (max_uses), and knows whether
        // it was pickup vs delivery. Without this the Stripe order loses all
        // three signals when it materialises.
        $payload = [
            'items'           => $built['items'],
            'totals'          => $totals,
            'address'         => $built['address'],
            'notes'           => $built['notes'],
            'shipping_method' => $built['shipping_method'] ?? 'home_delivery',
            'coupon'          => $built['coupon'],
        ];

        (new PendingCheckoutModel())->insert([
            'payment_intent_id' => $intent['id'],
            'user_id'           => $userId,
            'amount_minor'      => (int) $totals['grand_total'],
            'currency'          => 'AED',
            'payload_json'      => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status'            => 'pending',
            'created_at'        => $now,
        ]);

        return $this->ok([
            'payment_intent_id'      => $intent['id'],
            'client_secret'          => $intent['client_secret'] ?? null,
            'publishable_key'        => env('stripe.publishable_key'),
            'grand_total_minor'      => (int) $totals['grand_total'],
            'currency'               => 'AED',
            'totals'                 => $totals,
        ]);
    }

    /**
     * POST /api/me/checkout/finalize-stripe
     * Called by the storefront after Stripe.js confirms the payment. We verify
     * with Stripe that the PaymentIntent really did succeed, then materialise
     * the order from the pending_checkouts row.
     *
     * Idempotent: if the order already exists (e.g. the webhook beat us to it),
     * we return that order rather than failing.
     *
     * Body: { payment_intent_id: "pi_xxx" }
     */
    public function finalizeStripe()
    {
        $body = $this->jsonBody();
        $piId = (string) ($body['payment_intent_id'] ?? '');
        if (!$piId) return $this->validationError(['payment_intent_id' => 'Required']);

        $result = $this->settleStripeIntent($piId, $this->userId());
        if (is_array($result) && isset($result['error'])) {
            return $this->fail($result['error']['code'], $result['error']['message'], null, $result['error']['status'] ?? 400);
        }
        return $this->ok($result);
    }

    /**
     * Shared worker used by /finalize-stripe AND by the Stripe webhook. Verifies a
     * PaymentIntent and creates the order from the pending_checkouts row. Returns the
     * order array on success or an ['error' => ['code','message','status']] shape.
     *
     * The webhook calls this without an authenticated user — `$expectedUserId=null`
     * means we trust the customer_id from the PaymentIntent's own metadata.
     */
    public function settleStripeIntent(string $piId, ?int $expectedUserId): array
    {
        $om = new OrderModel();

        // Idempotency: if an order already exists with this PI ref, return it as-is.
        $existing = $om->where('payment_ref', $piId)->first();
        if ($existing) {
            return $this->hydrate($existing);
        }

        $pcm = new PendingCheckoutModel();
        $pending = $pcm->findByIntent($piId);
        if (!$pending) {
            return ['error' => ['code' => 'PI_UNKNOWN', 'message' => 'No pending checkout matches this payment.', 'status' => 404]];
        }

        if ($expectedUserId !== null && (int) $pending['user_id'] !== $expectedUserId) {
            return ['error' => ['code' => 'PI_NOT_YOURS', 'message' => 'This payment does not belong to your account.', 'status' => 403]];
        }

        // Authoritative status check against Stripe
        try {
            $stripe = new \App\Libraries\Stripe((string) env('stripe.secret_key', ''));
            $intent = $stripe->retrievePaymentIntent($piId);
        } catch (\Throwable $e) {
            return ['error' => ['code' => 'STRIPE_ERROR', 'message' => $e->getMessage(), 'status' => 502]];
        }
        if (($intent['status'] ?? '') !== 'succeeded') {
            return ['error' => ['code' => 'PI_NOT_SUCCEEDED', 'message' => 'Payment is not in succeeded state (' . ($intent['status'] ?? 'unknown') . ').', 'status' => 409]];
        }
        if ((int) ($intent['amount'] ?? 0) !== (int) $pending['amount_minor']) {
            return ['error' => ['code' => 'AMOUNT_MISMATCH', 'message' => 'PaymentIntent amount differs from prepared total.', 'status' => 409]];
        }

        $payload  = json_decode($pending['payload_json'], true) ?: [];
        $customer = (new CustomerModel())->find((int) $pending['user_id']);

        $order = $this->materialiseOrder([
            'user_id'         => (int) $pending['user_id'],
            'items'           => $payload['items']   ?? [],
            'address'         => $payload['address'] ?? [],
            'notes'           => $payload['notes']   ?? null,
            'customer'        => $customer ?: [],
            // Restore the coupon + shipping method so the order row gets
            // coupon_id / coupon_code, coupon_uses gets a new row, and the
            // coupon's uses_count is incremented — matching COD and Tamara.
            'coupon'          => $payload['coupon']  ?? null,
            'shipping_method' => $payload['shipping_method'] ?? 'home_delivery',
        ], [
            'payment_method' => 'stripe',
            'payment_status' => 'paid',
            'payment_ref'    => $piId,
            'status'         => 'confirmed',
            'confirmed_at'   => date('Y-m-d H:i:s'),
        ], $payload['totals'] ?? null);

        // Mark the pending row consumed (kept for audit; cleanup job can purge later).
        $pcm->update($pending['id'], [
            'status'      => 'consumed',
            'consumed_at' => date('Y-m-d H:i:s'),
        ]);

        return $order;
    }

    // ============================================================
    //   TAMARA  (BNPL — pay-in-3 etc.)
    //   Flow mirrors Stripe's prepare → redirect → finalize/webhook
    //   model. Important differences from Stripe:
    //     - Amounts are DECIMAL major units, not minor.
    //     - The customer is redirected to checkout_url (no inline form).
    //     - On order_approved webhook we call /orders/{id}/authorise.
    //     - Capture is at SHIPMENT (not at order placement), so the order
    //       is materialised at "approved/authorised" status with
    //       payment_status='pending_capture'.
    // ============================================================

    /**
     * POST /api/me/checkout/prepare-tamara
     * Creates a Tamara checkout session, stores cart in pending_checkouts keyed by
     * tamara_order_id, returns the redirect URL.
     *
     * Body: same shape as /place — { items, coupon_code, shipping_method, notes }
     */
    public function prepareTamara()
    {
        $body  = $this->jsonBody();
        $built = $this->buildOrderContext($body);
        if ($built instanceof \CodeIgniter\HTTP\ResponseInterface) return $built;

        $userId   = $this->userId();
        $customer = $built['customer'];
        $totals   = $this->computeTotals(
            $built['items'],
            'tamara',
            $built['coupon']['discount_minor'] ?? 0,
        );

        // Pickup zeroes shipping (same rule as materialiseOrder).
        if (($built['shipping_method'] ?? 'home_delivery') === 'pickup' && $totals['shipping'] > 0) {
            $totals['grand_total'] = max(0, $totals['grand_total'] - $totals['shipping']);
            $totals['shipping']    = 0;
        }

        // Build the Tamara /checkout payload.
        $orderRef = 'MAROOFF-' . $userId . '-' . substr(bin2hex(random_bytes(6)), 0, 10);
        $payload  = $this->buildTamaraCheckoutPayload(
            $built,
            $totals,
            $customer,
            $orderRef,
        );
        // Validation errors (e.g. incomplete name) are returned as a Response.
        if ($payload instanceof \CodeIgniter\HTTP\ResponseInterface) return $payload;

        try {
            $tamara = Tamara::fromEnv();
            $resp   = $tamara->createCheckout($payload);
        } catch (\Throwable $e) {
            return $this->fail('TAMARA_ERROR', $e->getMessage(), null, 502);
        }

        $tamaraOrderId = (string) ($resp['order_id'] ?? '');
        $checkoutUrl   = (string) ($resp['checkout_url'] ?? '');
        if ($tamaraOrderId === '' || $checkoutUrl === '') {
            return $this->fail('TAMARA_BAD_RESPONSE', 'Tamara did not return a checkout URL.', $resp, 502);
        }

        // Store the full context so finalizeTamara / the webhook can materialise the
        // order without having to re-resolve cart state.
        $pendingPayload = [
            'items'           => $built['items'],
            'totals'          => $totals,
            'address'         => $built['address'],
            'notes'           => $built['notes'],
            'shipping_method' => $built['shipping_method'] ?? 'home_delivery',
            'coupon'          => $built['coupon'],
            'tamara' => [
                'order_id'           => $tamaraOrderId,
                'checkout_id'        => $resp['checkout_id'] ?? null,
                'order_reference_id' => $orderRef,
            ],
        ];

        (new PendingCheckoutModel())->insert([
            'payment_intent_id' => $tamaraOrderId,         // reused column — Tamara UUID
            'user_id'           => $userId,
            'amount_minor'      => (int) $totals['grand_total'],
            'currency'          => 'AED',
            'payload_json'      => json_encode($pendingPayload, JSON_UNESCAPED_UNICODE),
            'status'            => 'pending',
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        return $this->ok([
            'checkout_url'       => $checkoutUrl,
            'tamara_order_id'    => $tamaraOrderId,
            'order_reference_id' => $orderRef,
            'grand_total_minor'  => (int) $totals['grand_total'],
            'currency'           => 'AED',
            'totals'             => $totals,
        ]);
    }

    /**
     * POST /api/me/checkout/finalize-tamara
     * Storefront calls this when Tamara redirects the customer back to /checkout/tamara/success.
     * Verifies the Tamara order status server-side, then materialises the local order.
     * Idempotent: if the webhook beat us to it, returns the existing order.
     *
     * Body: { tamara_order_id: "uuid" }
     */
    public function finalizeTamara()
    {
        $body = $this->jsonBody();
        $tamaraOrderId = (string) ($body['tamara_order_id'] ?? '');
        if (!$tamaraOrderId) return $this->validationError(['tamara_order_id' => 'Required']);

        $result = $this->settleTamaraOrder($tamaraOrderId, $this->userId());
        if (is_array($result) && isset($result['error'])) {
            return $this->fail($result['error']['code'], $result['error']['message'], null, $result['error']['status'] ?? 400);
        }
        return $this->ok($result);
    }

    /**
     * Shared worker for /finalize-tamara AND the Tamara webhook. Verifies the
     * Tamara order status and materialises the local order from the pending row.
     *
     * The webhook passes $expectedUserId=null (server-to-server, no JWT).
     *
     * Tamara order statuses we accept as "go ahead and create the order":
     *   approved, authorised, fully_captured, partially_captured
     *
     * Statuses we treat as still-pending (no order yet, no error):
     *   new (customer hasn't completed checkout yet)
     *
     * Statuses we treat as terminal failure:
     *   declined, expired, canceled
     */
    public function settleTamaraOrder(string $tamaraOrderId, ?int $expectedUserId): array
    {
        $om = new OrderModel();

        // Idempotency — webhook may have beaten us to it.
        $existing = $om->where('payment_ref', $tamaraOrderId)->first();
        if ($existing) return $this->hydrate($existing);

        $pcm     = new PendingCheckoutModel();
        $pending = $pcm->findByIntent($tamaraOrderId);
        if (!$pending) {
            return ['error' => ['code' => 'TAMARA_UNKNOWN', 'message' => 'No pending checkout matches this Tamara order.', 'status' => 404]];
        }
        if ($expectedUserId !== null && (int) $pending['user_id'] !== $expectedUserId) {
            return ['error' => ['code' => 'TAMARA_NOT_YOURS', 'message' => 'This Tamara order does not belong to your account.', 'status' => 403]];
        }

        // Authoritative status check.
        try {
            $tamara = Tamara::fromEnv();
            $order  = $tamara->getOrder($tamaraOrderId);
        } catch (\Throwable $e) {
            return ['error' => ['code' => 'TAMARA_ERROR', 'message' => $e->getMessage(), 'status' => 502]];
        }
        $status = strtolower((string) ($order['status'] ?? ''));

        $okStatuses     = ['approved', 'authorised', 'authorized', 'fully_captured', 'partially_captured'];
        $stillPending   = ['new'];
        $failedStatuses = ['declined', 'expired', 'canceled', 'cancelled'];

        if (in_array($status, $stillPending, true)) {
            return ['error' => ['code' => 'TAMARA_PENDING', 'message' => 'Customer has not completed the Tamara checkout yet.', 'status' => 409]];
        }
        if (in_array($status, $failedStatuses, true)) {
            // Mark pending as expired so cleanup can purge it.
            $pcm->update($pending['id'], ['status' => 'expired']);
            return ['error' => ['code' => 'TAMARA_FAILED', 'message' => 'Tamara order is ' . $status . '.', 'status' => 409]];
        }
        if (!in_array($status, $okStatuses, true)) {
            return ['error' => ['code' => 'TAMARA_UNEXPECTED_STATUS', 'message' => 'Tamara order is in unexpected status: ' . $status, 'status' => 502]];
        }

        // Sanity-check amount + currency match our prepared total. Accept a 1-fils
        // tolerance to absorb any 0.01 float-rounding drift from Tamara's side.
        $tamaraCurrency = strtoupper((string) ($order['total_amount']['currency'] ?? ''));
        if ($tamaraCurrency !== 'AED') {
            return ['error' => ['code' => 'CURRENCY_MISMATCH', 'message' => 'Tamara order currency is not AED.', 'status' => 409]];
        }
        $tamaraTotalMajor = (float) ($order['total_amount']['amount'] ?? 0);
        $tamaraTotalMinor = (int) round($tamaraTotalMajor * 100);
        if (abs($tamaraTotalMinor - (int) $pending['amount_minor']) > 1) {
            return ['error' => ['code' => 'AMOUNT_MISMATCH', 'message' => 'Tamara order amount differs from prepared total.', 'status' => 409]];
        }

        $payload  = json_decode($pending['payload_json'], true) ?: [];
        $customer = (new CustomerModel())->find((int) $pending['user_id']);

        $localOrder = $this->materialiseOrder([
            'user_id'         => (int) $pending['user_id'],
            'items'           => $payload['items']   ?? [],
            'address'         => $payload['address'] ?? [],
            'notes'           => $payload['notes']   ?? null,
            'customer'        => $customer ?: [],
            'coupon'          => $payload['coupon']  ?? null,
            'shipping_method' => $payload['shipping_method'] ?? 'home_delivery',
        ], [
            'payment_method' => 'tamara',
            // Tamara is BNPL — order is "authorised" with Tamara but funds aren't
            // captured until shipment. The DB enum doesn't have 'authorised', so we
            // use 'pending' and flip to 'paid' on the order_captured webhook.
            'payment_status' => 'pending',
            'payment_ref'    => $tamaraOrderId,
            'status'         => 'confirmed',
            'confirmed_at'   => date('Y-m-d H:i:s'),
        ], $payload['totals'] ?? null);

        $pcm->update($pending['id'], [
            'status'      => 'consumed',
            'consumed_at' => date('Y-m-d H:i:s'),
        ]);

        return $localOrder;
    }

    /**
     * Build the JSON payload for Tamara /checkout. Maps our internal cart + saved-address
     * shape into Tamara's required structure. Money goes in as decimal AED.
     *
     * Returns either the payload array OR a Response (validation error) when the customer's
     * saved address has data Tamara won't accept (e.g. missing surname, since Tamara rejects
     * single-char name fields with "must be at least 2 characters").
     */
    private function buildTamaraCheckoutPayload(array $built, array $totals, array $customer, string $orderRef)
    {
        $addr = $built['address'];

        // First / last name split. Tamara rejects names <2 chars and non-alphabetic
        // placeholders like '.', so we resolve via address → customer record and refuse
        // to send a payload Tamara would reject.
        $nameParts = preg_split('/\s+/', trim((string) ($addr['name'] ?? '')), 2);
        $firstName = trim((string) ($nameParts[0] ?? ''));
        $lastName  = trim((string) ($nameParts[1] ?? ''));
        if ($firstName === '') $firstName = trim((string) ($customer['name'] ?? ''));
        if ($lastName  === '') {
            // Use the part after the first space in customer name if available, else duplicate
            // the first name — Tamara accepts identical first/last but not a 1-char placeholder.
            $cust = preg_split('/\s+/', trim((string) ($customer['name'] ?? '')), 2);
            $lastName = trim((string) ($cust[1] ?? ($firstName)));
        }
        if (strlen($firstName) < 2 || strlen($lastName) < 2) {
            return $this->fail(
                'NAME_INCOMPLETE',
                'Please add your full name (first and last) on your saved address before paying with Tamara.',
                null, 422,
            );
        }

        // Phone — Tamara wants strict E.164 UAE format. Validate explicitly so the
        // customer gets a clear message instead of a cryptic Tamara OTP failure.
        $phone = $this->normalisePhoneE164($addr['phone'] ?? ($customer['phone'] ?? ''));
        if ($phone === '') {
            return $this->fail(
                'INVALID_PHONE',
                'Please update your saved address with a valid UAE mobile number (e.g. +971501234567) before paying with Tamara.',
                null, 422,
            );
        }

        // Concatenate into a single address line. Use !== '' / !== null checks because
        // '0' is a valid building/floor number (ground floor) but PHP treats string '0' as falsy.
        $hasVal = fn ($v) => $v !== null && $v !== '' && $v !== false;
        $line1 = trim(implode(', ', array_filter([
            $hasVal($addr['apartment'] ?? null) ? $addr['apartment'] : null,
            $hasVal($addr['floor']     ?? null) ? ('Floor ' . $addr['floor']) : null,
            $hasVal($addr['building']  ?? null) ? $addr['building'] : null,
            $hasVal($addr['street']    ?? null) ? $addr['street']   : null,
            $hasVal($addr['area']      ?? null) ? $addr['area']     : null,
        ])));
        if ($line1 === '') $line1 = $addr['area'] ?? 'Address';

        $addressBlock = [
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'line1'        => $line1,
            'city'         => $addr['emirate'] ?? 'Dubai',
            'country_code' => 'AE',
            'phone_number' => $phone,
        ];
        if (!empty($addr['landmark'])) $addressBlock['line2'] = $addr['landmark'];

        // Items — Tamara wants per-line money in decimal. Tamara validates
        //   items[].total_amount == items[].unit_price * quantity - discount_amount
        // Our line_total_minor already accounts for tier/volume discounts, so we
        // back-out the per-item discount as (unit_price_minor * qty - line_total_minor)
        // and report it explicitly — keeping the original unit_price on receipts.
        $items = [];
        foreach ($built['items'] as $it) {
            $unitMinor  = (int) ($it['unit_price_minor'] ?? 0);
            $qty        = (int) ($it['qty']              ?? 0);
            $lineMinor  = (int) ($it['line_total_minor'] ?? ($unitMinor * $qty));
            $tierDisc   = max(0, $unitMinor * $qty - $lineMinor);
            $sku        = trim((string) ($it['sku'] ?? ''));
            $ref        = $sku !== '' ? $sku : ('ITEM-' . ($it['product_id'] ?? '0'));
            $items[] = [
                'reference_id'    => $ref,
                'type'            => 'Cosmetics',
                'name'            => (string) ($it['name'] ?? 'Item'),
                'sku'             => $sku !== '' ? $sku : $ref,                 // keep reference_id == sku when no SKU set
                'quantity'        => $qty,
                'unit_price'      => Tamara::money($unitMinor),                  // tax-inclusive list price
                'total_amount'    => Tamara::money($lineMinor),                  // = unit_price × qty - discount_amount
                'tax_amount'      => Tamara::money(0),                           // VAT already in unit_price (UAE inclusive)
                'discount_amount' => Tamara::money($tierDisc),                   // tier/volume discount (0 if none)
            ];
        }

        // Compute return URLs from the storefront base in env. Falls back to marooffc.com.
        $storefrontBase = rtrim((string) env('storefront.base_url', 'https://marooffc.com'), '/');
        $apiBase        = rtrim((string) env('app.baseURL', 'https://api.marooffc.com/'), '/');

        // IMPORTANT: our line_total_minor INCLUDES VAT (UAE inclusive-VAT pricing).
        // Tamara validates  total_amount == sum(items.total_amount) + shipping + tax - discount,
        // so to keep that equation true with VAT-inclusive items we send tax_amount = 0
        // and keep the per-item totals as the tax-inclusive line totals.
        $payload = [
            'order_reference_id' => $orderRef,
            'total_amount'       => Tamara::money((int) $totals['grand_total']),
            'shipping_amount'    => Tamara::money((int) $totals['shipping']),
            'tax_amount'         => Tamara::money(0),
            'description'        => 'Marooff cosmetics order',
            'country_code'       => 'AE',
            'locale'             => 'en_US',
            'items'              => $items,
            'consumer' => [
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'phone_number' => $phone,
                'email'        => (string) ($customer['email'] ?? ''),
            ],
            'shipping_address' => $addressBlock,
            'billing_address'  => $addressBlock,
            'merchant_url' => [
                'success'      => $storefrontBase . '/checkout/tamara/success/',
                'failure'      => $storefrontBase . '/checkout/tamara/failure/',
                'cancel'       => $storefrontBase . '/checkout/tamara/cancel/',
                'notification' => $apiBase . '/api/webhooks/tamara',
            ],
            'platform'  => 'Other',
            'is_mobile' => false,
        ];
        // Only include `discount` when we actually have one — Tamara rejects an
        // explicit JSON null on this optional-object field.
        if ((int) $totals['discount'] > 0) {
            $payload['discount'] = [
                'name'   => (string) ($built['coupon']['code'] ?? 'Discount'),
                'amount' => Tamara::money((int) $totals['discount']),
            ];
        }
        return $payload;
    }

    /**
     * Normalise a UAE phone number to strict E.164: "+971" + 9 digits.
     * Accepts inputs in any common shape:
     *   "+971 50 123 4567" / "00971501234567" / "971501234567" / "0501234567" / "501234567"
     * Returns "" if the result wouldn't be a valid UAE number (so callers
     * can surface a user-friendly validation error to the customer).
     */
    private function normalisePhoneE164(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === null || $digits === '') return '';

        // Drop a leading "00" international prefix if present (e.g. "00971...").
        if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
        // Strip the country code or trunk "0" so we end up with the local number.
        if (str_starts_with($digits, '971')) $digits = substr($digits, 3);
        elseif (str_starts_with($digits, '0')) $digits = substr($digits, 1);

        // UAE mobile + landline local numbers are exactly 9 digits.
        if (strlen($digits) !== 9) return '';
        return '+971' . $digits;
    }

    // ============================================================
    //   internals
    // ============================================================

    /**
     * Resolve cart + saved-address + customer into the snapshot data needed to materialise
     * (or pend) an order. Returns either an associative array OR an error Response.
     */
    private function buildOrderContext(array $body)
    {
        $resolved = $this->resolveItems($body['items'] ?? []);
        if ($resolved instanceof \CodeIgniter\HTTP\ResponseInterface) return $resolved;
        if (!$resolved['items']) return $this->validationError(['items' => 'Cart is empty']);

        $userId = $this->userId();
        $pair = (new AddressModel())->pairForUser($userId);
        if (!$pair['shipping']) {
            return $this->fail('ADDRESS_REQUIRED', 'Please add your address before placing an order.', null, 422);
        }
        $addr = $this->normaliseSavedAddress($pair['shipping']);
        if (!$addr) return $this->validationError(['address' => 'Saved address is incomplete']);

        return [
            'user_id'         => $userId,
            'items'           => $resolved['items'],
            'address'         => $addr,
            'notes'           => trim((string) ($body['notes'] ?? '')) ?: null,
            'customer'        => (new CustomerModel())->find($userId) ?: [],
            'coupon'          => $this->resolveCoupon($body['coupon_code'] ?? null, $resolved['items']),
            'shipping_method' => $this->resolveShippingMethod($body['shipping_method'] ?? null),
        ];
    }

    /** Accept "home_delivery" or "pickup" as the shipping method. Defaults to home_delivery. */
    private function resolveShippingMethod($value): string
    {
        $v = strtolower((string) ($value ?: 'home_delivery'));
        return in_array($v, ['home_delivery', 'pickup'], true) ? $v : 'home_delivery';
    }

    /**
     * Insert order + items + shipping address into the DB. Uses a UNIQUE constraint on
     * orders.payment_ref to guarantee at-most-once order creation per PaymentIntent.
     */
    private function materialiseOrder(array $ctx, array $payment, ?array $totals = null): array
    {
        $coupon = $ctx['coupon'] ?? null;
        $shipMethod = $ctx['shipping_method'] ?? 'home_delivery';
        if ($totals === null) {
            $totals = $this->computeTotals($ctx['items'], $payment['payment_method'], $coupon['discount_minor'] ?? 0);
            if ($shipMethod === 'pickup') {
                // Pickup → no shipping fee + grand_total adjusts
                $totals['grand_total'] = max(0, $totals['grand_total'] - $totals['shipping']);
                $totals['shipping']    = 0;
            }
        }

        $userId   = (int) $ctx['user_id'];
        $addr     = $ctx['address'];
        $customer = $ctx['customer'] ?? [];
        $now      = date('Y-m-d H:i:s');

        $db = \Config\Database::connect();
        $db->transStart();

        $orderModel = new OrderModel();
        $orderRow   = [
            'order_number'      => OrderModel::generateOrderNumber(),
            'user_id'           => $userId,
            'status'            => $payment['status'],
            'subtotal_minor'    => (int) $totals['subtotal'],
            'discount_minor'    => (int) $totals['discount'],
            'vat_minor'         => (int) $totals['vat'],
            'shipping_fee_minor'=> (int) $totals['shipping'],
            'cod_fee_minor'     => (int) ($totals['cod_fee'] ?? 0),
            'grand_total_minor' => (int) $totals['grand_total'],
            'currency'          => 'AED',
            'payment_method'    => $payment['payment_method'],
            'payment_status'    => $payment['payment_status'],
            'payment_ref'       => $payment['payment_ref'],
            'customer_name'     => $addr['name'] ?: ($customer['name'] ?? ''),
            'customer_email'    => $customer['email'] ?? '',
            'customer_phone'    => $addr['phone'] ?: ($customer['phone'] ?? ''),
            'notes'             => $ctx['notes'] ?? null,
            'placed_at'         => $now,
            'confirmed_at'      => $payment['confirmed_at'] ?? null,
            'coupon_id'         => $coupon ? (int) $coupon['id']   : null,
            'coupon_code'       => $coupon ? $coupon['code']        : null,
            'shipping_method'   => $shipMethod,
        ];
        // Race-safe insert: the UNIQUE constraint on payment_ref is the source of truth.
        // If a concurrent caller (e.g. webhook firing while /finalize-tamara is in flight)
        // beats us to it, MySQL rejects our INSERT — we detect this either via thrown
        // DatabaseException (DBDebug=true) OR by insert() returning false (DBDebug=false).
        //
        // We DO NOT call transRollback() manually here because we're inside transStart()'s
        // strict block — manual rollback corrupts the depth counter. Instead we flag the
        // duplicate, let transStart()'s auto-rollback fire on transComplete() (the failed
        // insert flips its internal flag), and look up the winner AFTER the transaction
        // is cleaned up at the end of this method.
        $insertOk = false;
        $insertErr = null;
        try {
            $insertOk = (bool) $orderModel->insert($orderRow);
        } catch (\Throwable $e) {
            $insertErr = $e;
        }
        if (!$insertOk) {
            $ref = $orderRow['payment_ref'] ?? null;
            // Defer transaction cleanup to transComplete() at the bottom of the method.
            // Capture state and short-circuit AFTER the transaction has been finalised.
            $db->transComplete(); // strict-mode rolls back due to the failed insert
            if (is_string($ref) && $ref !== '' && ($winner = $orderModel->where('payment_ref', $ref)->first())) {
                return $this->hydrate($winner);
            }
            if ($insertErr) throw $insertErr;
            throw new \RuntimeException('Order insert failed: ' . json_encode($orderModel->errors() ?: $db->error()));
        }
        $orderId = (int) $orderModel->getInsertID();

        // Record coupon usage (so max_uses works)
        if ($coupon) {
            $db->table('coupons')->where('id', (int) $coupon['id'])->set('uses_count', 'uses_count + 1', false)->update();
            $db->table('coupon_uses')->insert([
                'coupon_id'      => (int) $coupon['id'],
                'user_id'        => $userId,
                'order_id'       => $orderId,
                'discount_minor' => (int) $totals['discount'],
                'created_at'     => $now,
            ]);
        }

        $itemModel = new OrderItemModel();
        $rows = [];
        foreach ($ctx['items'] as $it) {
            $rows[] = [
                'order_id'         => $orderId,
                'product_id'       => $it['product_id'],
                'variant_id'       => $it['variant_id'],
                'name_snapshot'    => $it['name'],
                'sku_snapshot'     => $it['sku'],
                'shade_snapshot'   => $it['shade'],
                'image_snapshot'   => $it['image'],
                'qty'              => $it['qty'],
                'unit_price_minor' => $it['unit_price_minor'],
                'line_total_minor' => $it['line_total_minor'],
                'created_at'       => $now,
            ];
        }
        if ($rows) $itemModel->insertBatch($rows);

        (new OrderAddressModel())->insert([
            'order_id'  => $orderId,
            'type'      => 'ship',
            'name'      => $addr['name'],
            'phone'     => $addr['phone'],
            'emirate'   => $addr['emirate'],
            'area'      => $addr['area'],
            'street'    => $addr['street'],
            'building'  => $addr['building'],
            'floor'     => $addr['floor'],
            'apartment' => $addr['apartment'],
            'landmark'  => $addr['landmark'],
            'makani'    => $addr['makani'],
            'created_at'=> $now,
        ]);

        $db->transComplete();

        $hydrated = $this->hydrate($orderModel->find($orderId));

        // Fire-and-forget notifications (email + whatsapp). Wrapped in try/catch
        // inside the notifier — a failure here MUST NOT roll back the order.
        OrderNotifier::newOrder($hydrated);

        // Fire-and-forget Jeebly shipment creation (writes shipping_reference on
        // success, shipping_error on failure — see JeeblyShipmentService).
        JeeblyShipmentService::autoCreate($orderId);

        // Re-hydrate so the response includes shipping_reference/shipping_status.
        return $this->hydrate($orderModel->find($orderId));
    }

    private function hydrate(array $order): array
    {
        $orderId = (int) $order['id'];
        $order['items']            = (new OrderItemModel())->forOrder($orderId);
        $order['shipping_address'] = (new OrderAddressModel())->forOrder($orderId)['ship'] ?? null;
        return $order;
    }

    /**
     * Look up each requested item against the DB, validating product/variant, fetching the
     * authoritative server-side price, and producing rich snapshot fields.
     */
    private function resolveItems(array $rawItems)
    {
        if (!is_array($rawItems) || !count($rawItems)) {
            return $this->validationError(['items' => 'Cart cannot be empty']);
        }
        $pm  = new ProductModel();
        $vm  = new ProductVariantModel();
        $vdm = new ProductVolumeDiscountModel();
        $cm  = new ComboModel();
        $tierCache = []; // product_id => tiers
        $out = [];
        foreach ($rawItems as $r) {
            // ----- Combo lines -----
            $cidRaw = $r['combo_id'] ?? null;
            if ($cidRaw !== null && (int) $cidRaw > 0) {
                $cid = (int) $cidRaw;
                $qty = max(1, (int) ($r['qty'] ?? 1));
                $combo = $cm->find($cid);
                if (!$combo || (int) $combo['is_active'] !== 1) {
                    return $this->validationError(['items' => "Combo #{$cid} is not available"]);
                }
                if ((int) $combo['stock'] > 0 && $qty > (int) $combo['stock']) {
                    return $this->validationError(['items' =>
                        "Only {$combo['stock']} left of '{$combo['name']}' combo. Reduce quantity."]);
                }
                $unit = (int) (($combo['sale_price_minor'] ?: $combo['price_minor']));
                $out[] = [
                    'product_id'           => null,
                    'variant_id'           => null,
                    'combo_id'             => $cid,
                    'name'                 => 'Combo: ' . $combo['name'],
                    'sku'                  => null,
                    'shade'                => null,
                    'image'                => $combo['image_url'],
                    'qty'                  => $qty,
                    'unit_price_minor'     => $unit,
                    'line_total_minor'     => $unit * $qty,
                    'volume_discount_minor'=> 0,
                    'tier_min_qty'         => null,
                ];
                continue;
            }

            $pid = (int) ($r['product_id'] ?? 0);
            $vid = isset($r['variant_id']) && $r['variant_id'] !== null ? (int) $r['variant_id'] : null;
            $qty = max(1, (int) ($r['qty'] ?? 1));
            if (!$pid) continue;
            $p = $pm->find($pid);
            if (!$p || (int) $p['is_active'] !== 1) {
                return $this->validationError(['items' => "Product #{$pid} is not available"]);
            }

            $v = null;
            $unit = (int) $p['price_minor'];
            $name = (string) $p['name'];
            $sku  = (string) ($p['sku'] ?? '');
            $shade = null;
            $image = $p['main_image_url'];
            if ($vid) {
                $v = $vm->find($vid);
                if (!$v || (int) $v['product_id'] !== $pid) {
                    return $this->validationError(['items' => "Variant #{$vid} is not valid for product #{$pid}"]);
                }
                if (!$v['is_available']) {
                    return $this->validationError(['items' => "Selected shade is out of stock for {$p['name']}"]);
                }
                // Per-shade stock is the authority when > 0. When 0, fall back to product
                // main stock (means "not separately tracked for this shade").
                $shadeStock     = (int) ($v['stock'] ?? 0);
                $productStock   = (int) ($p['stock']  ?? 0);
                $availableStock = $shadeStock > 0 ? $shadeStock : $productStock;
                if ($availableStock <= 0) {
                    return $this->validationError(['items' =>
                        "'{$p['name']}' – " . ($v['shade'] ?: $v['title']) . " is out of stock."]);
                }
                if ($qty > $availableStock) {
                    return $this->validationError(['items' =>
                        "Only {$availableStock} left of {$p['name']} – " . ($v['shade'] ?: $v['title']) . ". Reduce quantity."]);
                }
                $unit  = (int) ($v['sale_price_minor'] ?: $v['price_minor']);
                $sku   = (string) ($v['sku'] ?: $sku);
                $shade = $v['shade'] ?: $v['title'];
                if ($v['image_url']) $image = $v['image_url'];
            } else {
                if (!empty($p['sale_price_minor']) && $p['sale_price_minor'] < $unit) {
                    $unit = (int) $p['sale_price_minor'];
                }
                // No variant chosen — validate against product main stock.
                $productStock = (int) ($p['stock'] ?? 0);
                if ($productStock <= 0) {
                    return $this->validationError(['items' => "'{$p['name']}' is out of stock."]);
                }
                if ($qty > $productStock) {
                    return $this->validationError(['items' =>
                        "Only {$productStock} left of '{$p['name']}'. Reduce quantity."]);
                }
            }

            $line = $unit * $qty;

            // Apply best matching volume-discount tier for this product, if any.
            if (!array_key_exists($pid, $tierCache)) {
                $tierCache[$pid] = $vdm->forProduct($pid);
            }
            $tier = $vdm->pickTier($tierCache[$pid], $qty);
            $tierDiscount = $tier ? min((int) $tier['discount_minor'], $line) : 0;
            $line -= $tierDiscount;

            $out[] = [
                'product_id'           => $pid,
                'variant_id'           => $vid,
                'name'                 => $name,
                'sku'                  => $sku ?: null,
                'shade'                => $shade,
                'image'                => $image,
                'qty'                  => $qty,
                'unit_price_minor'     => $unit,
                'line_total_minor'     => $line,
                'volume_discount_minor'=> $tierDiscount,
                'tier_min_qty'         => $tier ? (int) $tier['min_qty'] : null,
            ];
        }
        return ['items' => $out];
    }

    private function computeTotals(array $items, string $payment, int $couponDiscount = 0): array
    {
        $subtotal = 0;
        foreach ($items as $i) $subtotal += $i['line_total_minor'];
        $discount = max(0, min($subtotal, (int) $couponDiscount));
        $afterDisc = max(0, $subtotal - $discount);
        $vatExcl = (int) round($afterDisc / (1 + self::VAT_PCT / 100));
        $vat     = $afterDisc - $vatExcl;
        // Free shipping threshold is checked AGAINST subtotal (before discount), keeping the rule simple.
        $rules = $this->shippingRules();
        $shipping = $subtotal >= $rules['free_above'] ? 0 : $rules['flat_fee'];
        $codFee  = $payment === 'cod' ? self::COD_FEE_MINOR : 0;
        $grand   = $afterDisc + $shipping + $codFee;

        return [
            'subtotal'    => $subtotal,
            'discount'    => $discount,
            'vat'         => $vat,
            'shipping'    => $shipping,
            'cod_fee'     => $codFee,
            'grand_total' => $grand,
            'currency'    => 'AED',
            'free_shipping_min' => $rules['free_above'],
        ];
    }

    /** Copy a saved-address DB row into the snapshot shape used by orders. */
    private function normaliseSavedAddress(array $a): ?array
    {
        $name    = trim((string) ($a['name']    ?? ''));
        $phone   = trim((string) ($a['phone']   ?? ''));
        $emirate = trim((string) ($a['emirate'] ?? ''));
        $area    = trim((string) ($a['area']    ?? ''));
        if (!$name || !$phone || !$emirate || !$area) return null;
        return [
            'name'      => $name,
            'phone'     => $phone,
            'emirate'   => $emirate,
            'area'      => $area,
            'street'    => $a['street']    ?: null,
            'building'  => $a['building']  ?: null,
            'floor'     => $a['floor']     ?: null,
            'apartment' => $a['apartment'] ?: null,
            'landmark'  => $a['landmark']  ?: null,
            'makani'    => $a['makani']    ?: null,
        ];
    }

    private function userId(): int
    {
        $hdr = $this->request->getHeaderLine('X-Auth-User');
        return (int) (json_decode($hdr, true)['sub'] ?? 0);
    }
}
