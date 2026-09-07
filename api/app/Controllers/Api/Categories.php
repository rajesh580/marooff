<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\CategoryModel;
use App\Models\ProductModel;

class Categories extends BaseController
{
    private const CATEGORY_AR_MAP = ['name' => 'name_ar', 'description' => 'description_ar'];
    private const PRODUCT_AR_MAP  = [
        'name' => 'name_ar', 'short_desc' => 'short_desc_ar',
        'description' => 'description_ar', 'brand' => 'brand_ar',
    ];

    /**
     * Returns the full category tree: parents (parent_id NULL) with their children nested.
     * Each category includes `product_count` (active products directly in that category).
     * Parents also receive a `product_total` aggregating itself + all descendants — so a flat
     * leaf-parent (one without children, like the new Marooff schema) still reports its own count.
     */
    public function index()
    {
        $lang = (string) ($this->request->getGet('lang') ?? 'en');
        $cacheKey = 'categories_index_' . $lang;
        try {
            if ($cached = cache($cacheKey)) {
                return $this->ok($cached);
            }
        } catch (\Throwable $e) {}

        try {
            $rows = (new CategoryModel())->activeOrdered();

            // Count active products per category in a single query.
            $counts = [];
            $db = \Config\Database::connect();
            $q  = $db->query('SELECT category_id, COUNT(*) AS n FROM products WHERE is_active = 1 GROUP BY category_id');
            foreach ($q->getResultArray() as $r) {
                $counts[(int) $r['category_id']] = (int) $r['n'];
            }

            $parents = [];
            $childrenByParent = [];
            foreach ($rows as $r) {
                $r['product_count'] = $counts[(int) $r['id']] ?? 0;
                if ($r['parent_id'] === null) $parents[] = $r;
                else $childrenByParent[(int) $r['parent_id']][] = $r;
            }
            foreach ($parents as &$p) {
                $kids = $childrenByParent[(int) $p['id']] ?? [];
                usort($kids, fn ($a, $b) => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));
                $kids = $this->localizeMany($kids, self::CATEGORY_AR_MAP);
                $p['children']      = $kids;
                // Total = parent's own products + all children's products
                $p['product_total'] =
                    (int) ($p['product_count'] ?? 0)
                    + array_sum(array_map(fn ($c) => (int) ($c['product_count'] ?? 0), $kids));
            }
            $parents = $this->localizeMany($parents, self::CATEGORY_AR_MAP);

            try {
                cache()->save($cacheKey, $parents, 120);
            } catch (\Throwable $e) {}

            return $this->ok($parents);
        } catch (\Throwable $e) {
            log_message('error', 'Categories::index failed: ' . $e->getMessage());
            return $this->serverError('Failed to load categories: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/categories/:slug
     *
     * - Child category   → returns { category, children:[], products, products_total }
     * - Leaf-parent (no children, like our Marooff schema) → returns its OWN products
     * - Parent with children → aggregates products across the parent itself AND its children
     */
    public function show(string $slug)
    {
        [, $limit, $offset] = $this->pageParams(24, 60);
        $lang = (string) ($this->request->getGet('lang') ?? 'en');
        $cacheKey = 'cat_show_' . md5($slug . '_' . $limit . '_' . $offset . '_' . $lang);
        try {
            if ($cached = cache($cacheKey)) {
                return $this->ok($cached);
            }
        } catch (\Throwable $e) {}

        try {
            $catM = new CategoryModel();
            $cat  = $catM->findBySlug($slug);
            if (!$cat || !$cat['is_active']) return $this->notFound('Category not found');

            $isParent = $cat['parent_id'] === null;

            if ($isParent) {
                $children = $catM->where('parent_id', $cat['id'])->where('is_active', 1)
                    ->orderBy('sort_order', 'ASC')->findAll();

                $categoryIds = [(int) $cat['id']];
                foreach ($children as $c) $categoryIds[] = (int) $c['id'];

                // Fetch ALL products in newest-first order, then apply absolute-slot positioning,
                // then slice for pagination.
                $pm = new ProductModel();
                $b  = $pm->whereIn('category_id', $categoryIds)->where('is_active', 1)->orderBy('id', 'DESC');
                $allProducts   = $b->findAll();
                $productsTotal = count($allProducts);
                $ordered       = self::publicRepositionBySlot($allProducts);
                $products      = array_slice($ordered, $offset, $limit);
                // Hover-image enrichment so the storefront ProductCard's mouse-enter swap works.
                $pm->attachHoverImages($products);

                $res = [
                    'category'       => $this->localize($cat, self::CATEGORY_AR_MAP),
                    'children'       => $this->localizeMany($children, self::CATEGORY_AR_MAP),
                    'products'       => $this->localizeMany($products, self::PRODUCT_AR_MAP),
                    'products_total' => $productsTotal,
                ];

                try {
                    cache()->save($cacheKey, $res, 120);
                } catch (\Throwable $e) {}

                return $this->ok($res);
            }

            // Child category — fetch all, apply slot positioning, slice for the page.
            $pm = new ProductModel();
            $all = $pm->where('category_id', (int) $cat['id'])->where('is_active', 1)
                ->orderBy('id', 'DESC')->findAll();
            $total   = count($all);
            $ordered = self::publicRepositionBySlot($all);
            $page    = array_slice($ordered, $offset, $limit);
            $pm->attachHoverImages($page);
            $res = [
                'category'       => $this->localize($cat, self::CATEGORY_AR_MAP),
                'children'       => [],
                'products'       => $this->localizeMany($page, self::PRODUCT_AR_MAP),
                'products_total' => $total,
            ];

            try {
                cache()->save($cacheKey, $res, 120);
            } catch (\Throwable $e) {}

            return $this->ok($res);
        } catch (\Throwable $e) {
            log_message('error', "Categories::show('$slug') failed: " . $e->getMessage());
            return $this->serverError('Failed to load category: ' . $e->getMessage());
        }
    }

    /**
     * Rearrange a newest-first product list so that products with `position > 0` land at
     * their exact slot (position=2 → 2nd slot), and unpositioned products fill the gaps
     * around them in their original newest-first order.
     *
     * Examples (input newest-first = [A, B, C, D, E], one positioned F at position 3):
     *   slot 1 ← A (unpositioned)
     *   slot 2 ← B (unpositioned)
     *   slot 3 ← F (positioned)
     *   slot 4 ← C (unpositioned)
     *   slot 5 ← D (unpositioned)
     *   slot 6 ← E (unpositioned)
     *
     * Multiple positioned at same slot: tie-broken by newest-first.
     * Position > total: pushed to the end.
     */
    public static function publicRepositionBySlot(array $products): array
    {
        if (count($products) <= 1) return $products;

        $positioned   = []; // [slot => [product, ...]]
        $unpositioned = [];
        foreach ($products as $p) {
            $pos = (int) ($p['position'] ?? 0);
            if ($pos > 0) {
                if (!isset($positioned[$pos])) $positioned[$pos] = [];
                $positioned[$pos][] = $p;
            } else {
                $unpositioned[] = $p;
            }
        }
        if (!$positioned) return $products; // fast path — nothing to rearrange

        $total = count($products);
        // Clamp any out-of-range positions to the last slot.
        $clamped = [];
        foreach ($positioned as $slot => $group) {
            $effective = min(max(1, $slot), $total);
            if (!isset($clamped[$effective])) $clamped[$effective] = [];
            foreach ($group as $p) $clamped[$effective][] = $p;
        }
        ksort($clamped);

        $out = [];
        $unposIdx = 0;
        for ($slot = 1; $slot <= $total; $slot++) {
            if (isset($clamped[$slot])) {
                foreach ($clamped[$slot] as $p) {
                    if (count($out) < $total) $out[] = $p;
                }
            }
            while (count($out) < $slot && $unposIdx < count($unpositioned)) {
                $out[] = $unpositioned[$unposIdx++];
            }
        }
        // Sweep up any unpositioned not yet placed (happens when multiple share a slot).
        while ($unposIdx < count($unpositioned) && count($out) < $total) {
            $out[] = $unpositioned[$unposIdx++];
        }
        return $out;
    }
}
