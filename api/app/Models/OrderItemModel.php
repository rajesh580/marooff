<?php

namespace App\Models;

use CodeIgniter\Model;

class OrderItemModel extends Model
{
    protected $table         = 'order_items';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    // We pass created_at explicitly. CI4 emits malformed SQL when useTimestamps=true and
    // updatedField=null, so manage timestamps ourselves.
    protected $useTimestamps = false;

    protected $allowedFields = [
        'order_id', 'product_id', 'variant_id',
        'name_snapshot', 'sku_snapshot', 'shade_snapshot', 'image_snapshot',
        'qty', 'unit_price_minor', 'line_total_minor',
        'created_at',
    ];

    public function forOrder(int $orderId): array
    {
        return $this->where('order_id', $orderId)->orderBy('id', 'ASC')->findAll();
    }
}
