<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\Jwt;
use App\Models\CustomerModel;

class Auth extends BaseController
{
    public function register()
    {
        $body = $this->jsonBody();
        $name  = trim((string) ($body['name']  ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $phone = trim((string) ($body['phone'] ?? ''));
        $pass  = (string) ($body['password'] ?? '');

        $errors = [];
        if ($name === '')                              $errors['name'] = 'Required';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))$errors['email'] = 'Valid email required';
        if (strlen($pass) < 8)                         $errors['password'] = 'Must be at least 8 characters';
        if ($errors) return $this->validationError($errors);

        $m = new CustomerModel();
        if ($m->findByEmail($email)) {
            return $this->fail('EMAIL_TAKEN', 'An account with that email already exists.', null, 409);
        }
        $row = [
            'name'          => $name,
            'email'         => $email,
            'phone'         => $phone ?: null,
            'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
            'is_active'     => 1,
        ];
        $m->insert($row);
        $id = (int) $m->getInsertID();

        return $this->created([
            'user'  => $this->user($id),
            'token' => $this->issueToken($id, $email, $name),
        ]);
    }

    public function login()
    {
        $body  = $this->jsonBody();
        $email = strtolower(trim((string) ($body['email']    ?? '')));
        $pass  = (string) ($body['password'] ?? '');
        if (!$email || !$pass) return $this->validationError(['email' => 'required', 'password' => 'required']);

        $m = new CustomerModel();
        $u = $m->findByEmail($email);
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            return $this->fail('INVALID_CREDENTIALS', 'Email or password is incorrect.', null, 401);
        }
        $m->update($u['id'], ['last_login_at' => date('Y-m-d H:i:s')]);

        return $this->ok([
            'user'  => CustomerModel::sanitize($u),
            'token' => $this->issueToken((int) $u['id'], $u['email'], $u['name']),
        ]);
    }

    public function logout()
    {
        // Stateless JWT — client discards the token. Endpoint kept for symmetry.
        return $this->ok(['logged_out' => true]);
    }

    public function me()
    {
        $hdr     = $this->request->getHeaderLine('X-Auth-User');
        $payload = $hdr ? json_decode($hdr, true) : null;
        if (!$payload || empty($payload['sub'])) return $this->unauthorized();
        return $this->ok($this->user((int) $payload['sub']));
    }

    /**
     * PUT /api/auth/me — update profile (name, phone, optionally email + password).
     * Requires current_password to change email or password.
     */
    public function updateMe()
    {
        $hdr     = $this->request->getHeaderLine('X-Auth-User');
        $payload = $hdr ? json_decode($hdr, true) : null;
        if (!$payload || empty($payload['sub'])) return $this->unauthorized();
        $id = (int) $payload['sub'];

        $body = $this->jsonBody();
        $m = new CustomerModel();
        $u = $m->find($id);
        if (!$u) return $this->unauthorized();

        $patch  = [];
        $errors = [];

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') $errors['name'] = 'Required';
            else $patch['name'] = $name;
        }
        if (array_key_exists('phone', $body)) {
            $patch['phone'] = trim((string) $body['phone']) ?: null;
        }

        $wantsEmailChange = array_key_exists('email', $body) && strtolower(trim((string) $body['email'])) !== strtolower($u['email']);
        $wantsPassChange  = !empty($body['new_password']);

        if ($wantsEmailChange || $wantsPassChange) {
            $current = (string) ($body['current_password'] ?? '');
            if ($current === '' || !password_verify($current, $u['password_hash'])) {
                return $this->fail('INVALID_CREDENTIALS', 'Current password is incorrect.', null, 401);
            }
        }

        if ($wantsEmailChange) {
            $newEmail = strtolower(trim((string) $body['email']));
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Valid email required';
            elseif ($m->where('email', $newEmail)->where('id !=', $id)->first()) $errors['email'] = 'That email is already in use';
            else $patch['email'] = $newEmail;
        }

        if ($wantsPassChange) {
            $newPass = (string) $body['new_password'];
            if (strlen($newPass) < 8) $errors['new_password'] = 'Must be at least 8 characters';
            else $patch['password_hash'] = password_hash($newPass, PASSWORD_BCRYPT);
        }

        if ($errors) return $this->validationError($errors);
        if ($patch)  $m->update($id, $patch);

        return $this->ok($this->user($id));
    }

    // --- helpers ---

    private function user(int $id): ?array
    {
        $u = (new CustomerModel())->find($id);
        return $u ? CustomerModel::sanitize($u) : null;
    }

    private function issueToken(int $id, string $email, string $name): array
    {
        $secret = (string) env('auth.jwt_secret', 'CHANGE_ME');
        $ttl    = (int) env('auth.jwt_access_ttl', 28800);
        $token  = Jwt::encode([
            'sub'   => $id,
            'email' => $email,
            'name'  => $name,
            'role'  => 'customer',
        ], $secret, $ttl);
        return ['access_token' => $token, 'expires_in' => $ttl];
    }
}
