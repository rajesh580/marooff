<?php

namespace App\Models;

use CodeIgniter\Model;

class CouponModel extends Model
{
    protected $table         = 'coupons';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'code', 'type', 'value_minor', 'min_order_minor', 'max_uses', 'uses_count',
        'starts_at', 'ends_at', 'is_active', 'notes',
    ];

    public function findByCode(string $code): ?array
    {
        $row = $this->where('code', strtoupper(trim($code)))->first();
        return $row ?: null;
    }

    /**
     * Returns ['ok' => bool, 'reason' => string|null, 'discount_minor' => int]
     * for a given coupon row + cart subtotal.
     */
    public function validateForSubtotal(array $coupon, int $subtotal): array
    {
        if (!$coupon || !(int) $coupon['is_active'])      return ['ok' => false, 'reason' => 'This coupon is not active.', 'discount_minor' => 0];
        if (!empty($coupon['starts_at']) && strtotime($coupon['starts_at']) > time()) return ['ok' => false, 'reason' => 'This coupon is not valid yet.', 'discount_minor' => 0];
        if (!empty($coupon['ends_at'])   && strtotime($coupon['ends_at'])   < time()) return ['ok' => false, 'reason' => 'This coupon has expired.', 'discount_minor' => 0];
        if (!empty($coupon['max_uses']) && (int) $coupon['uses_count'] >= (int) $coupon['max_uses']) {
            return ['ok' => false, 'reason' => 'This coupon has reached its usage limit.', 'discount_minor' => 0];
        }
        if ($subtotal < (int) $coupon['min_order_minor']) {
            return ['ok' => false,
                    'reason' => 'Minimum order of AED ' . number_format($coupon['min_order_minor'] / 100, 2) . ' required.',
                    'discount_minor' => 0];
        }

        // Compute the discount amount in minor units
        if ($coupon['type'] === 'percent') {
            $pct = max(0, min(100, (int) $coupon['value_minor']));
            $discount = (int) floor($subtotal * $pct / 100);
        } else { // fixed
            $discount = min($subtotal, (int) $coupon['value_minor']);
        }
        return ['ok' => true, 'reason' => null, 'discount_minor' => $discount];
    }
}
