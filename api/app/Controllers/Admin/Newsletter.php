<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\NewsletterSubscriberModel;

class Newsletter extends BaseController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pageParams(50, 200);
        $m = new NewsletterSubscriberModel();
        $b = $m;

        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') $b = $b->like('email', $q);

        $status = (string) $this->request->getGet('status');
        if ($status === 'active')   $b = $b->where('is_active', 1);
        if ($status === 'inactive') $b = $b->where('is_active', 0);

        $b = $b->orderBy('id', 'DESC');
        $total = (clone $b)->countAllResults(false);
        $rows  = $b->limit($limit, $offset)->find();

        // Quick summary counts
        $allM    = new NewsletterSubscriberModel();
        $stats = [
            'total'    => $allM->countAllResults(false),
            'active'   => (new NewsletterSubscriberModel())->where('is_active', 1)->countAllResults(),
            'inactive' => (new NewsletterSubscriberModel())->where('is_active', 0)->countAllResults(),
        ];

        return $this->ok($rows, [
            'page' => $page, 'limit' => $limit, 'total' => $total,
            'last_page' => $total ? (int) ceil($total / $limit) : 1,
            'stats' => $stats,
        ]);
    }

    /** DELETE /api/admin/newsletter/:id — soft unsubscribe (keeps email, flags inactive). */
    public function delete(int $id)
    {
        $m = new NewsletterSubscriberModel();
        $row = $m->find($id);
        if (!$row) return $this->notFound('Subscriber not found');
        if ($this->request->getGet('hard') === '1') {
            $m->delete($id);
            return $this->ok(['hard_deleted' => true]);
        }
        $m->update($id, ['is_active' => 0, 'unsubscribed_at' => date('Y-m-d H:i:s')]);
        return $this->ok(['unsubscribed' => true]);
    }

    /** GET /api/admin/newsletter/export — streams a CSV of all subscribers. */
    public function export()
    {
        $m = new NewsletterSubscriberModel();
        $rows = $m->orderBy('id', 'DESC')->findAll();

        $filename = 'marooff-newsletter-' . date('Ymd-His') . '.csv';
        $out = "Email,Source,Language,Subscribed At,Status,Unsubscribed At\n";
        foreach ($rows as $r) {
            $out .= sprintf(
                "%s,%s,%s,%s,%s,%s\n",
                str_replace([",", "\n", "\r"], ' ', (string) $r['email']),
                str_replace([",", "\n", "\r"], ' ', (string) ($r['source'] ?? '')),
                str_replace([",", "\n", "\r"], ' ', (string) ($r['language'] ?? '')),
                $r['created_at'],
                $r['is_active'] ? 'active' : 'inactive',
                (string) ($r['unsubscribed_at'] ?? '')
            );
        }

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->setBody($out);
    }
}
