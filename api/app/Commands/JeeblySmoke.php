<?php

namespace App\Commands;

use App\Libraries\Jeebly;
use App\Libraries\JeeblyShipmentService;
use App\Models\OrderModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Jeebly smoke test.
 *
 *   php spark jeebly:smoke              — probe the API by tracking a known-bogus AWB
 *   php spark jeebly:smoke <order_id>   — actually create a shipment for the given order
 *
 * The probe path doesn't create a real shipment; it just confirms the credentials are
 * recognised by the configured env (demo vs production) using the tracking endpoint.
 */
class JeeblySmoke extends BaseCommand
{
    protected $group       = 'Marooff';
    protected $name        = 'jeebly:smoke';
    protected $description = 'Test the Jeebly API credentials and (optionally) auto-create a shipment for an order.';

    public function run(array $params)
    {
        $client = Jeebly::fromEnv();
        CLI::write('Environment: ' . env('jeebly.environment', 'demo'),     'yellow');
        CLI::write('X-API-KEY:   ' . substr((string) env('jeebly.x_api_key', ''), 0, 8) . '…', 'yellow');
        CLI::write('client_key:  ' . substr((string) env('jeebly.client_key', ''), 0, 16) . '…', 'yellow');
        CLI::write('');

        if (!$client->isConfigured()) {
            CLI::error('Jeebly is not configured — set jeebly.x_api_key and jeebly.client_key in .env');
            return 1;
        }

        // ----- Mode 1: order_id was provided → real auto-create -----
        $orderId = (int) ($params[0] ?? 0);
        if ($orderId > 0) {
            $order = (new OrderModel())->find($orderId);
            if (!$order) {
                CLI::error("Order #{$orderId} not found");
                return 1;
            }
            CLI::write("Auto-creating shipment for order #{$orderId} (" . $order['order_number'] . ')…', 'cyan');
            $awb = JeeblyShipmentService::autoCreate($orderId);
            $fresh = (new OrderModel())->find($orderId);
            if ($awb) {
                CLI::write('✓ Created AWB ' . $awb, 'green');
                CLI::write('  shipping_status: ' . ($fresh['shipping_status'] ?? '?'));
                CLI::write('  pickup_date:     ' . ($fresh['shipping_pickup_date'] ?? '?'));
                return 0;
            }
            CLI::error('✗ Creation failed. shipping_error: ' . ($fresh['shipping_error'] ?? '(none)'));
            return 1;
        }

        // ----- Mode 2: probe (no shipment created) -----
        CLI::write('Probing Jeebly with a bogus AWB to check credentials…', 'cyan');
        try {
            $resp = $client->trackShipment('JB000000');
            CLI::write('✓ Credentials recognised — tracking returned: ' . json_encode($resp, JSON_PRETTY_PRINT), 'green');
            return 0;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // "Invalid Shipment Number" is the GOOD answer — means auth passed but the AWB doesn't exist.
            if (stripos($msg, 'invalid shipment number') !== false || stripos($msg, 'no shipment') !== false) {
                CLI::write('✓ Credentials recognised (tracking returned "' . $msg . '" for the bogus AWB)', 'green');
                return 0;
            }
            CLI::error('✗ ' . $msg);
            return 1;
        }
    }
}
