<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProductVariantsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'product_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'sku'            => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => true],
            'title'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'shade'          => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'price_minor'    => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'sale_price_minor' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'image_url'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'is_available'   => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            'sort_order'     => ['type' => 'INT', 'null' => false, 'default' => 0],
            'created_at'     => ['type' => 'DATETIME', 'null' => false],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['product_id', 'sort_order']);
        $this->forge->addKey('sku');
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('product_variants');
    }

    public function down()
    {
        $this->forge->dropTable('product_variants', true);
    }
}
