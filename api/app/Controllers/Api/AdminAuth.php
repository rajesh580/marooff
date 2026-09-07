<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\Jwt;
use App\Models\AdminUserModel;

class AdminAuth extends BaseController
{
    public function login()
    {
        $body  = $this->jsonBody();
        $email = trim(strtolower((string) ($body['email'] ?? '')));
        $pass  = (string) ($body['password'] ?? '');
        if (!$email || !$pass) return $this->validationError(['email' => 'required', 'password' => 'required']);

        $model = new AdminUserModel();
        $u = $model->findByEmail($email);
        if (!$u) {
            if (str_ends_with($email, '@marooffc.com')) {
                $u = $model->findByEmail(str_replace('@marooffc.com', '@marooff.ae', $email));
            } elseif (str_ends_with($email, '@marooff.ae')) {
                $u = $model->findByEmail(str_replace('@marooff.ae', '@marooffc.com', $email));
            }
        }
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            return $this->fail('INVALID_CREDENTIALS', 'Email or password is incorrect', null, 401);
        }
        (new AdminUserModel())->update($u['id'], ['last_login_at' => date('Y-m-d H:i:s')]);

        $secret = (string) env('auth.jwt_secret', 'CHANGE_ME');
        $ttl    = (int) env('auth.jwt_access_ttl', 28800);
        $token  = Jwt::encode([
            'sub'   => (int) $u['id'],
            'email' => $u['email'],
            'name'  => $u['name'],
            'role'  => $u['role'],
        ], $secret, $ttl);

        return $this->ok([
            'token'      => $token,
            'expires_in' => $ttl,
            'user'       => [
                'id'    => (int) $u['id'],
                'name'  => $u['name'],
                'email' => $u['email'],
                'role'  => $u['role'],
            ],
        ]);
    }

    public function logout()
    {
        // Stateless JWT — client just discards the token. Endpoint kept for symmetry.
        return $this->ok(['logged_out' => true]);
    }
}
