<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCategoriesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'parent_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'slug'        => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => false],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => false],
            'description' => ['type' => 'TEXT', 'null' => true],
            'image_url'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'sort_order'  => ['type' => 'INT', 'null' => false, 'default' => 0],
            'is_active'   => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => false],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('parent_id');
        $this->forge->addKey(['is_active', 'sort_order']);
        $this->forge->createTable('categories');
    }

    public function down()
    {
        $this->forge->dropTable('categories', true);
    }
}
