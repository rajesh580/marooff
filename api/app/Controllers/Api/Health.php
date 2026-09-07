<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;

class Health extends BaseController
{
    public function index()
    {
        $checkDb = $this->request->getGet('check_db') !== null;
        $dbOk = true;
        $dbError = null;
        if ($checkDb) {
            try {
                $dbOk = (bool) \Config\Database::connect()->query('SELECT 1')->getRow();
            } catch (\Throwable $e) {
                $dbOk = false;
                $dbError = $e->getMessage();
            }
        }
        return $this->ok([
            'service'   => 'marooff-backend-api',
            'status'    => 'ok',
            'time'      => date(DATE_ATOM),
            'php'       => PHP_VERSION,
            'db_ok'     => $dbOk,
            'db_error'  => $dbError,
        ]);
    }
}
