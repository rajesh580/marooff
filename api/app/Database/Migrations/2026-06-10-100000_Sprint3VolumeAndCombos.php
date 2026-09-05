<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 3 additions: per-shade stock, volume discount tiers, combo bundles.
 *
 * Per-shade stock is also patchable via a one-off ALTER but this migration is the
 * canonical record. The DB upgrade SQL run on the live server matches what's here.
 */
class Sprint3VolumeAndCombos extends Migration
{
    public function up()
    {
        // ---- product_variants.stock ----
        if (! $this->db->fieldExists('stock', 'product_variants')) {
            $this->forge->addColumn('product_variants', [
                'stock' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => false,
                    'default'  => 0,
                    'after'    => 'sale_price_minor',
                ],
            ]);
        }

        // ---- product_volume_discounts ----
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'product_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'min_qty'        => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'discount_minor' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'sort_order'     => ['type' => 'INT', 'null' => false, 'default' => 0],
            'created_at'     => ['type' => 'DATETIME', 'null' => false],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['product_id', 'min_qty']);
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('product_volume_discounts', true);

        // ---- combos ----
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'slug'              => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'name'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'description'       => ['type' => 'TEXT', 'null' => true],
            'image_url'         => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'price_minor'       => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'sale_price_minor'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'currency'          => ['type' => 'CHAR', 'constraint' => 3, 'null' => false, 'default' => 'AED'],
            'stock'             => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'is_active'         => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            'sort_order'        => ['type' => 'INT', 'null' => false, 'default' => 0],
            'created_at'        => ['type' => 'DATETIME', 'null' => false],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('combos', true);

        // ---- combo_items (junction) ----
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'combo_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'product_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'variant_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'qty'          => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
            'sort_order'   => ['type' => 'INT', 'null' => false, 'default' => 0],
            'created_at'   => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['combo_id', 'sort_order']);
        $this->forge->addForeignKey('combo_id',  'combos',   'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('product_id','products', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('combo_items', true);
    }

    public function down()
    {
        $this->forge->dropTable('combo_items', true);
        $this->forge->dropTable('combos', true);
        $this->forge->dropTable('product_volume_discounts', true);
        if ($this->db->fieldExists('stock', 'product_variants')) {
            $this->forge->dropColumn('product_variants', 'stock');
        }
    }
}
