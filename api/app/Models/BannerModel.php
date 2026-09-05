<?php

namespace App\Models;

use CodeIgniter\Model;

class BannerModel extends Model
{
    protected $table         = 'banners';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = ['placement', 'title', 'subtitle', 'image_url', 'link_url', 'sort_order', 'is_active', 'starts_at', 'ends_at', 'title_ar', 'subtitle_ar', 'ar_hash'];

    public function activeForPlacement(string $placement): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->where('placement', $placement)
            ->where('is_active', 1)
            ->groupStart()->where('starts_at', null)->orWhere('starts_at <=', $now)->groupEnd()
            ->groupStart()->where('ends_at', null)->orWhere('ends_at >=', $now)->groupEnd()
            ->orderBy('sort_order', 'ASC')->findAll();
    }
}
