<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AdminUserModel;

class AdminAuth extends BaseController
{
    public function me()
    {
        $hdr = $this->request->getHeaderLine('X-Auth-User');
        $payload = $hdr ? json_decode($hdr, true) : null;
        return $this->ok($payload ?: []);
    }

    public function changePassword()
    {
        $hdr = $this->request->getHeaderLine('X-Auth-User');
        $payload = $hdr ? json_decode($hdr, true) : null;
        $userId = (int) ($payload['sub'] ?? 0);
        if (!$userId) {
            return $this->unauthorized('User not authenticated');
        }

        $body = $this->jsonBody();
        $currentPassword = (string) ($body['current_password'] ?? '');
        $newPassword = (string) ($body['new_password'] ?? '');
        $confirmPassword = (string) ($body['confirm_password'] ?? '');

        $errors = [];
        if ($currentPassword === '') {
            $errors['current_password'] = 'Current password is required';
        }
        if ($newPassword === '') {
            $errors['new_password'] = 'New password is required';
        } elseif (strlen($newPassword) < 6) {
            $errors['new_password'] = 'New password must be at least 6 characters long';
        }
        if ($confirmPassword !== '' && $newPassword !== $confirmPassword) {
            $errors['confirm_password'] = 'Passwords do not match';
        }

        if (!empty($errors)) {
            return $this->validationError($errors, reset($errors));
        }

        $model = new AdminUserModel();
        $user = $model->find($userId);
        if (!$user) {
            return $this->notFound('Admin user not found');
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            return $this->fail(
                'INVALID_CURRENT_PASSWORD',
                'Current password is incorrect',
                ['current_password' => 'Current password is incorrect'],
                400
            );
        }

        if (password_verify($newPassword, $user['password_hash'])) {
            return $this->fail(
                'SAME_PASSWORD',
                'New password must be different from current password',
                ['new_password' => 'New password cannot be identical to current password'],
                400
            );
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $model->update($userId, ['password_hash' => $newHash]);

        // Sync mirrored account if exists (@marooff.ae <-> @marooffc.com)
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        $mirroredEmail = null;
        if (str_ends_with($email, '@marooff.ae')) {
            $mirroredEmail = str_replace('@marooff.ae', '@marooffc.com', $email);
        } elseif (str_ends_with($email, '@marooffc.com')) {
            $mirroredEmail = str_replace('@marooffc.com', '@marooff.ae', $email);
        }

        if ($mirroredEmail) {
            $mirrorUser = $model->findByEmail($mirroredEmail);
            if ($mirrorUser && (int) $mirrorUser['id'] !== $userId) {
                $model->update((int) $mirrorUser['id'], ['password_hash' => $newHash]);
            }
        }

        return $this->ok(['message' => 'Password updated successfully']);
    }
}
