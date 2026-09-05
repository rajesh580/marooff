<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CouponModel;

class Coupons extends BaseController
{
    public function index()
    {
        $m = new CouponModel();
        $rows = $m->orderBy('id', 'DESC')->find();
        return $this->ok($rows);
    }

    public function show(int $id)
    {
        $m = new CouponModel();
        $row = $m->find($id);
        if (!$row) return $this->notFound('Coupon not found');
        return $this->ok($row);
    }

    public function create()
    {
        $body = $this->prep($this->jsonBody(), true);
        $m = new CouponModel();
        if (!$m->insert($body)) return $this->validationError($m->errors());
        return $this->created($m->find($m->getInsertID()));
    }

    public function update(int $id)
    {
        $m = new CouponModel();
        if (!$m->find($id)) return $this->notFound('Coupon not found');
        $body = $this->prep($this->jsonBody(), false);
        $m->update($id, $body);
        return $this->ok($m->find($id));
    }

    public function delete(int $id)
    {
        $m = new CouponModel();
        if (!$m->find($id)) return $this->notFound('Coupon not found');
        $m->delete($id);
        return $this->ok(['deleted' => true]);
    }

    private function prep(array $body, bool $create): array
    {
        $out = [];
        if (isset($body['code']))           $out['code']            = strtoupper(trim((string) $body['code']));
        if (isset($body['type']))           $out['type']            = in_array($body['type'], ['percent','fixed'], true) ? $body['type'] : 'percent';
        if (isset($body['value_minor']))    $out['value_minor']     = max(0, (int) $body['value_minor']);
        if (isset($body['min_order_minor'])) $out['min_order_minor'] = max(0, (int) $body['min_order_minor']);
        if (array_key_exists('max_uses',  $body)) $out['max_uses']  = $body['max_uses']  ? max(0, (int) $body['max_uses'])  : null;
        if (array_key_exists('starts_at', $body)) $out['starts_at'] = $body['starts_at'] ?: null;
        if (array_key_exists('ends_at',   $body)) $out['ends_at']   = $body['ends_at']   ?: null;
        if (isset($body['is_active']))      $out['is_active']      = $body['is_active'] ? 1 : 0;
        if (array_key_exists('notes', $body)) $out['notes']        = (string) ($body['notes'] ?? '') ?: null;
        return $out;
    }
}
