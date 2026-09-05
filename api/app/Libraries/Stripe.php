<?php

namespace App\Libraries;

/**
 * Minimal Stripe API client — no SDK dependency, pure cURL.
 * Sufficient for the PaymentIntent + retrieve flow Marooff needs.
 *
 * Usage:
 *   $stripe = new Stripe(env('stripe.secret_key'));
 *   $intent = $stripe->createPaymentIntent($amountMinor, $currency, [...]);
 *
 * The Stripe API expects form-urlencoded request bodies (NOT JSON) and uses
 * `parent[child]` bracket notation for nested params.
 */
final class Stripe
{
    private const BASE = 'https://api.stripe.com/v1';
    private const API_VERSION = '2024-12-18.acacia';

    public function __construct(private string $secretKey) {}

    /**
     * Create a PaymentIntent.
     *
     * @param int    $amountMinor  Amount in the smallest currency unit (fils for AED).
     * @param string $currency     ISO 4217 code, lowercase ("aed").
     * @param array  $opts         { metadata: [], description: '', receipt_email: '' }
     */
    public function createPaymentIntent(int $amountMinor, string $currency, array $opts = []): array
    {
        $payload = [
            'amount'                => $amountMinor,
            'currency'              => strtolower($currency),
            // The PaymentElement / mobile wallets handle all method types automatically.
            'automatic_payment_methods[enabled]' => 'true',
        ];
        if (!empty($opts['description']))   $payload['description']   = $opts['description'];
        if (!empty($opts['receipt_email'])) $payload['receipt_email'] = $opts['receipt_email'];
        foreach (($opts['metadata'] ?? []) as $k => $v) {
            $payload["metadata[$k]"] = (string) $v;
        }
        return $this->request('POST', '/payment_intents', $payload);
    }

    /** Retrieve a PaymentIntent by id — used to confirm payment server-side. */
    public function retrievePaymentIntent(string $id): array
    {
        return $this->request('GET', "/payment_intents/" . urlencode($id));
    }

    /**
     * Verify a webhook signature (HMAC-SHA256 using the signing secret).
     * Returns true if valid, false otherwise. Skips when $signingSecret is empty.
     */
    public static function verifyWebhookSignature(string $payload, string $sigHeader, string $signingSecret, int $tolerance = 300): bool
    {
        if (!$signingSecret) return true; // dev: signature verification disabled
        // Header format:  t=12345,v1=abc...,v0=xyz
        $parts = [];
        foreach (explode(',', $sigHeader) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, null);
            $parts[$k] = $v;
        }
        if (empty($parts['t']) || empty($parts['v1'])) return false;
        $timestamp = (int) $parts['t'];
        if (abs(time() - $timestamp) > $tolerance) return false;

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $signingSecret);
        return hash_equals($expected, $parts['v1']);
    }

    private function request(string $method, string $path, array $params = []): array
    {
        $ch = curl_init();
        $url = self::BASE . $path;
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Stripe-Version: ' . self::API_VERSION,
        ];

        if ($method === 'GET' && $params) {
            $url .= '?' . http_build_query($params);
        }

        $curlOpts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ];

        // Ensure SSL CA certificate bundle is loaded on environments without global CA configured
        $caInfo = ini_get('curl.cainfo');
        if (empty($caInfo) || !file_exists($caInfo)) {
            $bundle = __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem';
            if (file_exists($bundle)) {
                $curlOpts[CURLOPT_CAINFO] = $bundle;
            }
        }

        curl_setopt_array($ch, $curlOpts);
        if ($method !== 'GET' && $params) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Stripe connection failed: ' . $err);
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            throw new \RuntimeException("Stripe returned non-JSON (HTTP $code): " . substr((string) $body, 0, 300));
        }
        if ($code >= 400) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $code);
            $e = new StripeException("Stripe API error: $msg");
            // Attach the raw response so the controller can include details if needed.
            $e->stripeResponse = $json;
            throw $e;
        }
        return $json;
    }
}

class StripeException extends \RuntimeException
{
    public ?array $stripeResponse = null;
}
