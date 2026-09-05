<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\EnquiryModel;

class Enquiries extends BaseController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pageParams(20, 100);
        $m = new EnquiryModel();

        $status = $this->request->getGet('status');
        $q      = trim((string) $this->request->getGet('q'));

        if ($status && in_array($status, ['new', 'read', 'responded', 'archived'], true)) {
            $m = $m->where('status', $status);
        }
        if ($q !== '') {
            $m = $m->groupStart()
                ->like('name', $q)->orLike('email', $q)
                ->orLike('subject', $q)->orLike('message', $q)
                ->groupEnd();
        }
        $total = (clone $m)->countAllResults(false);
        $items = $m->orderBy('created_at', 'DESC')->limit($limit, $offset)->find();

        return $this->ok($items, [
            'page'      => $page,
            'limit'     => $limit,
            'total'     => $total,
            'last_page' => $total ? (int) ceil($total / $limit) : 1,
            'counts'    => (new EnquiryModel())->countByStatus(),
        ]);
    }

    public function show(int $id)
    {
        $m = new EnquiryModel();
        $row = $m->find($id);
        if (!$row) return $this->notFound('Enquiry not found');
        if ($row['status'] === 'new') {
            $m->update($id, ['status' => 'read']);
            $row['status'] = 'read';
        }
        return $this->ok($row);
    }

    public function update(int $id)
    {
        $m = new EnquiryModel();
        $row = $m->find($id);
        if (!$row) return $this->notFound('Enquiry not found');

        $body = $this->jsonBody();
        $patch = [];
        if (isset($body['status']) && in_array($body['status'], ['new', 'read', 'responded', 'archived'], true)) {
            $patch['status'] = $body['status'];
        }
        if (array_key_exists('admin_note', $body)) {
            $patch['admin_note'] = (string) $body['admin_note'];
        }
        if (isset($body['mark_responded']) && $body['mark_responded']) {
            $patch['status']       = 'responded';
            $patch['responded_at'] = date('Y-m-d H:i:s');
            $hdr = $this->request->getHeaderLine('X-Auth-User');
            $auth = $hdr ? json_decode($hdr, true) : null;
            if ($auth && isset($auth['sub'])) $patch['responded_by'] = (int) $auth['sub'];
        }
        if (!$patch) return $this->validationError(['body' => 'No updatable fields provided']);

        $m->update($id, $patch);
        return $this->ok($m->find($id));
    }

    public function delete(int $id)
    {
        $m = new EnquiryModel();
        if (!$m->find($id)) return $this->notFound('Enquiry not found');
        $m->delete($id);
        return $this->ok(['deleted' => true]);
    }
}
