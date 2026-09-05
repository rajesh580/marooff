<?php

namespace App\Models;

use CodeIgniter\Model;

class AdminUserModel extends Model
{
    protected $table         = 'admin_users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = ['name', 'email', 'password_hash', 'role', 'is_active', 'last_login_at'];

    public function findByEmail(string $email): ?array
    {
        $row = $this->where('email', $email)->where('is_active', 1)->first();
        return $row ?: null;
    }
}
