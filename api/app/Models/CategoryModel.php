<?php

namespace App\Models;

use CodeIgniter\Model;

class CategoryModel extends Model
{
    protected $table         = 'categories';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = ['parent_id', 'slug', 'name', 'description', 'image_url', 'banner_url', 'sort_order', 'is_active', 'name_ar', 'description_ar', 'ar_hash'];

    protected $validationRules = [
        'slug' => 'required|max_length[190]|regex_match[/^[a-z0-9-]+$/]',
        'name' => 'required|max_length[190]',
    ];

    public function findBySlug(string $slug): ?array
    {
        $row = $this->where('slug', $slug)->first();
        return $row ?: null;
    }

    public function activeOrdered(): array
    {
        return $this->where('is_active', 1)->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')->findAll();
    }
}
