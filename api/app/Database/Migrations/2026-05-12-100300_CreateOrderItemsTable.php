<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOrderItemsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'order_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'product_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'variant_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'name_snapshot'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'sku_snapshot'     => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => true],
            'shade_snapshot'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'image_snapshot'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'qty'              => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
            'unit_price_minor' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'line_total_minor' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'created_at'       => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('order_id');
        $this->forge->addForeignKey('order_id', 'orders', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('order_items');
    }

    public function down()
    {
        $this->forge->dropTable('order_items', true);
    }
}
