<?php

namespace App\Models;

use CodeIgniter\Model;

class PendingCheckoutModel extends Model
{
    protected $table         = 'pending_checkouts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'payment_intent_id', 'user_id', 'amount_minor', 'currency',
        'payload_json', 'status', 'created_at', 'consumed_at',
    ];

    public function findByIntent(string $piId): ?array
    {
        $row = $this->where('payment_intent_id', $piId)->first();
        return $row ?: null;
    }
}
