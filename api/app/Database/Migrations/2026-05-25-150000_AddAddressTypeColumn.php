<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Each customer keeps two address rows: one billing (type=0) and one shipping (type=1).
 * The storefront fills a single form which mirrors into both rows. This migration only
 * adds the discriminator column; fresh installs can rely on the application layer to
 * insert the pair.
 */
class AddAddressTypeColumn extends Migration
{
    public function up()
    {
        $this->forge->addColumn('addresses', [
            'type' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'comment'    => '0=billing, 1=shipping',
                'after'      => 'label',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('addresses', 'type');
    }
}
