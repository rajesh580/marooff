<?php

namespace App\Models;

use CodeIgniter\Model;

class AddressModel extends Model
{
    protected $table         = 'addresses';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'user_id', 'label', 'type', 'name', 'phone',
        'emirate', 'area', 'street', 'building', 'floor', 'apartment',
        'landmark', 'makani', 'is_default',
    ];

    public const TYPE_BILLING  = 0;
    public const TYPE_SHIPPING = 1;

    public function forUser(int $userId): array
    {
        return $this->where('user_id', $userId)
            ->orderBy('type', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /** Get the billing+shipping pair for a user. Returns ['billing' => ?array, 'shipping' => ?array]. */
    public function pairForUser(int $userId): array
    {
        $rows = $this->forUser($userId);
        $out  = ['billing' => null, 'shipping' => null];
        foreach ($rows as $r) {
            if ((int) $r['type'] === self::TYPE_BILLING)  $out['billing']  = $r;
            if ((int) $r['type'] === self::TYPE_SHIPPING) $out['shipping'] = $r;
        }
        return $out;
    }

    public function hasPair(int $userId): bool
    {
        $p = $this->pairForUser($userId);
        return $p['billing'] && $p['shipping'];
    }

    /** Ensure only one default per user. */
    public function makeDefault(int $userId, int $addressId): void
    {
        $this->where('user_id', $userId)->set(['is_default' => 0])->update();
        $this->update($addressId, ['is_default' => 1]);
    }
}
