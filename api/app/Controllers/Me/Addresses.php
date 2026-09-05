<?php

namespace App\Controllers\Me;

use App\Controllers\BaseController;
use App\Models\AddressModel;

/**
 * Customer address book.
 *
 * Each customer keeps **exactly one address pair** in the DB:
 *   - one row with `type=0` (billing)
 *   - one row with `type=1` (shipping)
 *
 * The storefront sends both halves; if the customer ticked "shipping same as
 * billing" the storefront sets `same_as_billing=true` and we mirror billing
 * into the shipping row. Either way the DB always holds the pair.
 */
class Addresses extends BaseController
{
    /** GET /me/addresses — returns the current address pair (or empty). */
    public function index()
    {
        $m   = new AddressModel();
        $pair = $m->pairForUser($this->userId());
        $billing  = $pair['billing'];
        $shipping = $pair['shipping'];
        return $this->ok([
            'has_address'     => $billing && $shipping,
            'address'         => $billing ?: $shipping,   // legacy single-object shape
            'billing'         => $billing,
            'shipping'        => $shipping,
            'same_as_billing' => $billing && $shipping ? $this->sameContent($billing, $shipping) : true,
        ]);
    }

    /**
     * POST /me/addresses — create the billing + shipping pair.
     *
     * Body:
     *   {
     *     billing:  { name, phone, emirate, area, ... },
     *     shipping: { ... }   // omitted or null when same_as_billing=true
     *     same_as_billing: true|false
     *   }
     *
     * Legacy single-payload bodies are still accepted (the same data is used for both rows).
     */
    public function create()
    {
        $m = new AddressModel();
        $uid = $this->userId();
        if ($m->hasPair($uid)) {
            return $this->fail('ADDRESS_EXISTS', 'You already have an address on file. Edit it instead.', null, 409);
        }

        [$billing, $shipping, $errors] = $this->resolvePair($this->jsonBody());
        if ($errors) return $this->validationError($errors);

        $db = \Config\Database::connect();
        $db->transStart();
        $m->where('user_id', $uid)->delete();   // clean any half-pair orphans
        $m->insert(array_merge($billing,  ['user_id' => $uid, 'type' => AddressModel::TYPE_BILLING]));
        $m->insert(array_merge($shipping, ['user_id' => $uid, 'type' => AddressModel::TYPE_SHIPPING]));
        $db->transComplete();

        return $this->created($m->pairForUser($uid));
    }

    /**
     * PUT /me/addresses/(:num) — edit the pair.
     * `:id` may be either row; we always overwrite BOTH rows of the user's pair from the new body.
     */
    public function update(int $id)
    {
        $m  = new AddressModel();
        $ex = $m->find($id);
        if (!$ex || (int) $ex['user_id'] !== $this->userId()) return $this->notFound('Address not found');

        [$billing, $shipping, $errors] = $this->resolvePair($this->jsonBody());
        if ($errors) return $this->validationError($errors);

        $uid = $this->userId();
        $pair = $m->pairForUser($uid);

        $db = \Config\Database::connect();
        $db->transStart();
        if ($pair['billing'])  $m->update($pair['billing']['id'],  $billing);
        else                   $m->insert(array_merge($billing,  ['user_id' => $uid, 'type' => AddressModel::TYPE_BILLING]));
        if ($pair['shipping']) $m->update($pair['shipping']['id'], $shipping);
        else                   $m->insert(array_merge($shipping, ['user_id' => $uid, 'type' => AddressModel::TYPE_SHIPPING]));
        $db->transComplete();

        return $this->ok($m->pairForUser($uid));
    }

    /** DELETE /me/addresses/(:num) — removes BOTH rows of the user's pair. */
    public function delete(int $id)
    {
        $m  = new AddressModel();
        $ex = $m->find($id);
        if (!$ex || (int) $ex['user_id'] !== $this->userId()) return $this->notFound('Address not found');
        $m->where('user_id', $this->userId())->delete();
        return $this->ok(['deleted' => true]);
    }

    // -- helpers ---------------------------------------------------------

    /**
     * Pull `billing` and `shipping` payloads out of a request body. Returns
     * [$billingRow, $shippingRow, $errors] — where the rows are ready to insert/update
     * (no `user_id`, no `type`) and $errors is a fields=>message array (empty on success).
     */
    private function resolvePair(array $body): array
    {
        $hasNested      = isset($body['billing']) && is_array($body['billing']);
        $sameAsBilling  = !empty($body['same_as_billing']);

        $billingRaw  = $hasNested ? (array) $body['billing']  : $body;
        $shippingRaw = $hasNested
            ? (($sameAsBilling || !isset($body['shipping']) || !is_array($body['shipping'])) ? $billingRaw : (array) $body['shipping'])
            : $billingRaw;

        $billing  = $this->fields($billingRaw);
        $shipping = $sameAsBilling ? $billing : $this->fields($shippingRaw);

        $errors = [];
        foreach ($this->validateFields($billing)  as $k => $v) $errors["billing.$k"]  = $v;
        if (!$sameAsBilling) {
            foreach ($this->validateFields($shipping) as $k => $v) $errors["shipping.$k"] = $v;
        }
        return [$billing, $shipping, $errors];
    }

    private function fields(array $body): array
    {
        return [
            'label'      => trim((string) ($body['label']     ?? 'Home')),
            'name'       => trim((string) ($body['name']      ?? '')),
            'phone'      => trim((string) ($body['phone']     ?? '')),
            'emirate'    => trim((string) ($body['emirate']   ?? '')),
            'area'       => trim((string) ($body['area']      ?? '')),
            'street'     => trim((string) ($body['street']    ?? '')) ?: null,
            'building'   => trim((string) ($body['building']  ?? '')) ?: null,
            'floor'      => trim((string) ($body['floor']     ?? '')) ?: null,
            'apartment'  => trim((string) ($body['apartment'] ?? '')) ?: null,
            'landmark'   => trim((string) ($body['landmark']  ?? '')) ?: null,
            'makani'     => trim((string) ($body['makani']    ?? '')) ?: null,
            'is_default' => 1,
        ];
    }

    private function validateFields(array $row): array
    {
        $errors = [];
        if ($row['name']    === '') $errors['name']    = 'Required';
        if ($row['phone']   === '') $errors['phone']   = 'Required';
        if ($row['emirate'] === '') $errors['emirate'] = 'Required';
        if ($row['area']    === '') $errors['area']    = 'Required';
        return $errors;
    }

    /** Compare the address-content fields between two DB rows. */
    private function sameContent(array $a, array $b): bool
    {
        foreach (['name','phone','emirate','area','street','building','floor','apartment','landmark','makani'] as $k) {
            if (($a[$k] ?? null) !== ($b[$k] ?? null)) return false;
        }
        return true;
    }

    private function userId(): int
    {
        $hdr = $this->request->getHeaderLine('X-Auth-User');
        return (int) (json_decode($hdr, true)['sub'] ?? 0);
    }
}
