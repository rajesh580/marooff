<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProductsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'category_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'slug'           => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => false],
            'sku'            => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => true],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'brand'          => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'short_desc'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'description'    => ['type' => 'TEXT', 'null' => true],
            // Price in minor units (fils). 99.50 AED = 9950
            'price_minor'    => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'sale_price_minor' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'currency'       => ['type' => 'CHAR', 'constraint' => 3, 'null' => false, 'default' => 'AED'],
            'stock'          => ['type' => 'INT', 'null' => false, 'default' => 0],
            'is_active'      => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            'is_featured'    => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'is_new'         => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'is_bestseller'  => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'main_image_url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => false],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('category_id');
        $this->forge->addKey('sku');
        $this->forge->addKey(['is_active', 'is_featured']);
        $this->forge->addKey(['is_active', 'is_new']);
        $this->forge->addKey(['is_active', 'is_bestseller']);
        $this->forge->createTable('products');
    }

    public function down()
    {
        $this->forge->dropTable('products', true);
    }
}
