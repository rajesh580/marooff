<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Full Forever52 catalog replica seeder.
 *
 * Sources (all local):
 *  - products-rich.json    — extracted via scripts/extract-products.mjs (full descriptions, all images,
 *                            all variant shades pulled from each saved product page's SwymProductInfo blob).
 *  - category-banners.json — extracted via scripts/extract-category-banners.mjs (hero banner image per
 *                            collection, mapped to a local file in the mirror).
 *
 * Hero banner images for every category and the home page are COPIED FROM THE MIRROR into
 * `public/uploads/categories/` and `public/uploads/banners/`. Product images stay on Shopify CDN
 * URLs (those load cleanly anywhere with internet).
 *
 * Money is converted INR → AED at 0.044 (≈ 22.7 INR per AED, ~April 2026 rate) and stored as
 * integer fils (minor units).
 */
class Forever52CatalogSeeder extends Seeder
{
    private const ROOT          = 'D:\\marooff\\forever52-website-details';
    private const PRODUCTS_JSON = self::ROOT . '\\products-rich.json';
    private const BANNERS_JSON  = self::ROOT . '\\category-banners.json';
    private const INR_TO_AED    = 0.044;

    /** Parent → children. Each entry is [slug, name]. Banners come from category-banners.json. */
    private const TREE = [
        'face' => ['label' => 'Face', 'children' => [
            ['foundation',        'Foundation'],
            ['compact-powder',    'Compact Powder'],
            ['loose-powder',      'Loose Powder'],
            ['concealer',         'Concealer'],
            ['blush-bronze',      'Blush & Bronze'],
            ['skin-care',         'Skin Care'],
            ['contour-corrector', 'Contour & Corrector'],
            ['highlighter',       'Highlighter'],
            ['primer',            'Primer'],
            ['setting-spray',     'Setting Spray'],
        ]],
        'eyes' => ['label' => 'Eyes', 'children' => [
            ['eyeshadow',         'Eyeshadow'],
            ['eyeliner',          'Eyeliner'],
            ['mascara',           'Mascara'],
            ['kohl-kajal-pencil', 'Kohl / Kajal Pencil'],
            ['eyebrow',           'Eyebrow'],
            ['lash-glue',         'Lash Glue'],
            ['glitter',           'Glitter'],
        ]],
        'lips' => ['label' => 'Lips', 'children' => [
            ['lipstick',        'Lipstick'],
            ['liquid-lipstick', 'Liquid Lipstick'],
            ['lip-gloss',       'Lip Gloss'],
            ['lip-primer',      'Lip Primer'],
        ]],
        'nails' => ['label' => 'Nails', 'children' => [
            ['nail-polish', 'Nail Polish'],
            ['nail-tips',   'Nail Tips'],
        ]],
        'accessories' => ['label' => 'Accessories', 'children' => [
            ['brushes',         'Brushes'],
            ['sponges',         'Sponges'],
            ['brush-cleanser',  'Brush Cleanser'],
            ['makeup-removers', 'Makeup Removers'],
            ['steel-plate',     'Steel Plate'],
        ]],
    ];

    /** Map of "type" string (from Shopify) → our child category slug. */
    private const TYPE_RULES = [
        'foundation'              => 'foundation',
        'compact powder'          => 'compact-powder',
        'loose powder'            => 'loose-powder',
        'concealer'               => 'concealer',
        'blush & bronzer'         => 'blush-bronze',
        'blush'                   => 'blush-bronze',
        'bronzer'                 => 'blush-bronze',
        'cheek'                   => 'blush-bronze',
        'skin care'               => 'skin-care',
        'contour'                 => 'contour-corrector',
        'corrector'               => 'contour-corrector',
        'highlighter'             => 'highlighter',
        'illuminator'             => 'highlighter',
        'primer'                  => 'primer',
        'setting spray'           => 'setting-spray',
        'fixer'                   => 'setting-spray',

        'eyeshadow'               => 'eyeshadow',
        'eye shadow'              => 'eyeshadow',
        'eyeliner'                => 'eyeliner',
        'eye liner'               => 'eyeliner',
        'mascara'                 => 'mascara',
        'kajal'                   => 'kohl-kajal-pencil',
        'kohl'                    => 'kohl-kajal-pencil',
        'eyebrow'                 => 'eyebrow',
        'brow'                    => 'eyebrow',
        'lash glue'               => 'lash-glue',
        'glitter'                 => 'glitter',

        'lipstick'                => 'lipstick',
        'liquid lipstick'         => 'liquid-lipstick',
        'lip paint'               => 'liquid-lipstick',
        'lip gloss'               => 'lip-gloss',
        'lip primer'              => 'lip-primer',
        'lip liner'               => 'lipstick',

        'nail polish'             => 'nail-polish',
        'nail tips'               => 'nail-tips',
        'nail'                    => 'nail-polish',

        'brush'                   => 'brushes',
        'brushes'                 => 'brushes',
        'sponge'                  => 'sponges',
        'sponges'                 => 'sponges',
        'puff'                    => 'sponges',
        'cleanser'                => 'brush-cleanser',
        'wipes'                   => 'makeup-removers',
        'remover'                 => 'makeup-removers',
        'plate'                   => 'steel-plate',
    ];

    public function run()
    {
        if (!is_file(self::PRODUCTS_JSON) || !is_file(self::BANNERS_JSON)) {
            echo "Missing input file(s). Run extract-products.mjs and extract-category-banners.mjs first.\n";
            return;
        }
        $catalog = json_decode(file_get_contents(self::PRODUCTS_JSON), true);
        $banners = json_decode(file_get_contents(self::BANNERS_JSON), true);
        if (!is_array($catalog['products'] ?? null)) {
            echo "Bad products-rich.json\n";
            return;
        }

        $now = date('Y-m-d H:i:s');

        // ---- Wipe ----
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['product_variants', 'product_images', 'products', 'categories', 'banners'] as $t) {
            $this->db->table($t)->truncate();
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        // ---- Banner copy dest dirs ----
        $publicDir   = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
        $catBanDir   = $publicDir . DIRECTORY_SEPARATOR . 'categories';
        if (!is_dir($catBanDir)) mkdir($catBanDir, 0775, true);

        // ---- Categories ----
        $catIds = [];
        $sort = 0;
        foreach (self::TREE as $parentSlug => $node) {
            $sort++;
            $bannerUrl = $this->resolveBanner($parentSlug, $banners, $catBanDir);
            $this->db->table('categories')->insert([
                'parent_id'  => null, 'slug' => $parentSlug, 'name' => $node['label'],
                'image_url'  => $bannerUrl,
                'sort_order' => $sort, 'is_active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $catIds[$parentSlug] = (int) $this->db->insertID();

            $childSort = 0;
            foreach ($node['children'] as [$slug, $name]) {
                $childSort++;
                $childBanner = $this->resolveBanner($slug, $banners, $catBanDir);
                $this->db->table('categories')->insert([
                    'parent_id'  => $catIds[$parentSlug], 'slug' => $slug, 'name' => $name,
                    'image_url'  => $childBanner,
                    'sort_order' => $childSort, 'is_active' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $catIds[$slug] = (int) $this->db->insertID();
            }
        }
        // Fallback bucket for products we couldn't route.
        $this->db->table('categories')->insert([
            'parent_id' => null, 'slug' => 'other', 'name' => 'Other',
            'sort_order' => 999, 'is_active' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $catIds['other'] = (int) $this->db->insertID();

        // ---- Products + images + variants ----
        $imageRows = [];
        $variantRows = [];
        $featuredCount = 0; $newCount = 0; $bestCount = 0;
        $maxF = 16; $maxN = 16; $maxB = 16;

        foreach ($catalog['products'] as $idx => $p) {
            $slug  = (string) ($p['slug'] ?? '');
            $title = trim((string) ($p['title'] ?? ''));
            if (!$slug || !$title) continue;

            $childSlug = $this->routeByType((string) ($p['type'] ?? ''), $slug, $title);
            $catId     = $catIds[$childSlug] ?? $catIds['other'];

            $priceInr = $p['price_min_paisa'] ?? $p['price_paisa'] ?? null;
            $priceAed = $priceInr !== null ? round(((int) $priceInr / 100) * self::INR_TO_AED * 100) : 0;
            $compareInr = $p['compare_paisa'] ?? null;
            $compareAed = $compareInr && $compareInr > 0 && $compareInr != $priceInr
                ? round(((int) $compareInr / 100) * self::INR_TO_AED * 100)
                : null;

            $images = array_values(array_unique($p['images'] ?? []));
            $images = array_slice($images, 0, 12);
            $main   = $p['primary_image'] ?? ($images[0] ?? null);

            $description     = (string) ($p['description'] ?? '');
            $shortDescString = (string) ($p['short_desc'] ?? '');

            $isF = $featuredCount < $maxF && ($idx % 9) === 0;
            $isN = $newCount      < $maxN && ($idx % 9) === 3;
            $isB = $bestCount     < $maxB && ($idx % 9) === 6;
            if ($isF) $featuredCount++;
            if ($isN) $newCount++;
            if ($isB) $bestCount++;

            $this->db->table('products')->insert([
                'category_id'      => $catId,
                'slug'             => $slug,
                'sku'              => $p['variants'][0]['sku'] ?? null,
                'name'             => $title,
                'brand'            => 'Maroof', // Re-brand the imported catalog under Marooff.
                'short_desc'       => $shortDescString ?: null,
                'description'      => $description ?: null,
                'price_minor'      => (int) $priceAed,
                'sale_price_minor' => $compareAed,
                'currency'         => 'AED',
                'stock'            => 50,
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

            // Variants — these are the shade colors
            $vSort = 0;
            foreach ($p['variants'] ?? [] as $v) {
                $vSort++;
                $vp = (int) ($v['price_paisa'] ?? $priceInr);
                $vc = isset($v['compare_paisa']) && $v['compare_paisa'] ? (int) $v['compare_paisa'] : null;
                $variantRows[] = [
                    'product_id'       => $productId,
                    'sku'              => $v['sku'] ?? null,
                    'title'            => $v['title'] ?? $v['shade'] ?? '',
                    'shade'            => $v['shade'] ?? null,
                    'price_minor'      => (int) round(($vp / 100) * self::INR_TO_AED * 100),
                    'sale_price_minor' => $vc ? (int) round(($vc / 100) * self::INR_TO_AED * 100) : null,
                    'image_url'        => $v['image'] ?? null,
                    'is_available'     => !empty($v['available']) ? 1 : 0,
                    'sort_order'       => $vSort,
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

        // ---- Home hero banners (local files copied during earlier session) ----
        foreach ([
            ['placement' => 'home_hero', 'title' => 'All-Day Grip Primer', 'subtitle' => 'Lock in your look from morning to night', 'image_url' => '/api/uploads/banners/banner-1.png', 'link_url' => '/category/primer'],
            ['placement' => 'home_hero', 'title' => 'Amazonic Combo',      'subtitle' => 'Bestselling makeup essentials, bundled.', 'image_url' => '/api/uploads/banners/banner-2.png', 'link_url' => '/category/face'],
            ['placement' => 'home_hero', 'title' => 'Maroof Spring Edit',  'subtitle' => "Discover this season's fresh new arrivals.", 'image_url' => '/api/uploads/banners/banner-3.png', 'link_url' => '/products?sort=new'],
        ] as $i => $b) {
            $this->db->table('banners')->insert(array_merge($b, [
                'sort_order' => $i + 1, 'is_active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]));
        }

        $pCount  = $this->db->table('products')->countAllResults();
        $iCount  = count($imageRows);
        $vCount  = count($variantRows);
        $bCount  = (int) array_sum(array_map(fn ($s) => is_file($s) ? 1 : 0, glob($catBanDir . DIRECTORY_SEPARATOR . '*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: []));
        echo "Forever52CatalogSeeder: {$pCount} products, {$iCount} images, {$vCount} variants, {$bCount} category banners copied locally.\n";
    }

    /** Lookup banner for $slug from category-banners.json, copy local file to dest, return public URL. */
    private function resolveBanner(string $slug, array $banners, string $destDir): ?string
    {
        $entry = $banners[$slug] ?? null;
        if (!$entry) return null;
        $src = $entry['local_file'] ?? null;
        if (!$src || !is_file($src)) {
            // Fall back to the remote URL (still valid on Forever52 CDN).
            return $entry['url'] ?? null;
        }
        $ext  = strtolower(pathinfo($src, PATHINFO_EXTENSION)) ?: 'png';
        $dest = $destDir . DIRECTORY_SEPARATOR . $slug . '.' . $ext;
        copy($src, $dest);
        return '/api/uploads/categories/' . $slug . '.' . $ext;
    }

    /**
     * Route a product to a child category. Prefer the Shopify "type" field (most accurate),
     * fall back to slug/name keyword matching.
     */
    private function routeByType(string $type, string $slug, string $title): string
    {
        $t = strtolower(trim($type));
        // "Face - Blush & Bronzer" → strip the parent prefix
        if (preg_match('/(?:face|eyes|lips|nails|accessories)\s*[-–]\s*(.+)/i', $t, $m)) {
            $t = strtolower(trim($m[1]));
        }
        foreach (self::TYPE_RULES as $needle => $childSlug) {
            if (str_contains($t, $needle)) return $childSlug;
        }
        // Fallback to slug/title keyword.
        $hay = strtolower($slug . ' ' . $title);
        foreach (self::TYPE_RULES as $needle => $childSlug) {
            if (str_contains($hay, $needle)) return $childSlug;
        }
        return 'other';
    }
}
