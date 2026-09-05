<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates `coupons`, `coupon_uses`, and `order_shipping_events` tables,
 * and adds missing coupon and Jeebly courier tracking columns to `orders`.
 */
class AddCouponsAndJeeblyTracking extends Migration
{
    public function up()
    {
        // 1. coupons table
        if (!$this->db->tableExists('coupons')) {
            $this->forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'code'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'type'            => ['type' => 'ENUM', 'constraint' => ['percent', 'fixed'], 'null' => false, 'default' => 'percent'],
                'value_minor'     => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
                'min_order_minor' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
                'max_uses'        => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
                'uses_count'      => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
                'starts_at'       => ['type' => 'DATETIME', 'null' => true, 'default' => null],
                'ends_at'         => ['type' => 'DATETIME', 'null' => true, 'default' => null],
                'is_active'       => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
                'notes'           => ['type' => 'TEXT', 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => false],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('code');
            $this->forge->addKey(['is_active', 'starts_at', 'ends_at']);
            $this->forge->createTable('coupons', true);
        }

        // 2. coupon_uses table
        if (!$this->db->tableExists('coupon_uses')) {
            $this->forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'coupon_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'user_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'order_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'discount_minor' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
                'created_at'     => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey('coupon_id');
            $this->forge->addKey('user_id');
            $this->forge->addKey('order_id');
            $this->forge->createTable('coupon_uses', true);
        }

        // 3. Add columns to orders table
        if ($this->db->tableExists('orders')) {
            $colsToAdd = [];
            if (!$this->db->fieldExists('coupon_id', 'orders')) {
                $colsToAdd['coupon_id'] = ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'cancelled_at'];
            }
            if (!$this->db->fieldExists('coupon_code', 'orders')) {
                $colsToAdd['coupon_code'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'coupon_id'];
            }
            if (!$this->db->fieldExists('shipping_method', 'orders')) {
                $colsToAdd['shipping_method'] = ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'home_delivery', 'after' => 'coupon_code'];
            }
            if (!$this->db->fieldExists('shipping_provider', 'orders')) {
                $colsToAdd['shipping_provider'] = ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'after' => 'shipping_method'];
            }
            if (!$this->db->fieldExists('shipping_reference', 'orders')) {
                $colsToAdd['shipping_reference'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'shipping_provider'];
            }
            if (!$this->db->fieldExists('shipping_status', 'orders')) {
                $colsToAdd['shipping_status'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'shipping_reference'];
            }
            if (!$this->db->fieldExists('shipping_pickup_date', 'orders')) {
                $colsToAdd['shipping_pickup_date'] = ['type' => 'DATE', 'null' => true, 'after' => 'shipping_status'];
            }
            if (!$this->db->fieldExists('shipping_last_event_at', 'orders')) {
                $colsToAdd['shipping_last_event_at'] = ['type' => 'DATETIME', 'null' => true, 'after' => 'shipping_pickup_date'];
            }
            if (!$this->db->fieldExists('shipping_error', 'orders')) {
                $colsToAdd['shipping_error'] = ['type' => 'TEXT', 'null' => true, 'after' => 'shipping_last_event_at'];
            }
            if ($colsToAdd) {
                $this->forge->addColumn('orders', $colsToAdd);
            }
        }

        // 4. order_shipping_events table
        if (!$this->db->tableExists('order_shipping_events')) {
            $this->forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'order_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'reference_no'   => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => false],
                'status'         => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => false],
                'description'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'hub_name'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'event_at'       => ['type' => 'DATETIME', 'null' => true],
                'rider_code'     => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => true],
                'rider_name'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'pod_image_url'  => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'failure_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'raw_payload'    => ['type' => 'TEXT', 'null' => true],
                'created_at'     => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['order_id', 'event_at']);
            $this->forge->addKey('reference_no');
            $this->forge->createTable('order_shipping_events', true);
        }
    }

    public function down()
    {
        $this->forge->dropTable('order_shipping_events', true);
        $this->forge->dropTable('coupon_uses', true);
        $this->forge->dropTable('coupons', true);

        $colsToDrop = [
            'coupon_id', 'coupon_code', 'shipping_method',
            'shipping_provider', 'shipping_reference', 'shipping_status',
            'shipping_pickup_date', 'shipping_last_event_at', 'shipping_error',
        ];
        foreach ($colsToDrop as $c) {
            if ($this->db->fieldExists($c, 'orders')) {
                $this->forge->dropColumn('orders', $c);
            }
        }
    }
}
