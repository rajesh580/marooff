<?php

namespace App\Models;

use CodeIgniter\Model;

class ComboItemModel extends Model
{
    protected $table         = 'combo_items';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    // Don't let CI4 auto-touch timestamps — `combo_items` only has `created_at`
    // (no `updated_at` column). We fill `created_at` ourselves in Combos::syncItems.
    protected $useTimestamps = false;

    protected $allowedFields = ['combo_id', 'product_id', 'variant_id', 'qty', 'sort_order', 'created_at'];

    /** Return items for one combo, ordered. */
    public function forCombo(int $comboId): array
    {
        return $this->where('combo_id', $comboId)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * Hydrate the items list with product snapshots — name, image, sku — so the storefront can render
     * "this combo contains:" without a second round-trip.
     */
    public function hydrate(array $items): array
    {
        if (!$items) return [];
        $pids = array_unique(array_map(fn($i) => (int) $i['product_id'], $items));
        $products = (new ProductModel())->whereIn('id', $pids)->findAll();
        $byId = [];
        foreach ($products as $p) $byId[(int) $p['id']] = $p;
        $vids = array_values(array_filter(array_map(fn($i) => (int) ($i['variant_id'] ?? 0), $items)));
        $vByVid = [];
        if ($vids) {
            $vRows = (new ProductVariantModel())->whereIn('id', $vids)->findAll();
            foreach ($vRows as $v) $vByVid[(int) $v['id']] = $v;
        }
        $out = [];
        foreach ($items as $i) {
            $p = $byId[(int) $i['product_id']] ?? null;
            $v = $i['variant_id'] ? ($vByVid[(int) $i['variant_id']] ?? null) : null;
            $out[] = [
                'id'         => (int) $i['id'],
                'product_id' => (int) $i['product_id'],
                'variant_id' => $i['variant_id'] ? (int) $i['variant_id'] : null,
                'qty'        => (int) $i['qty'],
                'sort_order' => (int) $i['sort_order'],
                'name'       => $p['name']           ?? null,
                'slug'       => $p['slug']           ?? null,
                'image_url'  => $v['image_url'] ?? ($p['main_image_url'] ?? null),
                'sku'        => $v['sku']       ?? ($p['sku']            ?? null),
                'shade'      => $v['shade']     ?? null,
            ];
        }
        return $out;
    }
}
