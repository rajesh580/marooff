<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSettingsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'key'        => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'value'      => ['type' => 'TEXT', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('key');
        $this->forge->createTable('settings');

        // Seed minimal defaults
        $this->db->table('settings')->insertBatch([
            ['key' => 'store_name',     'value' => 'Marooff',                                   'updated_at' => date('Y-m-d H:i:s')],
            ['key' => 'store_tagline',  'value' => 'Premium cosmetics, made for everyday glam', 'updated_at' => date('Y-m-d H:i:s')],
            ['key' => 'support_email',  'value' => 'fakhreecosmetics@gmail.com',                'updated_at' => date('Y-m-d H:i:s')],
            ['key' => 'support_phone',  'value' => '+971 55 3978656',                           'updated_at' => date('Y-m-d H:i:s')],
            ['key' => 'support_phone2', 'value' => '+971 4 2268786',                            'updated_at' => date('Y-m-d H:i:s')],
            ['key' => 'address',        'value' => 'P.O. Box 6553, Deira, Dubai, UAE',          'updated_at' => date('Y-m-d H:i:s')],
            ['key' => 'currency',       'value' => 'AED',                                       'updated_at' => date('Y-m-d H:i:s')],
            ['key' => 'vat_pct',        'value' => '5',                                         'updated_at' => date('Y-m-d H:i:s')],
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('settings', true);
    }
}
