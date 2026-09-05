<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ArabicAutoFill;
use App\Models\BannerModel;

const BANNER_AR_FIELDS = [
    'title'    => 'title_ar',
    'subtitle' => 'subtitle_ar',
];

class Banners extends BaseController
{
    public function index()
    {
        $rows = (new BannerModel())->orderBy('placement', 'ASC')->orderBy('sort_order', 'ASC')->findAll();
        return $this->ok($rows);
    }

    public function create()
    {
        $body = $this->jsonBody();
        $m = new BannerModel();
        $row = [
            'placement'  => (string) ($body['placement']  ?? 'home_hero'),
            'title'      => (string) ($body['title']      ?? ''),
            'subtitle'   => (string) ($body['subtitle']   ?? ''),
            'image_url'  => (string) ($body['image_url']  ?? ''),
            'link_url'   => (string) ($body['link_url']   ?? ''),
            'sort_order' => (int)    ($body['sort_order'] ?? 0),
            'is_active'  => (int) (bool) ($body['is_active'] ?? 1),
            'starts_at'  => $body['starts_at'] ?? null,
            'ends_at'    => $body['ends_at']   ?? null,
        ];
        if (!$row['image_url']) return $this->validationError(['image_url' => 'required']);
        $m->insert($row);
        $id = (int) $m->getInsertID();
        ArabicAutoFill::run('banners', $id, BANNER_AR_FIELDS);
        return $this->created($m->find($id));
    }

    public function update(int $id)
    {
        $m = new BannerModel();
        if (!$m->find($id)) return $this->notFound('Banner not found');
        $body = $this->jsonBody();
        if (isset($body['is_active'])) $body['is_active'] = (int) (bool) $body['is_active'];
        $m->update($id, $body);
        ArabicAutoFill::run('banners', $id, BANNER_AR_FIELDS);
        return $this->ok($m->find($id));
    }

    public function delete(int $id)
    {
        $m = new BannerModel();
        if (!$m->find($id)) return $this->notFound('Banner not found');
        $m->delete($id);
        return $this->ok(['deleted' => true]);
    }
}
