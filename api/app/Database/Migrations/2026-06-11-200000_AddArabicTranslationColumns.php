<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds sibling `*_ar` columns next to every customer-facing English text field,
 * plus an `ar_hash` per row that records the MD5 of the English source when last
 * translated. When the English changes, the hash differs → trigger a re-translate.
 *
 * Additive only. Existing data is untouched. Storefront keeps falling back to
 * English until the backfill / first save populates the Arabic columns.
 */
class AddArabicTranslationColumns extends Migration
{
    public function up()
    {
        // ---- products -----------------------------------------------------
        $this->addCols('products', [
            'name_ar'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'name'],
            'short_desc_ar'  => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'short_desc'],
            'description_ar' => ['type' => 'TEXT',                          'null' => true, 'after' => 'description'],
            'brand_ar'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'brand'],
            'ar_hash'        => ['type' => 'VARCHAR', 'constraint' => 40,  'null' => true, 'after' => 'main_image_url'],
        ]);

        // ---- categories ---------------------------------------------------
        $this->addCols('categories', [
            'name_ar'        => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'after' => 'name'],
            'description_ar' => ['type' => 'TEXT',                          'null' => true, 'after' => 'description'],
            'ar_hash'        => ['type' => 'VARCHAR', 'constraint' => 40,  'null' => true, 'after' => 'image_url'],
        ]);

        // ---- product_variants --------------------------------------------
        $this->addCols('product_variants', [
            'shade_ar' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'shade'],
            'title_ar' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'title'],
            'ar_hash'  => ['type' => 'VARCHAR', 'constraint' => 40,  'null' => true, 'after' => 'image_url'],
        ]);

        // ---- combos -------------------------------------------------------
        $this->addCols('combos', [
            'name_ar'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'name'],
            'description_ar' => ['type' => 'TEXT',                          'null' => true, 'after' => 'description'],
            'ar_hash'        => ['type' => 'VARCHAR', 'constraint' => 40,  'null' => true, 'after' => 'image_url'],
        ]);

        // ---- banners ------------------------------------------------------
        $this->addCols('banners', [
            'title_ar'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'title'],
            'subtitle_ar' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'subtitle'],
            'ar_hash'     => ['type' => 'VARCHAR', 'constraint' => 40,  'null' => true, 'after' => 'image_url'],
        ]);

        // ---- settings: seed translator kill switch + Arabic value rows ----
        $db   = $this->db;
        $rows = [
            ['key' => 'translator_enabled',    'value' => '1'],
            ['key' => 'store_name_ar',         'value' => ''],
            ['key' => 'store_tagline_ar',      'value' => ''],
            ['key' => 'announcement_1_ar',     'value' => ''],
            ['key' => 'announcement_2_ar',     'value' => ''],
            ['key' => 'announcement_3_ar',     'value' => ''],
        ];
        foreach ($rows as $r) {
            $exists = $db->table('settings')->where('key', $r['key'])->countAllResults() > 0;
            if (!$exists) $db->table('settings')->insert($r);
        }
    }

    public function down()
    {
        foreach (['name_ar','short_desc_ar','description_ar','brand_ar','ar_hash'] as $c) {
            if ($this->db->fieldExists($c, 'products')) $this->forge->dropColumn('products', $c);
        }
        foreach (['name_ar','description_ar','ar_hash'] as $c) {
            if ($this->db->fieldExists($c, 'categories')) $this->forge->dropColumn('categories', $c);
        }
        foreach (['shade_ar','title_ar','ar_hash'] as $c) {
            if ($this->db->fieldExists($c, 'product_variants')) $this->forge->dropColumn('product_variants', $c);
        }
        foreach (['name_ar','description_ar','ar_hash'] as $c) {
            if ($this->db->fieldExists($c, 'combos')) $this->forge->dropColumn('combos', $c);
        }
        foreach (['title_ar','subtitle_ar','ar_hash'] as $c) {
            if ($this->db->fieldExists($c, 'banners')) $this->forge->dropColumn('banners', $c);
        }
        $this->db->table('settings')->whereIn('key', [
            'translator_enabled','store_name_ar','store_tagline_ar',
            'announcement_1_ar','announcement_2_ar','announcement_3_ar',
        ])->delete();
    }

    private function addCols(string $table, array $cols): void
    {
        $toAdd = [];
        foreach ($cols as $name => $spec) {
            if (! $this->db->fieldExists($name, $table)) $toAdd[$name] = $spec;
        }
        if ($toAdd) $this->forge->addColumn($table, $toAdd);
    }
}
