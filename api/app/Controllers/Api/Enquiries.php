<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\EnquiryModel;

class Enquiries extends BaseController
{
    /**
     * Public form submission. POST /api/enquiries
     */
    public function store()
    {
        $body = $this->jsonBody();
        $row = [
            'name'        => trim((string) ($body['name']    ?? '')),
            'email'       => trim((string) ($body['email']   ?? '')),
            'phone'       => trim((string) ($body['phone']   ?? '')) ?: null,
            'subject'     => trim((string) ($body['subject'] ?? '')) ?: null,
            'message'     => trim((string) ($body['message'] ?? '')),
            'source_page' => mb_substr((string) ($body['source_page'] ?? ''), 0, 255) ?: null,
            'user_agent'  => mb_substr((string) $this->request->getUserAgent(), 0, 255) ?: null,
            'ip'          => $this->request->getIPAddress(),
            'status'      => 'new',
        ];

        $m = new EnquiryModel();
        if (!$m->insert($row)) {
            return $this->validationError($m->errors());
        }
        return $this->created(['enquiry_id' => $m->getInsertID()]);
    }
}
