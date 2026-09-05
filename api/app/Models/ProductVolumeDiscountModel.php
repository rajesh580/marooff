<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductVolumeDiscountModel extends Model
{
    protected $table         = 'product_volume_discounts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = ['product_id', 'min_qty', 'discount_minor', 'sort_order'];

    /** Return all tiers for one product, ordered by qty ascending. */
    public function forProduct(int $productId): array
    {
        return $this->where('product_id', $productId)
            ->orderBy('min_qty', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * Given a qty, return the matching tier (highest min_qty <= qty) or null.
     * Tiers must already be ordered ASC by min_qty.
     */
    public function pickTier(array $tiers, int $qty): ?array
    {
        $best = null;
        foreach ($tiers as $t) {
            if ((int) $t['min_qty'] <= $qty) $best = $t;
        }
        return $best;
    }
}
