<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;

class Health extends BaseController
{
    public function index()
    {
        $dbOk = false; $dbError = null;
        try {
            $dbOk = (bool) \Config\Database::connect()->query('SELECT 1')->getRow();
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }
        return $this->ok([
            'service'   => 'marooff-backend-api',
            'env'       => ENVIRONMENT,
            'time'      => date(DATE_ATOM),
            'php'       => PHP_VERSION,
            'db_ok'     => $dbOk,
            'db_error'  => $dbError,
        ]);
    }
}
