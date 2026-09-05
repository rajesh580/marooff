<?php

namespace App\Models;

use CodeIgniter\Model;

class SettingModel extends Model
{
    protected $table         = 'settings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['key', 'value', 'updated_at'];

    public function all(): array
    {
        $rows = $this->findAll();
        $out = [];
        foreach ($rows as $r) $out[$r['key']] = $r['value'];
        return $out;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->where('key', $key)->first();
        return $row ? $row['value'] : $default;
    }

    /**
     * Upsert a single setting row.
     * (Not named `set()` — that name collides with CodeIgniter\Model::set().)
     */
    public function put(string $key, string $value): void
    {
        $existing = $this->where('key', $key)->first();
        if ($existing) {
            $this->update($existing['id'], ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            $this->insert(['key' => $key, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }
}
