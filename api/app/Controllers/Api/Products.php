<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ProductModel;
use App\Models\ProductImageModel;
use App\Models\ProductVariantModel;
use App\Models\ProductVolumeDiscountModel;
use App\Models\CategoryModel;

class Products extends BaseController
{
    private const PRODUCT_AR_MAP = [
        'name' => 'name_ar', 'short_desc' => 'short_desc_ar',
        'description' => 'description_ar', 'brand' => 'brand_ar',
    ];
    private const VARIANT_AR_MAP  = ['shade' => 'shade_ar', 'title' => 'title_ar'];
    private const CATEGORY_AR_MAP = ['name' => 'name_ar', 'description' => 'description_ar'];

    public function index()
    {
        [$page, $limit, $offset] = $this->pageParams(24, 60);
        $opts = [
            'category_slug' => $this->request->getGet('category'),
            'featured'      => $this->request->getGet('featured'),
            'new'           => $this->request->getGet('new'),
            'bestseller'    => $this->request->getGet('bestseller'),
            'q'             => $this->request->getGet('q'),
            'min_price'     => $this->request->getGet('min_price'),
            'max_price'     => $this->request->getGet('max_price'),
            'sort'          => $this->request->getGet('sort'),
        ];
        $categoryActive = !empty($opts['category_slug']);
        $sortRequested  = (string) ($opts['sort'] ?? '');

        // Storefront: when listing within a single category and no explicit sort is requested,
        // apply absolute-slot positioning (position=2 → slot 2). Otherwise use the normal
        // search() path which respects the sort param.
        if ($categoryActive && ($sortRequested === '' || $sortRequested === 'position')) {
            $opts['limit']  = 1000;
            $opts['offset'] = 0;
            $opts['sort']   = 'new';
            $r = (new ProductModel())->search($opts);
            $ordered = Categories::publicRepositionBySlot($r['items']);
            $items   = array_slice($ordered, $offset, $limit);
            $total   = $r['total'];
        } else {
            $opts['limit']  = $limit;
            $opts['offset'] = $offset;
            $r = (new ProductModel())->search($opts);
            $items = $r['items'];
            $total = $r['total'];
        }
        $meta = [
            'page'       => $page,
            'limit'      => $limit,
            'total'      => $total,
            'last_page'  => $total ? (int) ceil($total / $limit) : 1,
        ];
        $items = $this->localizeMany($items, self::PRODUCT_AR_MAP);
        return $this->ok($items, $meta);
    }

    public function show(string $slug)
    {
        $p = (new ProductModel())->findBySlug($slug);
        if (!$p || !$p['is_active']) return $this->notFound('Product not found');

        $images   = (new ProductImageModel())->forProduct((int) $p['id']);
        $variants = (new ProductVariantModel())->forProduct((int) $p['id']);
        $tiers    = (new ProductVolumeDiscountModel())->forProduct((int) $p['id']);
        $category = $p['category_id'] ? (new CategoryModel())->find((int) $p['category_id']) : null;

        return $this->ok([
            'product'          => $this->localize($p, self::PRODUCT_AR_MAP),
            'images'           => $images,
            'variants'         => $this->localizeMany($variants, self::VARIANT_AR_MAP),
            'volume_discounts' => $tiers,
            'category'         => $this->localize($category, self::CATEGORY_AR_MAP),
        ]);
    }
}
