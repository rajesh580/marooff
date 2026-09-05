<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\BannerModel;
use App\Models\CategoryModel;
use App\Models\ProductModel;

/**
 * Aggregate endpoint for the storefront homepage.
 * Single round-trip: hero banners + category tiles + new arrivals + featured + bestsellers.
 */
class Home extends BaseController
{
    private const PRODUCT_AR_MAP  = [
        'name' => 'name_ar', 'short_desc' => 'short_desc_ar',
        'description' => 'description_ar', 'brand' => 'brand_ar',
    ];
    private const CATEGORY_AR_MAP = ['name' => 'name_ar', 'description' => 'description_ar'];
    private const BANNER_AR_MAP   = ['title' => 'title_ar', 'subtitle' => 'subtitle_ar'];

    public function index()
    {
        $banners    = (new BannerModel())->activeForPlacement('home_hero');
        $categories = (new CategoryModel())->activeOrdered();
        $productM   = new ProductModel();

        $newArrivals = $productM->search(['new' => 1,        'limit' => 12, 'sort' => 'new'])['items'];
        $featured    = $productM->search(['featured' => 1,   'limit' => 12, 'sort' => 'new'])['items'];
        $bestsellers = $productM->search(['bestseller' => 1, 'limit' => 12, 'sort' => 'new'])['items'];

        return $this->ok([
            'banners'      => $this->localizeMany($banners, self::BANNER_AR_MAP),
            'categories'   => $this->localizeMany($categories, self::CATEGORY_AR_MAP),
            'new_arrivals' => $this->localizeMany($newArrivals, self::PRODUCT_AR_MAP),
            'featured'     => $this->localizeMany($featured, self::PRODUCT_AR_MAP),
            'bestsellers'  => $this->localizeMany($bestsellers, self::PRODUCT_AR_MAP),
        ]);
    }
}
