<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

abstract class BaseController extends Controller
{
    /** @var array<int, string> */
    protected $helpers = ['url', 'text'];

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
    }

    // ---------- Marooff API response helpers ----------
    // Shape: { "success": true|false, "data": <obj|null>, "meta": <obj|null>, "error": { "code", "message", "fields"? } | null }

    protected function ok($data = null, ?array $meta = null, int $status = 200): ResponseInterface
    {
        $body = ['success' => true, 'data' => $data, 'error' => null];
        if ($meta !== null) $body['meta'] = $meta;
        return $this->response->setStatusCode($status)->setJSON($body);
    }

    protected function created($data = null): ResponseInterface
    {
        return $this->ok($data, null, 201);
    }

    protected function fail(string $code, string $message, ?array $fields = null, int $status = 400): ResponseInterface
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== null) $error['fields'] = $fields;
        return $this->response->setStatusCode($status)->setJSON(['success' => false, 'data' => null, 'error' => $error]);
    }

    protected function notFound(string $message = 'Resource not found'): ResponseInterface
    {
        return $this->fail('NOT_FOUND', $message, null, 404);
    }

    protected function validationError(array $fields, string $message = 'Validation failed'): ResponseInterface
    {
        return $this->fail('VALIDATION_ERROR', $message, $fields, 422);
    }

    protected function unauthorized(string $message = 'Unauthorized'): ResponseInterface
    {
        return $this->fail('UNAUTHORIZED', $message, null, 401);
    }

    protected function serverError(string $message = 'Internal server error'): ResponseInterface
    {
        return $this->fail('SERVER_ERROR', $message, null, 500);
    }

    // ---------- Request helpers ----------
    protected function jsonBody(): array
    {
        $body = $this->request->getJSON(true);
        return is_array($body) ? $body : ($this->request->getPost() ?: []);
    }

    protected function pageParams(int $defaultLimit = 24, int $maxLimit = 100): array
    {
        $page  = max(1, (int) ($this->request->getGet('page') ?: 1));
        $limit = (int) ($this->request->getGet('limit') ?: $defaultLimit);
        if ($limit < 1) $limit = $defaultLimit;
        if ($limit > $maxLimit) $limit = $maxLimit;
        return [$page, $limit, ($page - 1) * $limit];
    }

    // ---------- Language helpers ----------

    /** Current request language: 'ar' if ?lang=ar, otherwise 'en'. */
    protected function lang(): string
    {
        return strtolower((string) ($this->request->getGet('lang') ?? 'en')) === 'ar' ? 'ar' : 'en';
    }

    /**
     * If lang=ar, copy *_ar values over their English siblings (when non-empty).
     * Falls back to English when an Arabic field is empty / null. Apply to a single row
     * or every row of a list. Pass the field map you want to swap.
     *
     * Example:
     *   $row = $this->localize($row, ['name' => 'name_ar', 'short_desc' => 'short_desc_ar']);
     */
    protected function localize(?array $row, array $map): ?array
    {
        if ($row === null) return null;
        if ($this->lang() !== 'ar') return $row;
        foreach ($map as $en => $ar) {
            $v = $row[$ar] ?? null;
            if (is_string($v) && trim($v) !== '') $row[$en] = $v;
        }
        return $row;
    }

    /** Apply localize() to every row in a list. */
    protected function localizeMany(array $rows, array $map): array
    {
        if ($this->lang() !== 'ar') return $rows;
        foreach ($rows as &$r) $r = $this->localize($r, $map);
        unset($r);
        return $rows;
    }
}
