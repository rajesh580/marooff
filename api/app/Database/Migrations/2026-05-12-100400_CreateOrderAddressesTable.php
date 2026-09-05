<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOrderAddressesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'order_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'type'         => ['type' => 'ENUM', 'constraint' => ['ship', 'bill'], 'null' => false, 'default' => 'ship'],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'phone'        => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => false],
            'emirate'      => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => false],
            'area'         => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'street'       => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'building'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'floor'        => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'apartment'    => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'landmark'     => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'makani'       => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['order_id', 'type']);
        $this->forge->addForeignKey('order_id', 'orders', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('order_addresses');
    }

    public function down()
    {
        $this->forge->dropTable('order_addresses', true);
    }
}
