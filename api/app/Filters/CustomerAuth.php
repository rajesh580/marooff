<?php

namespace App\Filters;

use App\Libraries\Jwt;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires a valid JWT issued to a customer for /api/me/* routes.
 * On success, attaches the decoded payload to the request as X-Auth-User.
 */
class CustomerAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('Authorization');
        if (!$header || stripos($header, 'Bearer ') !== 0) {
            return $this->reject('Sign in required');
        }
        $token   = trim(substr($header, 7));
        $secret  = (string) env('auth.jwt_secret', 'CHANGE_ME');
        $payload = Jwt::decode($token, $secret);
        if (!$payload || ($payload['role'] ?? null) !== 'customer') {
            return $this->reject('Invalid or expired session');
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
            'data'    => null,
            'error'   => ['code' => 'UNAUTHORIZED', 'message' => $message],
        ]);
    }
}
