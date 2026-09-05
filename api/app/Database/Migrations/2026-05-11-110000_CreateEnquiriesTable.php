<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateEnquiriesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'email'        => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => false],
            'phone'        => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'subject'      => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => true],
            'message'      => ['type' => 'TEXT',                          'null' => false],
            'source_page'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'user_agent'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'ip'           => ['type' => 'VARCHAR', 'constraint' => 45,  'null' => true],
            'status'       => ['type' => 'ENUM', 'constraint' => ['new', 'read', 'responded', 'archived'], 'null' => false, 'default' => 'new'],
            'admin_note'   => ['type' => 'TEXT',                          'null' => true],
            'responded_by' => ['type' => 'INT', 'unsigned' => true,       'null' => true],
            'responded_at' => ['type' => 'DATETIME',                      'null' => true],
            'created_at'   => ['type' => 'DATETIME',                      'null' => false],
            'updated_at'   => ['type' => 'DATETIME',                      'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->addKey('email');
        $this->forge->createTable('enquiries');
    }

    public function down()
    {
        $this->forge->dropTable('enquiries', true);
    }
}
