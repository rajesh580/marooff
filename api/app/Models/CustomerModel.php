<?php

namespace App\Models;

use CodeIgniter\Model;

class CustomerModel extends Model
{
    protected $table         = 'customers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = ['name', 'email', 'phone', 'password_hash', 'email_verified_at', 'last_login_at', 'is_active'];

    public function findByEmail(string $email): ?array
    {
        $row = $this->where('email', $email)->where('is_active', 1)->first();
        return $row ?: null;
    }

    /** Strip sensitive fields before returning to clients. */
    public static function sanitize(array $row): array
    {
        unset($row['password_hash']);
        return $row;
    }
}
