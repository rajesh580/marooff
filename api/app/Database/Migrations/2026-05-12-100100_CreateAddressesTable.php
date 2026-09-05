<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAddressesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'label'      => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'Home'],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'phone'      => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => false],
            'emirate'    => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => false],
            'area'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'street'     => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'building'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'floor'      => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'apartment'  => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'landmark'   => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'makani'     => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'is_default' => ['type' => 'TINYINT', 'constraint' => 1,   'null' => false, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('user_id', 'customers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('addresses');
    }

    public function down()
    {
        $this->forge->dropTable('addresses', true);
    }
}
