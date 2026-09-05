<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ComboModel;
use App\Models\ComboItemModel;

class Combos extends BaseController
{
    private const COMBO_AR_MAP = ['name' => 'name_ar', 'description' => 'description_ar'];

    public function index()
    {
        [$page, $limit, $offset] = $this->pageParams(24, 60);
        $m = new ComboModel();
        $rows = $m->listActive($limit, $offset);
        $total = $m->where('is_active', 1)->countAllResults();
        return $this->ok($this->localizeMany($rows, self::COMBO_AR_MAP), [
            'page' => $page, 'limit' => $limit, 'total' => $total,
            'last_page' => $total ? (int) ceil($total / $limit) : 1,
        ]);
    }

    public function show(string $slug)
    {
        $m = new ComboModel();
        $row = $m->findBySlug($slug);
        if (!$row || !$row['is_active']) return $this->notFound('Combo not found');
        $row['items'] = (new ComboItemModel())->hydrate((new ComboItemModel())->forCombo((int) $row['id']));
        // Combo items embed product snapshots; localize those names too when lang=ar.
        if ($this->lang() === 'ar') {
            foreach ($row['items'] as &$it) {
                if (!empty($it['product_id'])) {
                    $pRow = \Config\Database::connect()->table('products')->where('id', (int) $it['product_id'])->get()->getRowArray();
                    if ($pRow) {
                        $it['name'] = (is_string($pRow['name_ar'] ?? null) && trim($pRow['name_ar']) !== '')
                            ? $pRow['name_ar'] : $it['name'];
                    }
                }
            }
            unset($it);
        }
        return $this->ok($this->localize($row, self::COMBO_AR_MAP));
    }
}
