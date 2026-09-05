<?php

namespace App\Commands;

use App\Libraries\ArabicAutoFill;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * Translate every existing English row into Arabic on first deploy.
 *
 *   php spark translate:backfill
 *
 * Idempotent — already-translated rows (matching ar_hash) are skipped.
 * Use `php spark translate:backfill --force` to re-translate everything.
 */
class TranslateBackfill extends BaseCommand
{
    protected $group       = 'Marooff';
    protected $name        = 'translate:backfill';
    protected $description = 'Translate existing English content into Arabic (idempotent).';

    public function run(array $params)
    {
        $force = in_array('--force', $params, true);
        if ($force) {
            CLI::write('Force mode: clearing ar_hash on every table…', 'yellow');
            $db = Database::connect();
            foreach (['products','categories','product_variants','combos','banners'] as $t) {
                $db->table($t)->where('1=1', null, false)->update(['ar_hash' => null]);
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

        $db = Database::connect();
        foreach ($tasks as [$table, $fields, $overrides]) {
            $ids = $db->table($table)->select('id')->get()->getResultArray();
            $count = count($ids);
            if (!$count) { CLI::write("  {$table}: 0 rows", 'dark_gray'); continue; }
            CLI::write("  {$table}: {$count} rows", 'cyan');
            $i = 0;
            foreach ($ids as $r) {
                $i++;
                $id = (int) $r['id'];
                ArabicAutoFill::run($table, $id, $fields, $overrides);
                CLI::print("    [{$i}/{$count}] id={$id}        \r");
            }
            CLI::newLine();
        }

        // Settings
        CLI::write('  settings: translating store / announcement keys', 'cyan');
        ArabicAutoFill::runSettings([
            'store_name', 'store_tagline',
            'announcement_1', 'announcement_2', 'announcement_3',
        ]);

        CLI::write('Backfill done.', 'green');
    }
}
