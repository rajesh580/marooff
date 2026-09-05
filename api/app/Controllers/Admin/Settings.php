<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ArabicAutoFill;
use App\Models\SettingModel;

class Settings extends BaseController
{
    /** Translatable settings — these get auto-translated to <key>_ar after save. */
    private const TRANSLATABLE_KEYS = [
        'store_name', 'store_tagline',
        'announcement_1', 'announcement_2', 'announcement_3',
    ];

    public function index()
    {
        return $this->ok((new SettingModel())->all());
    }

    public function update()
    {
        $body = $this->jsonBody();
        if (empty($body) || !is_array($body)) return $this->validationError(['body' => 'JSON object required']);
        $m = new SettingModel();
        foreach ($body as $k => $v) {
            $m->put((string) $k, (string) $v);
        }
        // Auto-translate any English settings the admin edited.
        $touched = array_values(array_intersect(self::TRANSLATABLE_KEYS, array_keys($body)));
        if ($touched) ArabicAutoFill::runSettings($touched);
        return $this->ok($m->all());
    }
}
