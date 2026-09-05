<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 3.1 — per-product "position" so the admin controls the order products
 * appear in within a category. Lower number = appears first.
 *
 * 0 means "no explicit position" → those products sort to the end by id DESC
 * (current behavior preserved when position is not set).
 */
class AddProductPosition extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('position', 'products')) {
            $this->forge->addColumn('products', [
                'position' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => false,
                    'default'  => 0,
                    'after'    => 'is_bestseller',
                ],
            ]);
            // Helpful when filtering by category and sorting by position.
            $this->db->query('CREATE INDEX `products_category_position` ON `products` (`category_id`, `position`)');
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('position', 'products')) {
            // Drop the index first; some MySQL versions refuse to drop a column under an index.
            try { $this->db->query('DROP INDEX `products_category_position` ON `products`'); } catch (\Throwable $e) {}
            $this->forge->dropColumn('products', 'position');
        }
    }
}
