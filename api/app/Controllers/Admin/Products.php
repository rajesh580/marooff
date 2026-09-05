<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ArabicAutoFill;
use App\Models\ProductModel;
use App\Models\ProductImageModel;
use App\Models\ProductVariantModel;
use App\Models\ProductVolumeDiscountModel;

const PRODUCT_AR_FIELDS = [
    'name'        => 'name_ar',
    'short_desc'  => 'short_desc_ar',
    'description' => 'description_ar',
    'brand'       => 'brand_ar',
];

const VARIANT_AR_FIELDS = [
    'shade' => 'shade_ar',
    'title' => 'title_ar',
];

class Products extends BaseController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pageParams(24, 100);
        $m = new ProductModel();
        $b = $m;

        // Category filter — id or slug (slug is converted to id via a sub-select).
        $catId = $this->request->getGet('category_id');
        if ($catId !== null && $catId !== '') {
            $b = $b->where('category_id', (int) $catId);
        } else {
            $catSlug = trim((string) $this->request->getGet('category'));
            if ($catSlug !== '') {
                $cid = $m->db->table('categories')->where('slug', $catSlug)->select('id')->get()->getRow('id');
                if ($cid) $b = $b->where('category_id', (int) $cid);
                else      $b = $b->where('1=0', null, false); // unknown slug → no results
            }
        }

        // Status filter: 'live' | 'hidden' | (omit for all)
        $status = (string) $this->request->getGet('status');
        if ($status === 'live')   $b = $b->where('is_active', 1);
        if ($status === 'hidden') $b = $b->where('is_active', 0);

        // Flag filters
        if ($this->request->getGet('featured'))   $b = $b->where('is_featured', 1);
        if ($this->request->getGet('new'))        $b = $b->where('is_new', 1);
        if ($this->request->getGet('bestseller')) $b = $b->where('is_bestseller', 1);
        if ($this->request->getGet('low_stock'))  $b = $b->where('stock <=', (int) $this->request->getGet('low_stock'));

        // Search (name / sku / brand)
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $b = $b->groupStart()->like('name', $q)->orLike('sku', $q)->orLike('brand', $q)->groupEnd();
        }

        // Sort. When a specific category is active, "position" is the natural default.
        $defaultSort = ($catId !== null && $catId !== '') ? 'position' : 'new';
        $sort = (string) ($this->request->getGet('sort') ?: $defaultSort);
        match ($sort) {
            'price_asc'  => $b->orderBy('price_minor', 'ASC'),
            'price_desc' => $b->orderBy('price_minor', 'DESC'),
            'name_asc'   => $b->orderBy('name', 'ASC'),
            'name_desc'  => $b->orderBy('name', 'DESC'),
            'stock_asc'  => $b->orderBy('stock', 'ASC'),
            'stock_desc' => $b->orderBy('stock', 'DESC'),
            'oldest'     => $b->orderBy('id', 'ASC'),
            'position'   => $b->orderBy('(CASE WHEN position > 0 THEN 0 ELSE 1 END)', 'ASC', false)
                              ->orderBy('position', 'ASC')->orderBy('id', 'DESC'),
            default      => $b->orderBy('id', 'DESC'),
        };

        $total = (clone $b)->countAllResults(false);
        $items = $b->limit($limit, $offset)->find();
        return $this->ok($items, ['page' => $page, 'limit' => $limit, 'total' => $total, 'last_page' => $total ? (int) ceil($total / $limit) : 1]);
    }

    public function show(int $id)
    {
        $m = new ProductModel();
        $row = $m->find($id);
        if (!$row) return $this->notFound('Product not found');
        $row['images']           = (new ProductImageModel())->forProduct($id);
        $row['variants']         = (new ProductVariantModel())
            ->where('product_id', $id)
            ->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')
            ->find();
        $row['volume_discounts'] = (new ProductVolumeDiscountModel())->forProduct($id);
        return $this->ok($row);
    }

    // ------------------------------------------------------------------
    //   Volume discount tiers (bulk qty discounts) CRUD
    // ------------------------------------------------------------------

    public function addVolumeDiscount(int $productId)
    {
        $pm = new ProductModel();
        if (!$pm->find($productId)) return $this->notFound('Product not found');
        $body = $this->jsonBody();
        $minQty   = max(2, (int) ($body['min_qty'] ?? 0));
        $discount = max(0, (int) ($body['discount_minor'] ?? 0));
        if ($minQty < 2)   return $this->validationError(['min_qty' => 'Minimum qty must be 2 or more']);
        if ($discount <= 0) return $this->validationError(['discount_minor' => 'Discount must be greater than zero']);
        $vm = new ProductVolumeDiscountModel();
        $row = [
            'product_id'     => $productId,
            'min_qty'        => $minQty,
            'discount_minor' => $discount,
            'sort_order'     => (int) ($body['sort_order'] ?? 0),
            'created_at'     => date('Y-m-d H:i:s'),
        ];
        if (!$vm->insert($row)) return $this->validationError($vm->errors());
        return $this->created($vm->find($vm->getInsertID()));
    }

    public function updateVolumeDiscount(int $productId, int $tierId)
    {
        $vm  = new ProductVolumeDiscountModel();
        $row = $vm->find($tierId);
        if (!$row || (int) $row['product_id'] !== $productId) return $this->notFound('Tier not found');
        $body  = $this->jsonBody();
        $patch = [];
        if (array_key_exists('min_qty',        $body)) $patch['min_qty']        = max(2, (int) $body['min_qty']);
        if (array_key_exists('discount_minor', $body)) $patch['discount_minor'] = max(0, (int) $body['discount_minor']);
        if (array_key_exists('sort_order',     $body)) $patch['sort_order']     = (int) $body['sort_order'];
        if ($patch) $vm->update($tierId, $patch);
        return $this->ok($vm->find($tierId));
    }

    public function deleteVolumeDiscount(int $productId, int $tierId)
    {
        $vm  = new ProductVolumeDiscountModel();
        $row = $vm->find($tierId);
        if (!$row || (int) $row['product_id'] !== $productId) return $this->notFound('Tier not found');
        $vm->delete($tierId);
        return $this->ok(['deleted' => true]);
    }

    // ------------------------------------------------------------------
    //   Variant (shade) CRUD
    // ------------------------------------------------------------------

    public function addVariant(int $productId)
    {
        $pm = new ProductModel();
        if (!$pm->find($productId)) return $this->notFound('Product not found');
        $body = $this->jsonBody();
        $vm = new ProductVariantModel();
        $row = [
            'product_id'       => $productId,
            'sku'              => trim((string) ($body['sku']   ?? '')) ?: null,
            'title'            => trim((string) ($body['title'] ?? ($body['shade'] ?? ''))),
            'shade'            => trim((string) ($body['shade'] ?? '')) ?: null,
            'price_minor'      => max(0, (int) ($body['price_minor'] ?? 0)),
            'sale_price_minor' => isset($body['sale_price_minor']) && $body['sale_price_minor'] !== '' && $body['sale_price_minor'] !== null
                                  ? max(0, (int) $body['sale_price_minor']) : null,
            'stock'            => max(0, (int) ($body['stock'] ?? 0)),
            'image_url'        => trim((string) ($body['image_url'] ?? '')) ?: null,
            'is_available'     => !empty($body['is_available']) ? 1 : 1,
            'sort_order'       => (int) ($body['sort_order'] ?? 0),
            'created_at'       => date('Y-m-d H:i:s'),
        ];
        if ($row['title'] === '') return $this->validationError(['title' => 'Required (use the shade name)']);
        if (!$vm->insert($row))   return $this->validationError($vm->errors());
        $vid = (int) $vm->getInsertID();
        ArabicAutoFill::run('product_variants', $vid, VARIANT_AR_FIELDS);
        return $this->created($vm->find($vid));
    }

    public function updateVariant(int $productId, int $variantId)
    {
        $vm  = new ProductVariantModel();
        $row = $vm->find($variantId);
        if (!$row || (int) $row['product_id'] !== $productId) return $this->notFound('Variant not found');
        $body = $this->jsonBody();
        $patch = [];
        foreach (['sku','title','shade','image_url'] as $k) {
            if (array_key_exists($k, $body)) {
                $v = trim((string) ($body[$k] ?? ''));
                $patch[$k] = $v === '' ? null : $v;
            }
        }
        if (array_key_exists('price_minor',      $body)) $patch['price_minor']      = max(0, (int) $body['price_minor']);
        if (array_key_exists('sale_price_minor', $body)) $patch['sale_price_minor'] = $body['sale_price_minor'] === '' || $body['sale_price_minor'] === null
                                                                                       ? null : max(0, (int) $body['sale_price_minor']);
        if (array_key_exists('stock',        $body)) $patch['stock']        = max(0, (int) $body['stock']);
        if (array_key_exists('is_available', $body)) $patch['is_available'] = $body['is_available'] ? 1 : 0;
        if (array_key_exists('sort_order',   $body)) $patch['sort_order']   = (int) $body['sort_order'];
        if ($patch) $vm->update($variantId, $patch);
        ArabicAutoFill::run('product_variants', $variantId, VARIANT_AR_FIELDS);
        return $this->ok($vm->find($variantId));
    }

    public function deleteVariant(int $productId, int $variantId)
    {
        $vm  = new ProductVariantModel();
        $row = $vm->find($variantId);
        if (!$row || (int) $row['product_id'] !== $productId) return $this->notFound('Variant not found');
        $vm->delete($variantId);
        return $this->ok(['deleted' => true]);
    }

    public function create()
    {
        $body = $this->prep($this->jsonBody());
        $m = new ProductModel();
        if (!empty($body['slug']) && $m->findBySlug((string) $body['slug'])) {
            return $this->validationError(['slug' => 'A product with this slug already exists']);
        }
        try {
            if (!$m->insert($body)) return $this->validationError($m->errors());
            $id = (int) $m->getInsertID();
            ArabicAutoFill::run('products', $id, PRODUCT_AR_FIELDS);
            return $this->created($m->find($id));
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'duplicate') !== false || stripos($e->getMessage(), '1062') !== false) {
                return $this->validationError(['slug' => 'A product with this slug already exists']);
            }
            return $this->serverError($e->getMessage());
        }
    }

    public function update(int $id)
    {
        $m = new ProductModel();
        if (!$m->find($id)) return $this->notFound('Product not found');
        $body = $this->prep($this->jsonBody(), false);
        if (!empty($body['slug'])) {
            $existing = $m->where('slug', (string) $body['slug'])->where('id !=', $id)->first();
            if ($existing) {
                return $this->validationError(['slug' => 'A product with this slug already exists']);
            }
        }
        try {
            if (!$m->update($id, $body)) return $this->validationError($m->errors());
            ArabicAutoFill::run('products', $id, PRODUCT_AR_FIELDS);
            return $this->ok($m->find($id));
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'duplicate') !== false || stripos($e->getMessage(), '1062') !== false) {
                return $this->validationError(['slug' => 'A product with this slug already exists']);
            }
            return $this->serverError($e->getMessage());
        }
    }

    public function delete(int $id)
    {
        $m = new ProductModel();
        if (!$m->find($id)) return $this->notFound('Product not found');
        $m->delete($id);
        return $this->ok(['deleted' => true]);
    }

    public function addImage(int $productId)
    {
        $m = new ProductModel();
        if (!$m->find($productId)) return $this->notFound('Product not found');
        $body = $this->jsonBody();
        $url  = (string) ($body['url'] ?? '');
        if (!$url) return $this->validationError(['url' => 'required']);
        $mediaType = (string) ($body['media_type'] ?? 'image');
        if (!in_array($mediaType, ['image', 'video'], true)) $mediaType = 'image';
        $im = new ProductImageModel();
        $im->insert([
            'product_id' => $productId,
            'url'        => $url,
            'media_type' => $mediaType,
            'poster_url' => (string) ($body['poster_url'] ?? '') ?: null,
            'alt'        => (string) ($body['alt'] ?? ''),
            'sort_order' => (int) ($body['sort_order'] ?? 0),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->created($im->find($im->getInsertID()));
    }

    public function deleteImage(int $productId, int $imageId)
    {
        $im = new ProductImageModel();
        $row = $im->find($imageId);
        if (!$row || (int) $row['product_id'] !== $productId) return $this->notFound('Image not found');
        $im->delete($imageId);
        return $this->ok(['deleted' => true]);
    }

    /** PUT /admin/products/:id/images/:imageId — currently only updates sort_order or alt text. */
    public function updateImage(int $productId, int $imageId)
    {
        $im  = new ProductImageModel();
        $row = $im->find($imageId);
        if (!$row || (int) $row['product_id'] !== $productId) return $this->notFound('Image not found');
        $body  = $this->jsonBody();
        $patch = [];
        if (isset($body['sort_order'])) $patch['sort_order'] = (int) $body['sort_order'];
        if (isset($body['alt']))        $patch['alt']        = (string) $body['alt'];
        if ($patch) $im->update($imageId, $patch);
        return $this->ok($im->find($imageId));
    }

    private function prep(array $body, bool $create = true): array
    {
        if (isset($body['slug']))     $body['slug'] = $this->slugify((string) $body['slug']);
        if (isset($body['name']) && empty($body['slug'])) $body['slug'] = $this->slugify((string) $body['name']);
        if (isset($body['price_minor'])) $body['price_minor'] = max(0, (int) $body['price_minor']);
        if (isset($body['sale_price_minor'])) $body['sale_price_minor'] = max(0, (int) $body['sale_price_minor']);
        if (isset($body['stock']))    $body['stock']    = max(0, (int) $body['stock']);
        if (isset($body['position'])) $body['position'] = max(0, (int) $body['position']);
        foreach (['is_active', 'is_featured', 'is_new', 'is_bestseller'] as $f) {
            if (isset($body[$f])) $body[$f] = (int) (bool) $body[$f];
        }
        if ($create && !isset($body['currency'])) $body['currency'] = 'AED';
        return $body;
    }

    private function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
        return trim($s, '-');
    }
}
