<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBannersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'placement'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false, 'default' => 'home_hero'],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'subtitle'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'image_url'  => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => false],
            'link_url'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'sort_order' => ['type' => 'INT', 'null' => false, 'default' => 0],
            'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            'starts_at'  => ['type' => 'DATETIME', 'null' => true],
            'ends_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['placement', 'is_active', 'sort_order']);
        $this->forge->createTable('banners');
    }

    public function down()
    {
        $this->forge->dropTable('banners', true);
    }
}
