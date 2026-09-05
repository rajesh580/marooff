<?php

namespace App\Libraries;

use App\Models\OrderAddressModel;
use App\Models\OrderItemModel;
use App\Models\OrderModel;

/**
 * High-level glue between our orders and Jeebly's create-shipment endpoint.
 *
 * Call ::autoCreate($orderId) from anywhere — usually right after an order is
 * materialised. The method is fire-and-forget: it logs failures, never throws,
 * and stores the AWB on the order on success (or the error message in
 * shipping_error so admin sees why creation failed and can retry).
 */
final class JeeblyShipmentService
{
    /** Fire after a new order is materialised. Returns the AWB on success, null otherwise. */
    public static function autoCreate(int $orderId): ?string
    {
        try {
            $jeebly = Jeebly::fromEnv();
            if (!$jeebly->isConfigured()) {
                log_message('info', 'Jeebly skipped (not configured) for order #' . $orderId);
                return null;
            }

            $om    = new OrderModel();
            $order = $om->find($orderId);
            if (!$order) {
                log_message('warning', "Jeebly autoCreate: order #{$orderId} not found");
                return null;
            }
            // Don't ship orders the customer is picking up themselves.
            if (($order['shipping_method'] ?? 'home_delivery') === 'pickup') {
                return null;
            }
            // Don't double-create — UNIQUE index would block, but skip cleanly.
            if (!empty($order['shipping_reference'])) {
                return $order['shipping_reference'];
            }

            $shipAddr = (new OrderAddressModel())->forOrder($orderId)['ship'] ?? null;
            if (!$shipAddr) {
                $om->update($orderId, ['shipping_error' => 'No shipping address on order']);
                return null;
            }

            $payload = self::buildPayload($order, $shipAddr);
            if (isset($payload['__error'])) {
                $om->update($orderId, ['shipping_error' => $payload['__error']]);
                log_message('warning', 'Jeebly payload build failed: ' . $payload['__error']);
                return null;
            }

            $resp = $jeebly->createShipment($payload);
            $awb  = (string) ($resp['AWB No'] ?? '');
            if ($awb === '') {
                $om->update($orderId, ['shipping_error' => 'Jeebly returned no AWB: ' . json_encode($resp)]);
                return null;
            }

            // CRITICAL: persist the AWB FIRST in its own tiny query, BEFORE any other
            // column updates. If this fails we've created a real shipment at Jeebly
            // that we can't locally reference — log loudly so admin can reconcile.
            $db = \Config\Database::connect();
            $affected = $db->table('orders')
                ->where('id', $orderId)
                ->where('shipping_reference', null)   // only claim if still NULL — defends against double-create races
                ->update([
                    'shipping_provider'  => 'jeebly',
                    'shipping_reference' => $awb,
                ]);
            if (!$affected || $db->affectedRows() < 1) {
                log_message('critical', "Jeebly ORPHAN AWB: created {$awb} at Jeebly but could not stamp order #{$orderId} — manual reconcile required");
                $om->update($orderId, ['shipping_error' => "ORPHAN: Jeebly created {$awb} but local UPDATE didn't claim it. Reconcile in Jeebly dashboard."]);
                return null;
            }
            // Now safe to update the non-critical metadata.
            $om->update($orderId, [
                'shipping_status'      => 'Pickup Scheduled',
                'shipping_pickup_date' => $payload['pickup_date'] ?? null,
                'shipping_error'       => null,
            ]);
            log_message('info', "Jeebly shipment created: order #{$orderId} → {$awb}");
            return $awb;
        } catch (\Throwable $e) {
            // Soft-fail: store the message for admin visibility, but never break order placement.
            try { (new OrderModel())->update($orderId, ['shipping_error' => substr($e->getMessage(), 0, 1000)]); } catch (\Throwable) {}
            log_message('error', 'Jeebly autoCreate threw: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build the Jeebly /create_shipment payload from a local order + shipping address row.
     * Returns ['__error' => 'reason'] if a validation rule can't be met.
     */
    public static function buildPayload(array $order, array $shipAddr): array
    {
        // ---- Pickup (origin) — from env, the merchant's own store ----
        $oCity = Jeebly::normaliseCity((string) env('jeebly.pickup.city', 'Dubai')) ?: 'Dubai';
        [$oCC, $oLocal] = Jeebly::splitPhone((string) env('jeebly.pickup.phone', '+971500000000'));
        if (!$oCC || !$oLocal) return ['__error' => 'Pickup phone in env is invalid'];

        // ---- Destination — customer's shipping address ----
        $destCity = Jeebly::normaliseCity((string) ($shipAddr['emirate'] ?? ''));
        if ($destCity === '') return ['__error' => 'Destination city is outside the UAE-supported list'];

        [$dCC, $dLocal] = Jeebly::splitPhone((string) ($shipAddr['phone'] ?? ''));
        if (!$dCC || !$dLocal) return ['__error' => 'Customer phone is not valid E.164'];

        // ---- Money — Jeebly wants AED in major units ----
        $paymentType = ($order['payment_method'] ?? '') === 'cod' ? 'COD' : 'Prepaid';
        $grandMajor  = round(((int) $order['grand_total_minor']) / 100, 2);
        if ($paymentType === 'COD' && $grandMajor > 5000) {
            return ['__error' => 'COD over AED 5,000 is not supported by Jeebly. Switch this order to Prepaid or split it.'];
        }
        $codAmount = $paymentType === 'COD' ? max(0.01, $grandMajor) : null;

        // ---- Items → weight + description ----
        $items = (new OrderItemModel())->forOrder((int) $order['id']);
        $totalQty = array_sum(array_map(fn ($i) => (int) ($i['qty'] ?? 1), $items));
        if ($totalQty > 10) {
            return ['__error' => "Order has {$totalQty} pieces but Jeebly accepts max 10 per shipment. Split this order manually or contact Jeebly to raise the limit."];
        }
        $numPieces = max(1, $totalQty);
        $weightKg  = (int) max(1, min(20, $numPieces)); // 1KG/piece default — pessimistic but safe
        $descBits  = array_map(
            fn ($i) => trim(($i['qty'] ?? '1') . '× ' . substr((string) ($i['name_snapshot'] ?? 'item'), 0, 40)),
            array_slice($items, 0, 5)
        );
        $description = substr(implode(', ', $descBits), 0, 200);

        // ---- Reference number — Jeebly accepts our order number (4-20 chars) ----
        $custRef = substr((string) ($order['order_number'] ?? ''), 0, 20);

        // ---- Origin address fields (with safe defaults) ----
        $hasVal = fn ($v) => $v !== null && $v !== '' && $v !== false;
        $destHouse = $hasVal($shipAddr['building'] ?? null) ? (string) $shipAddr['building'] : 'N/A';
        $destFloor = $hasVal($shipAddr['floor']    ?? null) ? ('Floor ' . $shipAddr['floor']) : '';
        $destApt   = $hasVal($shipAddr['apartment']?? null) ? (string) $shipAddr['apartment'] : '';
        $destBuilding = trim(($destApt ? $destApt . ', ' : '') . ($destFloor ? $destFloor . ', ' : '') . (string) ($shipAddr['building'] ?? 'N/A')) ?: 'N/A';
        $destLandmark = trim((string) ($shipAddr['landmark'] ?? $shipAddr['makani'] ?? 'N/A')) ?: 'N/A';

        $payload = [
            'delivery_type'      => 'Next day',
            'load_type'          => 'Non-document',
            'consignment_type'   => 'Forward',
            'description'        => $description ?: 'Cosmetics order',
            'weight'             => $weightKg,
            'payment_type'       => $paymentType,
            'num_pieces'         => $numPieces,

            // -- Origin / pickup (merchant) --
            'origin_address_name'                => (string) env('jeebly.pickup.name',     'Maroof Store'),
            'origin_address_mob_no_country_code' => $oCC,
            'origin_address_mobile_number'       => $oLocal,
            'origin_address_house_no'            => (string) env('jeebly.pickup.house',    'Shop 9/10'),
            'origin_address_building_name'       => (string) env('jeebly.pickup.building', 'Al Dalal & Sons Building'),
            'origin_address_area'                => (string) env('jeebly.pickup.area',     'Al Buteen'),
            'origin_address_landmark'            => (string) env('jeebly.pickup.landmark', 'Deira'),
            'origin_address_city'                => $oCity,
            'origin_address_type'                => (string) env('jeebly.pickup.address_type', 'Normal'),

            // -- Destination / drop (customer) --
            'destination_address_name'                => substr((string) ($shipAddr['name'] ?? $order['customer_name'] ?? ''), 0, 255) ?: 'Customer',
            'destination_address_mob_no_country_code' => $dCC,
            'destination_address_mobile_number'       => $dLocal,
            'destination_address_house_no'            => substr($destHouse,    0, 255),
            'destination_address_building_name'       => substr($destBuilding, 0, 255),
            'destination_address_area'                => substr((string) ($shipAddr['area'] ?? 'N/A'), 0, 255) ?: 'N/A',
            'destination_address_landmark'            => substr($destLandmark, 0, 255) ?: 'N/A',
            'destination_address_city'                => $destCity,
            'destination_address_type'                => 'Normal',

            'pickup_date' => Jeebly::nextValidPickupDate(),
        ];

        // Conditionally-included optional fields. We omit them entirely (rather
        // than sending null) because Jeebly's validators tend to reject null on
        // optional-object/number fields.
        if ($paymentType === 'COD') {
            $payload['cod_amount'] = $codAmount;
        }
        // customer_reference_number is optional and must be >=4 chars; drop if shorter.
        if (strlen($custRef) >= 4) {
            $payload['customer_reference_number'] = $custRef;
        }
        return $payload;
    }
}
