<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Controllers\Me\Checkout;
use App\Libraries\Stripe;
use App\Models\OrderModel;
use App\Models\PendingCheckoutModel;

/**
 * Public endpoint Stripe calls server-to-server on payment events.
 *
 *   1. Stripe signs every request with HMAC-SHA256 using `stripe.webhook_secret`.
 *   2. payment_intent.succeeded → materialise the order from the pending_checkouts
 *      row (acts as a safety net if the storefront's /finalize-stripe call never
 *      reached us — e.g. the user closed the tab while Stripe was charging).
 *   3. payment_intent.payment_failed / .canceled → mark the pending row as expired.
 *
 * Local dev: leave `stripe.webhook_secret` blank to skip signature verification.
 * To test webhooks in dev: `stripe listen --forward-to localhost:8080/api/webhooks/stripe`.
 */
class StripeWebhook extends BaseController
{
    public function handle()
    {
        $payload = $this->request->getBody() ?: '';
        $sigHeader = $this->request->getHeaderLine('Stripe-Signature');
        $signingSecret = (string) env('stripe.webhook_secret', '');

        if (!Stripe::verifyWebhookSignature($payload, $sigHeader, $signingSecret)) {
            return $this->fail('BAD_SIGNATURE', 'Invalid webhook signature', null, 400);
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['type'])) {
            return $this->fail('BAD_PAYLOAD', 'Not a Stripe event', null, 400);
        }

        $type   = (string) $event['type'];
        $object = $event['data']['object'] ?? [];
        $piId   = (string) ($object['id'] ?? '');

        if ($type === 'payment_intent.succeeded' && $piId !== '') {
            // Reuse the same settlement path the storefront takes. Passing null skips
            // the user-ownership check (the webhook is server-to-server, no JWT).
            (new Checkout())->settleStripeIntent($piId, null);
        }

        if (in_array($type, ['payment_intent.payment_failed', 'payment_intent.canceled'], true) && $piId !== '') {
            // If an order already exists for this PI (rare race), mark it failed.
            $om = new OrderModel();
            if ($existing = $om->where('payment_ref', $piId)->first()) {
                $om->update($existing['id'], ['payment_status' => 'failed']);
            }
            // Always mark the pending row expired so the cleanup job can purge it.
            (new PendingCheckoutModel())
                ->where('payment_intent_id', $piId)
                ->set(['status' => 'expired'])
                ->update();
        }

        return $this->ok(['received' => true]);
    }
}
