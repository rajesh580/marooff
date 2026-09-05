<?php

namespace App\Filters;

use App\Libraries\Jwt;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Accepts EITHER a sales OR an admin JWT for /api/sales/* routes.
 * Admins inherit sales-panel access; sales staff cannot access /api/admin/*.
 */
class SalesAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('Authorization');
        if (!$header || stripos($header, 'Bearer ') !== 0) {
            return $this->reject('Missing bearer token');
        }
        $token = trim(substr($header, 7));
        $secret = env('auth.jwt_secret', 'CHANGE_ME');
        $payload = Jwt::decode($token, (string) $secret);
        $role = $payload['role'] ?? null;
        if (!$payload || !in_array($role, ['admin', 'sales'], true)) {
            return $this->reject('Invalid or expired token');
        }
        $request->setHeader('X-Auth-User', json_encode($payload));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    private function reject(string $message): ResponseInterface
    {
        return service('response')->setStatusCode(401)->setJSON([
            'success' => false,
            'data' => null,
            'error' => ['code' => 'UNAUTHORIZED', 'message' => $message],
        ]);
    }
}
