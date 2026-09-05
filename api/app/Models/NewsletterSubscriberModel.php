<?php

namespace App\Models;

use CodeIgniter\Model;

class NewsletterSubscriberModel extends Model
{
    protected $table         = 'newsletter_subscribers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'email', 'source', 'language', 'ip', 'user_agent', 'is_active', 'unsubscribed_at',
    ];

    public function findByEmail(string $email): ?array
    {
        $row = $this->where('email', strtolower(trim($email)))->first();
        return $row ?: null;
    }
}
