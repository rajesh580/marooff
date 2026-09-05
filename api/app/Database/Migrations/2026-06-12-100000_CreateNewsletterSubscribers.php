<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Holds storefront newsletter signups (the "Get 10% off your first order" form on the home page).
 * Each email is unique — re-submitting the same address updates `updated_at` and bumps `is_active`
 * back to 1 instead of erroring out.
 */
class CreateNewsletterSubscribers extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT',     'unsigned' => true, 'auto_increment' => true],
            'email'          => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => false],
            'source'         => ['type' => 'VARCHAR', 'constraint' => 40,  'null' => true, 'default' => 'home'],
            'language'       => ['type' => 'CHAR',    'constraint' => 2,   'null' => true],
            'ip'             => ['type' => 'VARCHAR', 'constraint' => 45,  'null' => true],
            'user_agent'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'is_active'      => ['type' => 'TINYINT', 'constraint' => 1,   'null' => false, 'default' => 1],
            'unsubscribed_at'=> ['type' => 'DATETIME','null' => true],
            'created_at'     => ['type' => 'DATETIME','null' => false],
            'updated_at'     => ['type' => 'DATETIME','null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');
        $this->forge->addKey(['is_active', 'created_at']);
        $this->forge->createTable('newsletter_subscribers', true);
    }

    public function down()
    {
        $this->forge->dropTable('newsletter_subscribers', true);
    }
}
