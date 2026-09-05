<?php

namespace App\Models;

use CodeIgniter\Model;

class ComboModel extends Model
{
    protected $table         = 'combos';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'slug', 'name', 'description', 'image_url',
        'price_minor', 'sale_price_minor', 'currency', 'stock',
        'is_active', 'sort_order',
        'name_ar', 'description_ar', 'ar_hash',
    ];

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }

    public function listActive(int $limit = 24, int $offset = 0): array
    {
        return $this->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'DESC')
            ->limit($limit, $offset)
            ->findAll();
    }
}
