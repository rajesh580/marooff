<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Holds a snapshot of an in-flight Stripe checkout so the order is only
 * persisted AFTER the card payment succeeds. One row per PaymentIntent.
 *
 * On a successful PI, the storefront calls /me/checkout/finalize-stripe
 * (or the webhook fires) and that consumes this row → inserts an order.
 * Abandoned card attempts simply leave a `pending` row behind that a
 * cleanup job can purge after a few hours.
 */
class CreatePendingCheckoutsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'payment_intent_id'  => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => false],
            'user_id'            => ['type' => 'INT', 'unsigned' => true,      'null' => false],
            'amount_minor'       => ['type' => 'INT', 'unsigned' => true,      'null' => false],
            'currency'           => ['type' => 'CHAR', 'constraint' => 3,      'null' => false, 'default' => 'AED'],
            'payload_json'       => ['type' => 'LONGTEXT',                     'null' => false],
            'status'             => ['type' => 'ENUM', 'constraint' => ['pending','consumed','expired'], 'default' => 'pending'],
            'created_at'         => ['type' => 'DATETIME', 'null' => false],
            'consumed_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('payment_intent_id');
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('user_id', 'customers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('pending_checkouts');

        // Prevent two finalize attempts (storefront + webhook race) from creating duplicate orders.
        if ($this->db->DBDriver === 'SQLite3') {
            $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_payment_ref ON orders(payment_ref)');
        } else {
            $this->db->query('ALTER TABLE orders ADD UNIQUE KEY uq_payment_ref (payment_ref)');
        }
    }

    public function down()
    {
        if ($this->db->DBDriver === 'SQLite3') {
            $this->db->query('DROP INDEX IF EXISTS uq_payment_ref');
        } else {
            $this->db->query('ALTER TABLE orders DROP INDEX uq_payment_ref');
        }
        $this->forge->dropTable('pending_checkouts', true);
    }
}
