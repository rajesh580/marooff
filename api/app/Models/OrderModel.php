<?php

namespace App\Models;

use CodeIgniter\Model;

class OrderModel extends Model
{
    protected $table         = 'orders';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'order_number', 'user_id', 'status',
        'subtotal_minor', 'discount_minor', 'vat_minor', 'shipping_fee_minor', 'cod_fee_minor', 'grand_total_minor',
        'currency', 'payment_method', 'payment_status', 'payment_ref',
        'customer_name', 'customer_email', 'customer_phone', 'notes', 'cancelled_reason',
        'placed_at', 'confirmed_at', 'shipped_at', 'delivered_at', 'cancelled_at',
        'coupon_id', 'coupon_code', 'shipping_method',
        // Jeebly delivery tracking (added 2026-06-20)
        'shipping_provider', 'shipping_reference', 'shipping_status',
        'shipping_pickup_date', 'shipping_last_event_at', 'shipping_error',
    ];

    public static function generateOrderNumber(): string
    {
        return 'M-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }

    public function countsByStatus(): array
    {
        $rows = $this->select('status, COUNT(*) AS c')->groupBy('status')->find();
        $out = ['placed' => 0, 'confirmed' => 0, 'shipped' => 0, 'delivered' => 0, 'cancelled' => 0, 'refunded' => 0];
        foreach ($rows as $r) $out[$r['status']] = (int) $r['c'];
        $out['total'] = array_sum($out);
        return $out;
    }
}
