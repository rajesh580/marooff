<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds a separate `banner_url` to categories so the admin can upload TWO images:
 *   - image_url   → small square image used on the home "Shop by category" tile
 *                   and the sidebar / mobile menu thumbnails.
 *   - banner_url  → wide hero image used as the banner on the category landing page.
 *
 * When banner_url is empty the storefront falls back to image_url, so existing rows
 * keep their current look until the admin uploads a wide banner.
 */
class AddCategoryBannerUrl extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('banner_url', 'categories')) {
            $this->forge->addColumn('categories', [
                'banner_url' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 500,
                    'null'       => true,
                    'after'      => 'image_url',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('banner_url', 'categories')) {
            $this->forge->dropColumn('categories', 'banner_url');
        }
    }
}
