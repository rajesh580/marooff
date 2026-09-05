<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductImageModel extends Model
{
    protected $table         = 'product_images';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['product_id', 'url', 'media_type', 'poster_url', 'alt', 'sort_order', 'created_at'];

    public function forProduct(int $productId): array
    {
        return $this->where('product_id', $productId)->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->findAll();
    }
}
