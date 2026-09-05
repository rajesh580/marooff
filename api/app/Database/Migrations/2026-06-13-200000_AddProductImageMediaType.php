<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Extends product_images so the same gallery can hold images AND videos:
 *   - media_type  ENUM('image','video') DEFAULT 'image'
 *   - poster_url  VARCHAR(500) NULL   (optional preview thumbnail for videos)
 *
 * Existing rows default to media_type='image' so the storefront keeps rendering
 * everything as it does today until the admin actually uploads a video.
 */
class AddProductImageMediaType extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('media_type', 'product_images')) {
            $this->forge->addColumn('product_images', [
                'media_type' => [
                    'type'       => 'ENUM',
                    'constraint' => ['image', 'video'],
                    'null'       => false,
                    'default'    => 'image',
                    'after'      => 'url',
                ],
            ]);
        }
        if (!$this->db->fieldExists('poster_url', 'product_images')) {
            $this->forge->addColumn('product_images', [
                'poster_url' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 500,
                    'null'       => true,
                    'after'      => 'media_type',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('poster_url', 'product_images')) {
            $this->forge->dropColumn('product_images', 'poster_url');
        }
        if ($this->db->fieldExists('media_type', 'product_images')) {
            $this->forge->dropColumn('product_images', 'media_type');
        }
    }
}
