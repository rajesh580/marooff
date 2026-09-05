<?php

namespace App\Models;

use CodeIgniter\Model;

class OrderShippingEventModel extends Model
{
    protected $table         = 'order_shipping_events';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'order_id', 'reference_no', 'status', 'description', 'hub_name',
        'event_at', 'rider_code', 'rider_name', 'pod_image_url', 'failure_reason',
        'raw_payload', 'created_at',
    ];

    /** Full event timeline for one order, oldest first. */
    public function forOrder(int $orderId): array
    {
        return $this->where('order_id', $orderId)->orderBy('event_at', 'ASC')->find();
    }
}
