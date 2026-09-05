<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Seeds the Marooff store with 100% Marooff data:
 *
 *  - Source     : ROOT\marooff-products.json (extracted from mirror/marooffc.com/product/<slug>/index.html)
 *  - Images     : local files under public/uploads/marooff-products/<slug>/ (copied from the WordPress mirror)
 *  - Categories : Face, Eyes, Lips, Cheeks, Nails, Body (mapped from WooCommerce categories)
 *  - Banners    : public/uploads/banners/marooff-hero-{1,2,3}.jpg
 *
 * No Forever52 data is referenced. All prior product rows are wiped on each run.
 */
class MarooffCatalogSeeder extends Seeder
{
    private const PRODUCTS_JSON = 'D:\\marooff\\maroof-backend-api\\marooff-backend-api\\marooff-products.json';

    /** Top-level categories shown in the storefront navigation. */
    private const CATEGORIES = [
        ['face',   'Face'],
        ['eyes',   'Eyes'],
        ['lips',   'Lips'],
        ['cheeks', 'Cheeks'],
        ['nails',  'Nails'],
        ['body',   'Body'],
    ];

    /** Map the WooCommerce category name → our internal slug. */
    private const CATEGORY_MAP = [
        'face'     => 'face',
        'eyes'     => 'eyes',
        'lips'     => 'lips',
        'cheek'    => 'cheeks',
        'cheeks'   => 'cheeks',
        'nails'    => 'nails',
        'nail'     => 'nails',
        'legs'     => 'body',
        'body'     => 'body',
        'featured' => null,   // not a real category; we re-route via product name heuristics
    ];

    public function run()
    {
        $jsonPath = is_file(ROOTPATH . 'marooff-products.json') ? (ROOTPATH . 'marooff-products.json') : self::PRODUCTS_JSON;
        if (!is_file($jsonPath)) {
            echo "Missing input: " . $jsonPath . "\n";
            return;
        }
        $data = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($data['products'] ?? null)) {
            echo "Malformed marooff-products.json\n";
            return;
        }
        $now = date('Y-m-d H:i:s');

