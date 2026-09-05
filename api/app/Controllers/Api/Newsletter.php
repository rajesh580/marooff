<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\NewsletterSubscriberModel;

class Newsletter extends BaseController
{
    /** POST /api/newsletter/subscribe { email } */
    public function subscribe()
    {
        $body  = $this->jsonBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->validationError(['email' => 'A valid email address is required.']);
        }

        $m   = new NewsletterSubscriberModel();
        $row = $m->findByEmail($email);
        $now = date('Y-m-d H:i:s');
        $source    = trim((string) ($body['source']   ?? 'home'));
        $language  = strtolower(substr((string) ($body['language'] ?? $this->lang()), 0, 2)) ?: 'en';
        $ip        = (string) $this->request->getIPAddress();
        $userAgent = substr((string) $this->request->getUserAgent(), 0, 255);

        if ($row) {
            // Already subscribed — reactivate if previously unsubscribed.
            $m->update($row['id'], [
                'is_active'       => 1,
                'unsubscribed_at' => null,
                'source'          => $source,
                'language'        => $language,
                'ip'              => $ip,
                'user_agent'      => $userAgent,
            ]);
            return $this->ok(['already_subscribed' => true]);
        }

        $m->insert([
            'email'      => $email,
            'source'     => $source,
            'language'   => $language,
            'ip'         => $ip,
            'user_agent' => $userAgent,
            'is_active'  => 1,
        ]);
        return $this->created(['subscribed' => true]);
    }
}
