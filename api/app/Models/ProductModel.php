<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductModel extends Model
{
    protected $table         = 'products';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'category_id', 'slug', 'sku', 'name', 'brand', 'short_desc', 'description',
        'price_minor', 'sale_price_minor', 'currency', 'stock',
        'is_active', 'is_featured', 'is_new', 'is_bestseller', 'position', 'main_image_url',
        'name_ar', 'short_desc_ar', 'description_ar', 'brand_ar', 'ar_hash',
    ];

    protected $validationRules = [
        'slug'        => 'required|max_length[190]|regex_match[/^[a-z0-9-]+$/]',
        'name'        => 'required|max_length[255]',
        'price_minor' => 'required|integer|greater_than_equal_to[0]',
    ];

    public function findBySlug(string $slug): ?array
    {
        $row = $this->where('slug', $slug)->first();
        return $row ?: null;
    }

    /**
     * Listing with optional filters. Returns ['items' => [...], 'total' => N].
     */
    public function search(array $opts): array
    {
        $b = $this->where('is_active', 1);
        if (!empty($opts['category_id']))    $b = $b->where('category_id', (int) $opts['category_id']);
        if (!empty($opts['category_slug'])) {
            $b = $b->where('category_id', $this->db->table('categories')->where('slug', $opts['category_slug'])->select('id')->get()->getRow('id'));
        }
        if (!empty($opts['featured']))       $b = $b->where('is_featured', 1);
        if (!empty($opts['new']))            $b = $b->where('is_new', 1);
        if (!empty($opts['bestseller']))     $b = $b->where('is_bestseller', 1);
        if (!empty($opts['q'])) {
            $q = (string) $opts['q'];
            $b = $b->groupStart()->like('name', $q)->orLike('sku', $q)->orLike('brand', $q)->groupEnd();
        }
        if (!empty($opts['min_price'])) $b = $b->where('price_minor >=', (int) $opts['min_price']);
        if (!empty($opts['max_price'])) $b = $b->where('price_minor <=', (int) $opts['max_price']);

        // Default sort:
        //   - "position" honours the admin-set position; 0s (unset) fall to the end by id DESC
        //   - When filtering by category, position is the natural default
        $sort = $opts['sort'] ?? (!empty($opts['category_id']) || !empty($opts['category_slug']) ? 'position' : 'new');
        match ($sort) {
            'price_asc'  => $b->orderBy('price_minor', 'ASC'),
            'price_desc' => $b->orderBy('price_minor', 'DESC'),
            'name_asc'   => $b->orderBy('name', 'ASC'),
            'position'   => $b->orderBy('(CASE WHEN position > 0 THEN 0 ELSE 1 END)', 'ASC', false)
                              ->orderBy('position', 'ASC')->orderBy('id', 'DESC'),
            default      => $b->orderBy('id', 'DESC'),
        };

        $total  = (clone $b)->countAllResults(false);
        $items  = $b->limit($opts['limit'] ?? 24, $opts['offset'] ?? 0)->find();
        $this->attachHoverImages($items);
        return ['items' => $items, 'total' => $total];
    }

    /**
     * Adds a `hover_image_url` field to each product in $items, in-place.
     *
     * The hover image is the first row in `product_images` (ordered by sort_order
     * then id) whose URL differs from the product's main_image_url. That mirrors
     * the storefront's ProductCard expectation: hover the card → see the second
     * angle / shade.
     *
     * Call this from any controller that returns product arrays to the storefront
     * so the hover-swap effect is consistent across home, category, and search pages.
     *
     * @param array<int, array<string, mixed>> $items  Products with at least `id`
     *                                                  and `main_image_url`.
     */
    public function attachHoverImages(array &$items): void
    {
        if (!$items) return;

        $ids = array_column($items, 'id');
        $mainByPid = [];
        foreach ($items as $it) $mainByPid[(int) $it['id']] = $it['main_image_url'] ?? null;

        $rows = $this->db->table('product_images')
            ->select('product_id, url, sort_order, id')
            ->whereIn('product_id', $ids)
            ->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $hoverMap = [];
        foreach ($rows as $r) {
            $pid = (int) $r['product_id'];
            if (isset($hoverMap[$pid])) continue;                              // already picked one for this product
            if (($r['url'] ?? '') === ($mainByPid[$pid] ?? null)) continue;    // skip duplicate of main
            $hoverMap[$pid] = $r['url'];
        }
        foreach ($items as &$it) {
            $it['hover_image_url'] = $hoverMap[(int) $it['id']] ?? null;
        }
        unset($it);
    }
}
