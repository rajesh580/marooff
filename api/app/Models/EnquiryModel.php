<?php

namespace App\Models;

use CodeIgniter\Model;

class EnquiryModel extends Model
{
    protected $table         = 'enquiries';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'name', 'email', 'phone', 'subject', 'message',
        'source_page', 'user_agent', 'ip',
        'status', 'admin_note', 'responded_by', 'responded_at',
    ];

    protected $validationRules = [
        'name'    => 'required|max_length[120]',
        'email'   => 'required|valid_email|max_length[190]',
        'message' => 'required|min_length[10]|max_length[5000]',
    ];

    public function countByStatus(): array
    {
        $rows = $this->select('status, COUNT(*) AS c')->groupBy('status')->find();
        $out = ['new' => 0, 'read' => 0, 'responded' => 0, 'archived' => 0];
        foreach ($rows as $r) $out[$r['status']] = (int) $r['c'];
        $out['total'] = array_sum($out);
        return $out;
    }
}
