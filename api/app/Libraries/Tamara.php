<?php

namespace App\Libraries;

/**
 * Minimal Tamara API client — pure cURL, no SDK.
 *
 * Auth:  Authorization: Bearer <merchant_api_token>
 *
 * Tamara uses DECIMAL major units (e.g. 299.00 AED, NOT 29900 fils). Money values
 * are objects of shape { amount: float, currency: "AED" }. All helper methods that
 * accept minor units convert internally before sending.
 *
 * Env keys read here:
 *   tamara.environment           sandbox|production            (default: sandbox)
 *   tamara.api_base              full base URL override        (default: per environment)
 *   tamara.merchant_token        the Partners-Portal API JWT   (Bearer)
 *   tamara.notification_token    webhook signing secret (HS256)
 *
 * The class throws RuntimeException on transport / 4xx / 5xx — callers must catch.
 */
final class Tamara
{
    private const SANDBOX_BASE    = 'https://api-sandbox.tamara.co';
    private const PRODUCTION_BASE = 'https://api.tamara.co';

    public function __construct(
        private string $token,
        private string $baseUrl,
    ) {}

    /** Build a client from env so callers don't repeat the boilerplate. */
    public static function fromEnv(): self
    {
        $token = (string) env('tamara.merchant_token', '');
        $env   = strtolower((string) env('tamara.environment', 'sandbox'));
        $base  = (string) env('tamara.api_base', '');
        if ($base === '') {
            $base = $env === 'production' ? self::PRODUCTION_BASE : self::SANDBOX_BASE;
        }
        return new self($token, rtrim($base, '/'));
    }

    // ---------------------------------------------------------- Money helpers
    /** Convert minor units (fils) to a Tamara money object. 29900 → { amount: 299.00, currency: "AED" } */
    public static function money(int $minor, string $currency = 'AED'): array
    {
        return [
            'amount'   => round($minor / 100, 2),
            'currency' => $currency,
        ];
    }

    // ---------------------------------------------------------- Create Checkout
    /**
     * POST /checkout — create a hosted-checkout session and get the redirect URL.
     * Returns the full response: { order_id, checkout_id, checkout_url, status }.
     */
    public function createCheckout(array $payload): array
    {
        return $this->request('POST', '/checkout', $payload);
    }

    // ---------------------------------------------------------- Order ops
    /** GET /orders/{id} — authoritative status check by Tamara's own UUID. */
    public function getOrder(string $orderId): array
    {
        return $this->request('GET', '/orders/' . urlencode($orderId));
    }

    /**
     * POST /orders/{id}/authorise — flip from "approved" → "authorised".
     * Called by the merchant from the order_approved webhook handler.
     * Tamara expects no body and returns 200 with no content / a tiny status object.
     */
    public function authorise(string $orderId): array
    {
        return $this->request('POST', '/orders/' . urlencode($orderId) . '/authorise', []);
    }

    /**
     * POST /payments/capture — charge the customer at shipment / fulfilment.
     * Caller is responsible for passing the full body shape Tamara expects
     * (order_id, total_amount, shipping_amount, tax_amount, shipping_info, items).
     */
    public function capture(array $payload): array
    {
        return $this->request('POST', '/payments/capture', $payload);
    }

    /** POST /payments/simple-refund — full or partial refund. */
    public function refund(string $orderId, array $payload): array
    {
        return $this->request('POST', '/payments/simple-refund/' . urlencode($orderId), $payload);
    }

    /** POST /orders/{id}/cancel — cancel an authorised (but uncaptured) order. */
    public function cancel(string $orderId, array $payload = []): array
    {
        return $this->request('POST', '/orders/' . urlencode($orderId) . '/cancel', $payload);
    }

    // ---------------------------------------------------------- Webhook verify
    /**
     * Verify a JWT signed by Tamara (HS256, secret = the Notification Token).
     *
     * Returns the decoded payload array on success. Returns:
     *   ['__skipped' => true]  ONLY when $notificationToken is empty (dev-mode skip).
     *                          Callers MUST refuse this in production.
     *   null                   on any verification failure (bad parts, wrong alg, bad
     *                          signature, expired, future-dated, crit header, etc.)
     */
    public static function verifyWebhookJwt(string $jwt, string $notificationToken, int $maxAgeSeconds = 600): ?array
    {
        if ($notificationToken === '') {
            return ['__skipped' => true];
        }
        if ($jwt === '') return null;

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;
        [$h64, $p64, $s64] = $parts;
        // Strict base64url — no padding chars in input.
        if (strpbrk($h64 . $p64 . $s64, '=') !== false) return null;

        $headerJson  = self::b64UrlDecode($h64);
        $payloadJson = self::b64UrlDecode($p64);
        $sig         = self::b64UrlDecode($s64);
        if ($headerJson === null || $payloadJson === null || $sig === null) return null;

        $header  = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        if (!is_array($header) || !is_array($payload)) return null;
        if (($header['alg'] ?? '') !== 'HS256') return null;
        // RFC 7515 §4.1.11 — unknown crit extensions must cause rejection.
        if (isset($header['crit'])) return null;

        $signingInput = $h64 . '.' . $p64;
        $expected = hash_hmac('sha256', $signingInput, $notificationToken, true);
        if (!hash_equals($expected, $sig)) return null;

        // Temporal claims — defeat indefinite replay of a captured JWT.
        $now = time();
        if (isset($payload['exp']) && (int) $payload['exp'] < $now)          return null;
        if (isset($payload['nbf']) && (int) $payload['nbf'] > $now + 60)     return null;
        if (isset($payload['iat'])) {
            $age = $now - (int) $payload['iat'];
            // Tamara JWTs include iat; reject if too old or wildly future-dated.
            if ($age > $maxAgeSeconds || $age < -60) return null;
        }

        return $payload;
    }

    private static function b64UrlDecode(string $s): ?string
    {
        $t = strtr($s, '-_', '+/');
        $pad = strlen($t) % 4;
        if ($pad) $t .= str_repeat('=', 4 - $pad);
        $out = base64_decode($t, true);
        return $out === false ? null : $out;
    }

    // ---------------------------------------------------------- HTTP
    private function request(string $method, string $path, array $body = []): array
    {
        $ch = curl_init();
        $url = $this->baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];

        $curlOpts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
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

        if ($method !== 'GET') {
            // Tamara always wants JSON bodies (not form-urlencoded like Stripe).
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ($body) {
            $url .= '?' . http_build_query($body);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException('Tamara connection failed: ' . $err);
        }
        // Some 200 responses (e.g. authorise on success) are empty / no JSON. Treat as ok.
        if ($resp === '' && $code >= 200 && $code < 300) {
            return ['__empty' => true, 'http_code' => $code];
        }
        $json = json_decode((string) $resp, true);
        if (!is_array($json)) {
            if ($code >= 200 && $code < 300) {
                return ['__raw' => (string) $resp, 'http_code' => $code];
            }
            throw new \RuntimeException("Tamara returned non-JSON (HTTP $code): " . substr((string) $resp, 0, 300));
        }
        if ($code >= 400) {
            $msg = $json['message'] ?? ($json['error']['message'] ?? ('HTTP ' . $code));
            $errors = isset($json['errors']) ? (' | ' . json_encode($json['errors'])) : '';
            $e = new TamaraException('Tamara API error: ' . $msg . $errors);
            $e->tamaraResponse = $json; // attach raw response for caller diagnostics
            throw $e;
        }
        return $json;
    }
}

class TamaraException extends \RuntimeException
{
    public ?array $tamaraResponse = null;
}
