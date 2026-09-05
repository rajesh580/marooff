<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\SettingModel;

class Settings extends BaseController
{
    /**
     * Public subset of settings — safe for the storefront to read.
     */
    public function publicView()
    {
        $all = (new SettingModel())->all();
        $publicKeys = [
            'store_name', 'store_tagline',
            'support_email', 'support_phone', 'support_phone2', 'address', 'currency',
            'announcement_1', 'announcement_2', 'announcement_3',
            // shipping rules — surfaced so the storefront can display "Free over AED X"
            'shipping_free_above_minor', 'shipping_flat_fee_minor',
        ];
        // Keys whose value gets swapped to Arabic when ?lang=ar (and the Arabic row is non-empty).
        $translatableKeys = ['store_name', 'store_tagline', 'announcement_1', 'announcement_2', 'announcement_3'];
        $isArabic = $this->lang() === 'ar';

        // Pre-compute the AED values to use for placeholder substitution.
        $freeAboveAed = (int) round(((int) ($all['shipping_free_above_minor'] ?? 0)) / 100);
        $flatFeeAed   = (int) round(((int) ($all['shipping_flat_fee_minor']   ?? 0)) / 100);

        $out = [];
        foreach ($publicKeys as $k) {
            $value = $all[$k] ?? null;
            if ($isArabic && in_array($k, $translatableKeys, true)) {
                $arVal = $all[$k . '_ar'] ?? null;
                if (is_string($arVal) && trim($arVal) !== '') $value = $arVal;
            }
            // Substitute {free_above} / {flat_fee} placeholders in the announcement strings
            // so admin only needs to update the threshold in one place and the text follows.
            if (is_string($value) && in_array($k, ['announcement_1', 'announcement_2', 'announcement_3'], true)) {
                $value = str_replace(
                    ['{free_above}', '{flat_fee}'],
                    [(string) $freeAboveAed, (string) $flatFeeAed],
                    $value
                );
            }
            $out[$k] = $value;
        }
        return $this->ok($out);
    }
}
