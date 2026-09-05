<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ArabicAutoFill;
use App\Models\ComboModel;
use App\Models\ComboItemModel;

const COMBO_AR_FIELDS = [
    'name'        => 'name_ar',
    'description' => 'description_ar',
];

class Combos extends BaseController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pageParams(24, 100);
        $m = new ComboModel();
        $b = $m;
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') $b = $b->groupStart()->like('name', $q)->orLike('slug', $q)->groupEnd();
        $b = $b->orderBy('id', 'DESC');
        $total = (clone $b)->countAllResults(false);
        $items = $b->limit($limit, $offset)->find();
        return $this->ok($items, ['page' => $page, 'limit' => $limit, 'total' => $total, 'last_page' => $total ? (int) ceil($total / $limit) : 1]);
    }

    public function show(int $id)
    {
        $m = new ComboModel();
        $row = $m->find($id);
        if (!$row) return $this->notFound('Combo not found');
        $row['items'] = (new ComboItemModel())->hydrate((new ComboItemModel())->forCombo($id));
        return $this->ok($row);
    }

    public function create()
    {
        $body = $this->prep($this->jsonBody());
        $m = new ComboModel();
        if (!empty($body['slug']) && $m->findBySlug((string) $body['slug'])) {
            return $this->validationError(['slug' => 'A combo with this slug already exists']);
        }
        try {
            if (!$m->insert($body)) return $this->validationError($m->errors());
            $id = (int) $m->getInsertID();
            $this->syncItems($id, $this->jsonBody()['items'] ?? []);
            ArabicAutoFill::run('combos', $id, COMBO_AR_FIELDS);
            return $this->created($this->showRow($id));
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'duplicate') !== false || stripos($e->getMessage(), '1062') !== false) {
                return $this->validationError(['slug' => 'A combo with this slug already exists']);
            }
            return $this->serverError($e->getMessage());
        }
    }

    public function update(int $id)
    {
        $m = new ComboModel();
        if (!$m->find($id)) return $this->notFound('Combo not found');
        $body = $this->prep($this->jsonBody(), false);
        if (!empty($body['slug'])) {
            $duplicate = $m->where('slug', (string) $body['slug'])->where('id !=', $id)->first();
            if ($duplicate) {
                return $this->validationError(['slug' => 'A combo with this slug already exists']);
            }
        }
        try {
            if (!$m->update($id, $body)) return $this->validationError($m->errors());
            if (array_key_exists('items', $this->jsonBody())) {
                $this->syncItems($id, $this->jsonBody()['items'] ?? []);
            }
            ArabicAutoFill::run('combos', $id, COMBO_AR_FIELDS);
            return $this->ok($this->showRow($id));
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'duplicate') !== false || stripos($e->getMessage(), '1062') !== false) {
                return $this->validationError(['slug' => 'A combo with this slug already exists']);
            }
            return $this->serverError($e->getMessage());
        }
    }

    public function delete(int $id)
    {
        $m = new ComboModel();
        if (!$m->find($id)) return $this->notFound('Combo not found');
        $m->delete($id);
        return $this->ok(['deleted' => true]);
    }

    private function showRow(int $id): array
    {
        $row = (new ComboModel())->find($id);
        $row['items'] = (new ComboItemModel())->hydrate((new ComboItemModel())->forCombo($id));
        return $row;
    }

    /** Replace all items for a combo with the new list. */
    private function syncItems(int $comboId, array $items): void
    {
        $cim = new ComboItemModel();
        $cim->where('combo_id', $comboId)->delete();
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($items as $i => $it) {
            $pid = (int) ($it['product_id'] ?? 0);
            if (!$pid) continue;
            $rows[] = [
                'combo_id'   => $comboId,
                'product_id' => $pid,
                'variant_id' => !empty($it['variant_id']) ? (int) $it['variant_id'] : null,
                'qty'        => max(1, (int) ($it['qty'] ?? 1)),
                'sort_order' => (int) ($it['sort_order'] ?? $i),
                'created_at' => $now,
            ];
        }
        if ($rows) $cim->insertBatch($rows);
    }

    private function prep(array $body, bool $create = true): array
    {
        if (isset($body['slug']))     $body['slug'] = $this->slugify((string) $body['slug']);
        if (isset($body['name']) && empty($body['slug'])) $body['slug'] = $this->slugify((string) $body['name']);
        if (isset($body['price_minor']))      $body['price_minor']      = max(0, (int) $body['price_minor']);
        if (isset($body['sale_price_minor'])) $body['sale_price_minor'] = $body['sale_price_minor'] === '' || $body['sale_price_minor'] === null
                                                                            ? null : max(0, (int) $body['sale_price_minor']);
        if (isset($body['stock']))     $body['stock']     = max(0, (int) $body['stock']);
        if (isset($body['is_active'])) $body['is_active'] = (int) (bool) $body['is_active'];
        if ($create && empty($body['currency'])) $body['currency'] = 'AED';
        // Strip non-allowed fields so 'items' doesn't reach insert/update.
        unset($body['items']);
        return $body;
    }

    private function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
        return trim($s, '-');
    }
}
