<?php

namespace App\Libraries;

/**
 * Jeebly Scheduled-Delivery API client.
 *
 * Auth (every request):
 *   X-API-KEY: <jeebly.x_api_key>
 *   client_key: <jeebly.client_key>
 *
 * Two environments documented:
 *   demo       → https://demo.jeebly.com
 *   production → https://myjeebly.jeebly.com
 *
 * Endpoints used:
 *   POST /customer/create_shipment        – create AWB
 *   POST /customer/track_shipment         – status + event history
 *   POST /customer/generate_shipment_label – returns binary PDF
 *   POST /customer/cancel_shipment        – cancel BEFORE pickup-completed
 */
final class Jeebly
{
    private const DEMO_BASE       = 'https://demo.jeebly.com';
    private const PRODUCTION_BASE = 'https://myjeebly.jeebly.com';

    /** UAE city values Jeebly accepts — exact strings, case-sensitive. */
    public const CITIES = [
        'Abu Dhabi', 'Ajman', 'Al-Ain', 'Dubai',
        'Fujairah', 'Ras Al Khaimah', 'Sharjah', 'Umm Al-Quwain',
    ];

    public function __construct(
        private string $xApiKey,
        private string $clientKey,
        private string $baseUrl,
    ) {}

    public static function fromEnv(): self
    {
        $env  = strtolower((string) env('jeebly.environment', 'demo'));
        $base = (string) env('jeebly.api_base', '');
        if ($base === '') {
            $base = $env === 'production' ? self::PRODUCTION_BASE : self::DEMO_BASE;
        }
        return new self(
            (string) env('jeebly.x_api_key', ''),
            (string) env('jeebly.client_key', ''),
            rtrim($base, '/'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->xApiKey !== '' && $this->clientKey !== '';
    }

    // -------------------------------------------------------- API methods
    /** POST /customer/create_shipment — returns { success, message, "AWB No" }. */
    public function createShipment(array $body): array
    {
        return $this->request('/customer/create_shipment', $body);
    }

    /** POST /customer/track_shipment — { success, Tracking: { reference_no, last_status, events[] } }. */
    public function trackShipment(string $referenceNumber): array
    {
        return $this->request('/customer/track_shipment', ['reference_number' => $referenceNumber]);
    }

    /** POST /customer/cancel_shipment — { success, message }. Only valid before pickup_completed. */
    public function cancelShipment(string $referenceNumber): array
    {
        return $this->request('/customer/cancel_shipment', ['reference_number' => $referenceNumber]);
    }

    /**
     * POST /customer/generate_shipment_label — returns raw binary PDF on success.
     * We don't json_decode; caller gets ['__binary' => true, 'pdf' => <bytes>] or
     * a normal JSON-shaped error.
     */
    public function generateLabel(string $referenceNumber): array
    {
        $url = $this->baseUrl . '/customer/generate_shipment_label';
        [$body, $code, $contentType] = $this->rawRequest($url, ['reference_number' => $referenceNumber]);
        if ($code === 200 && stripos($contentType, 'application/pdf') !== false) {
            return ['__binary' => true, 'pdf' => (string) $body, 'http_code' => $code];
        }
        $json = json_decode((string) $body, true);
        if (is_array($json)) {
            if ($code >= 400 || (($json['success'] ?? '') === 'false')) {
                throw new \RuntimeException('Jeebly label error: ' . ($json['message'] ?? "HTTP $code"));
            }
            return $json;
        }
        throw new \RuntimeException("Jeebly label returned non-PDF non-JSON (HTTP $code)");
    }

    // -------------------------------------------------------- Webhook auth
    /**
     * Validate a webhook delivery. Jeebly authenticates pushed events with
     * the same X-API-KEY header we use for outbound calls (per Jeebly docs).
     * Returns true iff the header matches the configured API key, false otherwise.
     * Empty configured key → returns true ONLY in dev (caller's job to gate by env).
     */
    public function verifyWebhookKey(string $providedKey): bool
    {
        if ($this->xApiKey === '') return false;
        return hash_equals($this->xApiKey, $providedKey);
    }

    // -------------------------------------------------------- Helpers
    /**
     * Normalise an arbitrary UAE city string (case/punctuation-insensitive) into
     * Jeebly's exact enum, e.g. "dubai " → "Dubai", "al ain" → "Al-Ain".
     * Returns "" if the input doesn't map to any known UAE city.
     */
    public static function normaliseCity(string $raw): string
    {
        $k = strtolower(preg_replace('/[\s\-_]+/', ' ', trim($raw)));
        $map = [
            'abu dhabi'      => 'Abu Dhabi',
            'abudhabi'       => 'Abu Dhabi',
            'auh'            => 'Abu Dhabi',
            'ajman'          => 'Ajman',
            'al ain'         => 'Al-Ain',
            'alain'          => 'Al-Ain',
            'dubai'          => 'Dubai',
            'dxb'            => 'Dubai',
            'fujairah'       => 'Fujairah',
            'fujeirah'       => 'Fujairah',
            'fujaira'        => 'Fujairah',
            'ras al khaimah' => 'Ras Al Khaimah',
            'rasalkhaimah'   => 'Ras Al Khaimah',
            'rak'            => 'Ras Al Khaimah',
            'sharjah'        => 'Sharjah',
            'sharja'         => 'Sharjah',
            'umm al quwain'  => 'Umm Al-Quwain',
            'ummalquwain'    => 'Umm Al-Quwain',
            'uaq'            => 'Umm Al-Quwain',
        ];
        // Try the space-normalised key first, then the no-space form (handles
        // "abudhabi", "rasalkhaimah" etc. that the regex above leaves spaced).
        return $map[$k] ?? $map[str_replace(' ', '', $k)] ?? '';
    }

    /**
     * Split a stored E.164 phone (e.g. "+971521457861") into (country_code, local_number)
     * exactly as Jeebly wants — country code with the leading '+' and local digits-only.
     * Returns [null, null] if the input isn't a valid UAE number (+971 + 9 digits).
     * Jeebly only delivers within the UAE, so any non-UAE phone is rejected here.
     */
    public static function splitPhone(string $e164): array
    {
        $p = trim($e164);
        if ($p === '' || $p[0] !== '+') return [null, null];
        // Strictly UAE: +971 + 9 local digits. Matches the same constraint
        // Checkout::normalisePhoneE164 enforces on saved addresses.
        if (preg_match('/^\+971(\d{9})$/', $p, $m)) {
            return ['+971', $m[1]];
        }
        return [null, null];
    }

    /**
     * Pickup date that satisfies Jeebly's calendar rules for Next-Day delivery:
     *   - never a Sunday (Jeebly does not pick up on Sundays)
     *   - never today after the cutoff. The PDF docs say 4 PM, but the live
     *     API rejects with "Pickup not allowed after 2 PM" — so the real cutoff
     *     is 14:00 local. We use 13:30 to leave room for processing latency.
     *
     * Override via env: `jeebly.pickup_cutoff_hour` and `jeebly.pickup_cutoff_minute`
     * if Jeebly's rules change for your account.
     *
     * Returns YYYY-MM-DD. Uses the server's local timezone.
     */
    public static function nextValidPickupDate(): string
    {
        // CRITICAL: compute in Asia/Dubai TZ regardless of server TZ — otherwise
        // a UTC host computes "today" wrong by 4 hours, picking up a date Jeebly
        // refuses (late evening Dubai → next-day in UTC, etc.).
        $tz     = new \DateTimeZone('Asia/Dubai');
        $hour   = (int) (env('jeebly.pickup_cutoff_hour',   13) ?? 13);
        $minute = (int) (env('jeebly.pickup_cutoff_minute', 30) ?? 30);
        $now    = new \DateTime('now', $tz);
        $cutoff = (clone $now)->setTime($hour, $minute);

        $isSunday = (int) $now->format('w') === 0; // 0 = Sun in Dubai TZ
        if ($now < $cutoff && !$isSunday) {
            return $now->format('Y-m-d');
        }
        $next = (clone $now)->modify('+1 day');
        while ((int) $next->format('w') === 0) $next->modify('+1 day');
        return $next->format('Y-m-d');
    }

    // -------------------------------------------------------- HTTP
    private function request(string $path, array $body): array
    {
        $url = $this->baseUrl . $path;
        [$resp, $code] = $this->rawRequest($url, $body);

        $json = json_decode((string) $resp, true);
        if (!is_array($json)) {
            throw new \RuntimeException("Jeebly returned non-JSON (HTTP $code): " . substr((string) $resp, 0, 300));
        }
        // Jeebly returns "success" as STRING "true"/"false" — normalise to bool for the caller.
        $successRaw = $json['success'] ?? null;
        $successBool = $successRaw === true || $successRaw === 'true' || $successRaw === '1';
        if ($code >= 400 || !$successBool) {
            $msg = $json['message'] ?? ('HTTP ' . $code);
            $e = new \RuntimeException('Jeebly API error: ' . $msg);
            $e->jeeblyResponse = $json;
            throw $e;
        }
        return $json;
    }

    /** Low-level POST that returns [body, httpCode, contentType] for binary handlers. */
    private function rawRequest(string $url, array $body): array
    {
        $ch = curl_init($url);
        $headers = [
            'X-API-KEY: ' . $this->xApiKey,
            'client_key: ' . $this->clientKey,
            'Content-Type: application/json',
            'Accept: */*',
        ];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException('Jeebly connection failed: ' . $err);
        }
        return [$resp, $code, $ct];
    }
}
