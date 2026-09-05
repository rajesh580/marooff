<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ArabicAutoFill;
use Config\Database;

/**
 * One-shot admin endpoints to run the Arabic backfill on production
 * (since Hostinger shared hosting can't easily run `php spark translate:backfill`).
 *
 *   POST /api/admin/translate/backfill          run incrementally
 *   POST /api/admin/translate/backfill?force=1  re-translate everything
 *
 * Each call processes ONE batch (default 8 rows) to avoid timeouts.
 * Returns { processed, remaining, done } — the admin UI / curl loop can
 * call it repeatedly until done=true.
 */
class TranslateAdmin extends BaseController
{
    private const BATCH = 8;

    public function backfill()
    {
        $force = (string) $this->request->getGet('force') === '1';
        $db = Database::connect();

        if ($force) {
            foreach (['products','categories','product_variants','combos','banners'] as $tt) {
                $db->table($tt)->where('1=1', null, false)->update(['ar_hash' => null]);
            }
            $db->table('settings')->where('key', 'ar_hash:settings')->delete();
        }

        $tasks = [
            ['products',         ['name' => 'name_ar', 'short_desc' => 'short_desc_ar', 'description' => 'description_ar', 'brand' => 'brand_ar'], []],
            ['categories',       ['name' => 'name_ar', 'description' => 'description_ar'], [
                'face' => 'الوجه', 'eyes' => 'العيون', 'lips' => 'الشفاه',
                'body' => 'الجسم', 'nails' => 'الأظافر', 'makeup kit' => 'حقيبة المكياج',
            ]],
            ['product_variants', ['shade' => 'shade_ar', 'title' => 'title_ar'], []],
            ['combos',           ['name' => 'name_ar', 'description' => 'description_ar'], []],
            ['banners',          ['title' => 'title_ar', 'subtitle' => 'subtitle_ar'], []],
        ];

        $processed  = 0;
        $remaining  = 0;
        $perTable   = [];

        foreach ($tasks as [$table, $fields, $overrides]) {
            // Rows that still need translating: ar_hash IS NULL (never done) OR doesn't
            // match the current English. Cheap version: just pick ar_hash IS NULL.
            $todoQuery = $db->table($table)->select('id')->where('ar_hash IS NULL');
            $todoCount = (clone $todoQuery)->countAllResults(false);
            $remaining += $todoCount;
            $perTable[$table] = $todoCount;

            if ($processed >= self::BATCH) continue;
            $slots = self::BATCH - $processed;
            $rows  = $todoQuery->limit($slots)->get()->getResultArray();
            foreach ($rows as $r) {
                ArabicAutoFill::run($table, (int) $r['id'], $fields, $overrides);
                $processed++;
                $remaining--;
            }
        }

        // Settings translation — cheap, do it always (only fires when hash differs).
        ArabicAutoFill::runSettings(['store_name','store_tagline','announcement_1','announcement_2','announcement_3']);

        return $this->ok([
            'processed'   => $processed,
            'remaining'   => $remaining,
            'done'        => $remaining === 0,
            'per_table'   => $perTable,
        ]);
    }
}
