<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class AdminAuth extends BaseController
{
    public function me()
    {
        $hdr = $this->request->getHeaderLine('X-Auth-User');
        $payload = $hdr ? json_decode($hdr, true) : null;
        return $this->ok($payload ?: []);
    }
}
