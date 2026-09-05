<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOrdersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'order_number'      => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => false],
            'user_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'status'            => ['type' => 'ENUM', 'constraint' => ['placed', 'confirmed', 'shipped', 'delivered', 'cancelled', 'refunded'], 'null' => false, 'default' => 'placed'],
            'subtotal_minor'    => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'discount_minor'    => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'vat_minor'         => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'shipping_fee_minor'=> ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'cod_fee_minor'     => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'grand_total_minor' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'currency'          => ['type' => 'CHAR', 'constraint' => 3, 'null' => false, 'default' => 'AED'],
            'payment_method'    => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'cod'],
            'payment_status'    => ['type' => 'ENUM', 'constraint' => ['pending', 'paid', 'failed', 'refunded'], 'null' => false, 'default' => 'pending'],
            'payment_ref'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'customer_name'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'customer_email'    => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => false],
            'customer_phone'    => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => false],
            'notes'             => ['type' => 'TEXT', 'null' => true],
            'cancelled_reason'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'placed_at'         => ['type' => 'DATETIME', 'null' => false],
            'confirmed_at'      => ['type' => 'DATETIME', 'null' => true],
            'shipped_at'        => ['type' => 'DATETIME', 'null' => true],
            'delivered_at'      => ['type' => 'DATETIME', 'null' => true],
            'cancelled_at'      => ['type' => 'DATETIME', 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => false],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('order_number');
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('user_id', 'customers', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('orders');
    }

    public function down()
    {
        $this->forge->dropTable('orders', true);
    }
}
