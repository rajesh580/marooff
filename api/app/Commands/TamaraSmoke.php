<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Tamara smoke test — probes both sandbox and production with the configured
 * merchant token to figure out which environment recognises it.
 *
 *   php spark tamara:smoke
 *
 * Each environment is hit with a tiny GET /orders/<bogus-id> request. Tamara's
 * response tells us exactly what's wrong:
 *
 *   HTTP 200 / 404 with "Order not found"   → token is for THIS env (use this)
 *   HTTP 401 / 403 with "Merchant is not found" / "Unauthorized" → wrong env or
 *                                                                 inactive merchant
 *   HTTP 500 / network error → connectivity problem, not a credential problem
 */
class TamaraSmoke extends BaseCommand
{
    protected $group       = 'Marooff';
    protected $name        = 'tamara:smoke';
    protected $description = 'Probe Tamara sandbox + production with the configured merchant token.';

    public function run(array $params)
    {
        $token = (string) env('tamara.merchant_token', '');
        if ($token === '') {
            CLI::error('tamara.merchant_token is empty in .env');
            return 1;
        }

        $parts = explode('.', $token);
        if (count($parts) >= 2) {
            $payload = json_decode($this->b64UrlDecode($parts[1]) ?: '{}', true);
            CLI::write('Token payload:', 'cyan');
            CLI::write('  accountId : ' . ($payload['accountId'] ?? '?'));
            CLI::write('  type      : ' . ($payload['type']      ?? '?'));
            CLI::write('  roles     : ' . json_encode($payload['roles'] ?? []));
            CLI::write('  iss       : ' . ($payload['iss']       ?? '?'));
            CLI::write('  iat       : ' . ($payload['iat']       ?? '?') . ' (' . date('Y-m-d', (int) ($payload['iat'] ?? 0)) . ')');
            CLI::write('');
        }

        $bogusOrderId = '00000000-0000-0000-0000-000000000000';
        $envs = [
            'sandbox'    => 'https://api-sandbox.tamara.co',
            'production' => 'https://api.tamara.co',
        ];

        foreach ($envs as $name => $base) {
            CLI::write("--- {$name} : {$base} ---", 'yellow');
            $url = $base . '/orders/' . $bogusOrderId;
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ],
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            CLI::write('  HTTP ' . $code);
            if ($body === false) {
                CLI::error('  curl error: ' . $err);
                continue;
            }
            $json    = json_decode((string) $body, true);
            $message = is_array($json) ? ($json['message'] ?? '') : (string) $body;
            CLI::write('  message   : ' . substr($message, 0, 200));
            CLI::write('  raw       : ' . substr((string) $body, 0, 240));

            // Interpretation
            if ($code === 401 || stripos($message, 'merchant is not found') !== false || stripos($message, 'unauthorized') !== false) {
                CLI::write('  ↳ ' . CLI::color('Token NOT recognised here.', 'red'));
            } elseif ($code === 404 && stripos($message, 'order') !== false) {
                CLI::write('  ↳ ' . CLI::color('Merchant recognised — use this environment.', 'green'));
            } elseif ($code === 200) {
                CLI::write('  ↳ ' . CLI::color('OK — use this environment.', 'green'));
            } else {
                CLI::write('  ↳ ' . CLI::color('Unclear; inspect raw body.', 'yellow'));
            }
            CLI::write('');
        }
        return 0;
    }

    private function b64UrlDecode(string $s): ?string
    {
        $t = strtr($s, '-_', '+/');
        $pad = strlen($t) % 4;
        if ($pad) $t .= str_repeat('=', 4 - $pad);
        $out = base64_decode($t, true);
        return $out === false ? null : $out;
    }
}
