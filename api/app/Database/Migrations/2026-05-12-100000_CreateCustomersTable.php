<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCustomersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'              => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'email'             => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => false],
            'phone'             => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'password_hash'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'email_verified_at' => ['type' => 'DATETIME', 'null' => true],
            'last_login_at'     => ['type' => 'DATETIME', 'null' => true],
            'is_active'         => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            'created_at'        => ['type' => 'DATETIME', 'null' => false],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');
        $this->forge->createTable('customers');
    }

    public function down()
    {
        $this->forge->dropTable('customers', true);
    }
}
