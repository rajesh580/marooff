<?php

namespace App\Filters;

use App\Libraries\Jwt;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires a valid JWT in the Authorization header for /api/admin/* routes.
 * On success, attaches the decoded payload to the request as a custom header so controllers can read it.
 */
class AdminAuth implements FilterInterface
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
        if (!$payload || ($payload['role'] ?? null) !== 'admin') {
            return $this->reject('Invalid or expired token');
        }
        // Attach payload for controllers
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
