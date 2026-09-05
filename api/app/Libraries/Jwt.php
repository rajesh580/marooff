<?php

namespace App\Libraries;

/**
 * Minimal HS256 JWT encoder/decoder. No external dependency.
 * Sufficient for admin-only auth in MVP. Replace with firebase/php-jwt if scope grows.
 */
final class Jwt
{
    public static function encode(array $payload, string $secret, int $ttlSeconds): string
    {
        $now = time();
        $payload = array_merge(['iat' => $now, 'exp' => $now + $ttlSeconds], $payload);
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $headerB64 = self::b64UrlEncode(json_encode($header));
        $payloadB64 = self::b64UrlEncode(json_encode($payload));
        $sig = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true);
        return $headerB64 . '.' . $payloadB64 . '.' . self::b64UrlEncode($sig);
    }

    /**
     * Returns the decoded payload on success or null if the token is invalid/expired.
     */
    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        [$headerB64, $payloadB64, $sigB64] = $parts;
        $expected = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true);
        $given = self::b64UrlDecode($sigB64);
        if ($given === false || !hash_equals($expected, $given)) return null;
        $payloadJson = self::b64UrlDecode($payloadB64);
        if ($payloadJson === false) return null;
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) return null;
        if (isset($payload['exp']) && time() >= (int) $payload['exp']) return null;
        return $payload;
    }

    private static function b64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $encoded): string|false
    {
        $pad = strlen($encoded) % 4;
        if ($pad) $encoded .= str_repeat('=', 4 - $pad);
        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
