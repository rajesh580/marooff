<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAdminUsersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'email'         => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => false],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'role'          => ['type' => 'VARCHAR', 'constraint' => 16,  'null' => false, 'default' => 'admin'],
            'is_active'     => ['type' => 'TINYINT', 'constraint' => 1,   'null' => false, 'default' => 1],
            'last_login_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => false],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');
        $this->forge->createTable('admin_users');

        // Seed one default admin: email=admin@marooff.ae, password=marooff@123
        $this->db->table('admin_users')->insert([
            'name'          => 'Marooff Admin',
            'email'         => 'admin@marooff.ae',
            'password_hash' => password_hash('marooff@123', PASSWORD_BCRYPT),
            'role'          => 'admin',
            'is_active'     => 1,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('admin_users', true);
    }
}
