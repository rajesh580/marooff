<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ArabicAutoFill;
use App\Models\CategoryModel;

const CATEGORY_AR_FIELDS = [
    'name'        => 'name_ar',
    'description' => 'description_ar',
];

// Hand-curated overrides for short, ambiguous category names where MyMemory
// would otherwise pick the wrong sense. Keys must be lowercase.
const CATEGORY_AR_OVERRIDES = [
    'face'       => 'الوجه',
    'eyes'       => 'العيون',
    'lips'       => 'الشفاه',
    'body'       => 'الجسم',
    'nails'      => 'الأظافر',
    'makeup kit' => 'حقيبة المكياج',
];

class Categories extends BaseController
{
    public function index()
    {
        $rows = (new CategoryModel())->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')->findAll();
        return $this->ok($rows);
    }

    public function show(int $id)
    {
        $row = (new CategoryModel())->find($id);
        if (!$row) return $this->notFound('Category not found');
        return $this->ok($row);
    }

    public function create()
    {
        $body = $this->jsonBody();
        $body['slug']      = $this->slugify((string) ($body['slug'] ?? $body['name'] ?? ''));
        $body['sort_order']= (int) ($body['sort_order'] ?? 0);
        $body['is_active'] = isset($body['is_active']) ? (int) (bool) $body['is_active'] : 1;

        $m = new CategoryModel();
        if (!empty($body['slug']) && $m->findBySlug((string) $body['slug'])) {
            return $this->validationError(['slug' => 'A category with this slug already exists']);
        }
        try {
            if (!$m->insert($body)) {
                return $this->validationError($m->errors());
            }
            $id = (int) $m->getInsertID();
            ArabicAutoFill::run('categories', $id, CATEGORY_AR_FIELDS, CATEGORY_AR_OVERRIDES);
            try { cache()->clean(); } catch (\Throwable $e) {}
            return $this->created($m->find($id));
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'duplicate') !== false || stripos($e->getMessage(), '1062') !== false) {
                return $this->validationError(['slug' => 'A category with this slug already exists']);
            }
            return $this->serverError($e->getMessage());
        }
    }

    public function update(int $id)
    {
        $m = new CategoryModel();
        $existing = $m->find($id);
        if (!$existing) return $this->notFound('Category not found');

        $body = $this->jsonBody();
        if (isset($body['slug'])) $body['slug'] = $this->slugify((string) $body['slug']);
        if (isset($body['is_active'])) $body['is_active'] = (int) (bool) $body['is_active'];

        if (!empty($body['slug'])) {
            $duplicate = $m->where('slug', (string) $body['slug'])->where('id !=', $id)->first();
            if ($duplicate) {
                return $this->validationError(['slug' => 'A category with this slug already exists']);
            }
        }

        try {
            if (!$m->update($id, $body)) return $this->validationError($m->errors());
            ArabicAutoFill::run('categories', $id, CATEGORY_AR_FIELDS, CATEGORY_AR_OVERRIDES);
            try { cache()->clean(); } catch (\Throwable $e) {}
            return $this->ok($m->find($id));
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'duplicate') !== false || stripos($e->getMessage(), '1062') !== false) {
                return $this->validationError(['slug' => 'A category with this slug already exists']);
            }
            return $this->serverError($e->getMessage());
        }
    }

    public function delete(int $id)
    {
        $m = new CategoryModel();
        if (!$m->find($id)) return $this->notFound('Category not found');
        $m->delete($id);
        try { cache()->clean(); } catch (\Throwable $e) {}
        return $this->ok(['deleted' => true]);
    }

    private function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
        $s = trim($s, '-');
        return $s;
    }
}