        // 1) Wipe products, variants, images, and banners
        if ($this->db->DBDriver === 'SQLite3') {
            $this->db->query('PRAGMA foreign_keys = OFF');
        } else {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach (['product_variants', 'product_images', 'products', 'banners'] as $t) {
            $this->db->table($t)->truncate();
        }
        if ($this->db->DBDriver === 'SQLite3') {
            $this->db->query('PRAGMA foreign_keys = ON');
        } else {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        // 2) Categories: use existing or insert missing
        $catIds = [];
        $existing = $this->db->table('categories')->get()->getResultArray();
        foreach ($existing as $c) {
            $catIds[$c['slug']] = (int) $c['id'];
        }
        $sort = count($existing);
        foreach (self::CATEGORIES as [$slug, $name]) {
            if (!isset($catIds[$slug])) {
                $sort++;
                $this->db->table('categories')->insert([
                    'parent_id'  => null,
                    'slug'       => $slug,
                    'name'       => $name,
                    'image_url'  => null,
                    'sort_order' => $sort,
                    'is_active'  => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $catIds[$slug] = (int) $this->db->insertID();
            }
        }
        if (!isset($catIds['other'])) {
            $this->db->table('categories')->insert([
                'parent_id'  => null, 'slug' => 'other', 'name' => 'Other',
                'sort_order' => 999, 'is_active' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $catIds['other'] = (int) $this->db->insertID();
        }

        // 3) Products + product_images + product_variants
        $imageRows   = [];
        $variantRows = [];
        $featuredCount = 0; $newCount = 0; $bestCount = 0;
        $maxF = 6; $maxN = 6; $maxB = 6;
        foreach ($data['products'] as $idx => $p) {
            $slug  = (string) ($p['slug'] ?? '');
            $title = trim((string) ($p['title'] ?? ''));
            if (!$slug || !$title) continue;

            $catSlug = $this->routeCategory((string) ($p['category'] ?? ''), $title);
            $catId   = $catIds[$catSlug] ?? $catIds['other'];

            $images = array_values(array_filter((array) ($p['images'] ?? [])));
            $main   = $p['main_image'] ?? ($images[0] ?? null);

            $isF = $featuredCount < $maxF && ($idx % 5) === 0; if ($isF) $featuredCount++;
            $isN = $newCount      < $maxN && ($idx % 5) === 2; if ($isN) $newCount++;
            $isB = $bestCount     < $maxB && ($idx % 5) === 4; if ($isB) $bestCount++;

            $this->db->table('products')->insert([
                'category_id'      => $catId,
                'slug'             => $slug,
                'sku'              => $p['sku'] ?? null,
                'name'             => $title,
                'brand'            => 'Maroof',
                'short_desc'       => $p['short_desc'] ?? null,
                'description'      => $p['description'] ?? null,
                'price_minor'      => (int) ($p['price_minor'] ?? 0),
                'sale_price_minor' => null,
                'currency'         => 'AED',
                'stock'            => 25,
                'is_active'        => 1,
                'is_featured'      => $isF ? 1 : 0,
                'is_new'           => $isN ? 1 : 0,
                'is_bestseller'    => $isB ? 1 : 0,
                'main_image_url'   => $main,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
            $productId = (int) $this->db->insertID();

            foreach ($images as $i => $u) {
                $imageRows[] = [
                    'product_id' => $productId, 'url' => $u, 'alt' => $title,
                    'sort_order' => $i, 'created_at' => $now,
                ];
            }

            // Variants (each shade) — populate the product_variants table so the PDP swatch picker has data.
            // Prefer swatch_image (small + local) over full_image for the picker UI.
            foreach ((array) ($p['variants'] ?? []) as $v) {
                $variantImage = $v['swatch_image'] ?? $v['full_image'] ?? null;
                $variantPriceMinor = isset($v['price']) && is_numeric($v['price']) ? (int) round($v['price'] * 100) : 0;
                $variantRows[] = [
                    'product_id'       => $productId,
                    'sku'              => $v['sku'] ?: null,
                    'title'            => (string) ($v['title'] ?? ''),
                    'shade'            => (string) ($v['shade'] ?? ''),
                    'price_minor'      => $variantPriceMinor,
                    'sale_price_minor' => null,
                    'image_url'        => $variantImage,
                    'is_available'     => !empty($v['is_in_stock']) ? 1 : 0,
                    'sort_order'       => (int) ($v['sort_order'] ?? 0),
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }
        }
        foreach (array_chunk($imageRows, 500) as $chunk) {
            $this->db->table('product_images')->insertBatch($chunk);
        }
        foreach (array_chunk($variantRows, 500) as $chunk) {
            $this->db->table('product_variants')->insertBatch($chunk);
        }

        // 4) Set category hero images — use a representative product photo per category
        $this->setCategoryImages($catIds);

        // 5) Banners (Marooff-only)
        $this->db->table('banners')->insert([
            'placement'  => 'home_hero',
            'title'      => 'Premium cosmetics, made for everyday glam',
            'subtitle'   => 'Discover Maroof — authentic UAE-made beauty.',
            'image_url'  => 'http://localhost:8080/uploads/banners/marooff-hero-1.jpg',
            'link_url'   => '/products',
            'sort_order' => 1, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('banners')->insert([
            'placement'  => 'home_hero',
            'title'      => 'The Eyeshadow Edit',
            'subtitle'   => 'Bold pigment, blendable formula, all-day wear.',
            'image_url'  => 'http://localhost:8080/uploads/banners/marooff-hero-2.jpg',
            'link_url'   => '/category/eyes',
            'sort_order' => 2, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('banners')->insert([
            'placement'  => 'home_hero',
            'title'      => 'Lip Edit — Long-Lasting Glam',
            'subtitle'   => 'From matte to gloss, find your signature shade.',
            'image_url'  => 'http://localhost:8080/uploads/banners/marooff-hero-3.jpg',
            'link_url'   => '/category/lips',
            'sort_order' => 3, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // 6) Settings — update with full catalogue contact block
        $settingsUpdates = [
            'store_name'    => 'Maroof',
            'store_tagline' => 'Premium cosmetics, made for everyday glam',
            'support_email' => 'fakhreecosmetics@gmail.com',
            'support_phone' => '+971 55 3978656',
            'support_phone2'=> '+971 4 2268786',
            'address'       => 'Shop 9/10, Al Dalal & Sons Building, Al Buteen, Deira, Dubai, UAE — PO Box 6553',
        ];
        foreach ($settingsUpdates as $k => $v) {
            $existing = $this->db->table('settings')->where('key', $k)->get()->getRowArray();
            if ($existing) {
                $this->db->table('settings')->where('key', $k)->update(['value' => $v, 'updated_at' => $now]);
            } else {
                $this->db->table('settings')->insert(['key' => $k, 'value' => $v, 'updated_at' => $now]);
            }
        }

        $pCount = $this->db->table('products')->countAllResults();
        $iCount = count($imageRows);
        echo "MarooffCatalogSeeder: {$pCount} products, {$iCount} images, " . count(self::CATEGORIES) . " categories, 3 banners.\n";
    }

    /** Map a WooCommerce category name → our internal slug, with name-fallback heuristic. */
    private function routeCategory(string $woocomCat, string $title): string
    {
        $key = strtolower(trim($woocomCat));
        if (array_key_exists($key, self::CATEGORY_MAP)) {
            $mapped = self::CATEGORY_MAP[$key];
            if ($mapped) return $mapped;
        }
        // Name-based heuristic (covers "Featured" and missing categories)
        $hay = strtolower($title);
        if (preg_match('/foundation|primer|powder|base|fixer|concealer|corrector|highlighter|illuminator|contour|compact|setting/', $hay)) return 'face';
        if (preg_match('/eyeshadow|eyeliner|mascara|kajal|lash|eyebrow|brow|kohl|glitter/', $hay))               return 'eyes';
        if (preg_match('/lipstick|lip[- ]?gloss|lip[- ]?liner|lipgloss|lip /', $hay))                            return 'lips';
        if (preg_match('/blush|bronz|cheek/', $hay))                                                              return 'cheeks';
        if (preg_match('/nail/', $hay))                                                                            return 'nails';
        if (preg_match('/legs|body/', $hay))                                                                       return 'body';
        return 'face';
    }

    /** Pick a representative product image per category and set categories.image_url. */
    private function setCategoryImages(array $catIds): void
    {
        foreach ($catIds as $slug => $id) {
            if ($slug === 'other') continue;
            $row = $this->db->table('products')
                ->select('main_image_url')
                ->where('category_id', $id)
                ->where('main_image_url IS NOT NULL', null, false)
                ->orderBy('id', 'ASC')
                ->limit(1)
                ->get()->getRowArray();
            if ($row && !empty($row['main_image_url'])) {
                $this->db->table('categories')->where('id', $id)->update(['image_url' => $row['main_image_url']]);
            }
        }
    }
}
