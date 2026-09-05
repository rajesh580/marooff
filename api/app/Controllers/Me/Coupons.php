<?php

namespace App\Controllers\Me;

use App\Controllers\BaseController;
use App\Models\CouponModel;
use App\Models\ProductModel;
use App\Models\ProductVariantModel;

/**
 * Customer-facing coupon validation. The storefront calls /api/me/coupons/validate
 * with the cart payload and a code; we resolve the cart subtotal server-side
 * (don't trust client-side prices) and return whether the coupon applies.
 */
class Coupons extends BaseController
{
    public function apply()
    {
        $body = $this->jsonBody();
        $code = strtoupper(trim((string) ($body['code'] ?? '')));
        if ($code === '') return $this->validationError(['code' => 'Required']);

        $cm = new CouponModel();
        $coupon = $cm->findByCode($code);
        if (!$coupon) return $this->fail('NOT_FOUND', 'No coupon with that code.', null, 404);

        // Combo bundles are excluded — coupons can't stack on bundle pricing.
        // resolveCartSubtotal already skips combo lines (they have no product_id).
        $rawItems = (array) ($body['items'] ?? []);
        $hasCombo    = false;
        $hasNonCombo = false;
        foreach ($rawItems as $r) {
            if (!empty($r['combo_id']))               $hasCombo    = true;
            elseif (!empty($r['product_id']))          $hasNonCombo = true;
        }
        if ($hasCombo && !$hasNonCombo) {
            return $this->fail('COMBO_ONLY', 'Coupons can\'t be applied to a cart that only contains combo bundles.', null, 422);
        }

        $subtotal = $this->resolveCartSubtotal($rawItems);

        $check = $cm->validateForSubtotal($coupon, $subtotal);
        if (!$check['ok']) return $this->fail('INVALID', $check['reason'], null, 422);

        $message = $coupon['type'] === 'percent'
                   ? $coupon['value_minor'] . '% off applied'
                   : 'AED ' . number_format($coupon['value_minor'] / 100, 2) . ' off applied';
        if ($hasCombo) $message .= ' (excludes combo bundles)';

        return $this->ok([
            'code'              => $coupon['code'],
            'type'              => $coupon['type'],
            'value_minor'       => (int) $coupon['value_minor'],
            'discount_minor'    => (int) $check['discount_minor'],
            'subtotal_minor'    => $subtotal,
            'excludes_combos'   => $hasCombo,
            'message'           => $message,
        ]);
    }

    private function resolveCartSubtotal(array $rawItems): int
    {
        if (!is_array($rawItems) || !count($rawItems)) return 0;
        $pm = new ProductModel();
        $vm = new ProductVariantModel();
        $total = 0;
        foreach ($rawItems as $r) {
            $pid = (int) ($r['product_id'] ?? 0);
            $vid = isset($r['variant_id']) && $r['variant_id'] !== null ? (int) $r['variant_id'] : null;
            $qty = max(1, (int) ($r['qty'] ?? 1));
            if (!$pid) continue;
            $p = $pm->find($pid);
            if (!$p || (int) $p['is_active'] !== 1) continue;
            $unit = !empty($p['sale_price_minor']) && $p['sale_price_minor'] < $p['price_minor']
                  ? (int) $p['sale_price_minor']
                  : (int) $p['price_minor'];
            if ($vid) {
                $v = $vm->find($vid);
                if ($v && (int) $v['product_id'] === $pid) {
                    $unit = (int) ($v['sale_price_minor'] ?: $v['price_minor']);
                }
            }
            $total += $unit * $qty;
        }
        return $total;
    }
}
