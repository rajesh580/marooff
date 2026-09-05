<?php

namespace App\Models;

use CodeIgniter\Model;

class OrderAddressModel extends Model
{
    protected $table         = 'order_addresses';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'order_id', 'type', 'name', 'phone',
        'emirate', 'area', 'street', 'building', 'floor', 'apartment',
        'landmark', 'makani',
        'created_at',
    ];

    public function forOrder(int $orderId): array
    {
        $rows = $this->where('order_id', $orderId)->findAll();
        $out = ['ship' => null, 'bill' => null];
        foreach ($rows as $r) $out[$r['type']] = $r;
        return $out;
    }
}
