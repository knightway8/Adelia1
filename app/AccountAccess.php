<?php

declare(strict_types=1);

namespace Adelia;

trait AccountAccess
{
    private ?array $pendingSessionAccount = null;

    private function mustChangePassword(): bool
    {
        return $this->loggedin && isset($_SESSION['initial_password'])
            && hash_equals($_SESSION['initial_password'], $_SESSION['account_fingerprint'] ?? '');
    }

    private function changeOwnPassword(): string
    {
        $this->requireModerator();
        if ($this->request->server['REQUEST_METHOD'] === 'POST') {
            $current = $this->request->form['current_password'] ?? null;
            $password = $this->request->form['password'] ?? null;
            $confirm = $this->request->form['confirm'] ?? null;
            if (!is_string($current) || !is_string($password) || !is_string($confirm)) {
                throw new BoardMessage('Complete all three password fields.');
            }
            if (!Passwords::verify($current, $this->account['password'])) {
                throw new BoardMessage('The current password is incorrect.');
            }
            Passwords::validateAccountPassword($password);
            if (!hash_equals($password, $confirm)) {
                throw new BoardMessage('Passwords do not match.');
            }
            if (Passwords::verify($password, $this->account['password'])) {
                throw new BoardMessage('Choose a different password.');
            }
            $account = $this->account;
            $account['password'] = $password;
            $this->updateAccount($account);
            $this->manageLogAction('Changed own password');
            return $this->manageInfo('Password updated. Other sessions have been signed out.') . '<p><a href="?manage">Continue to management</a></p>';
        }
        $notice = $this->mustChangePassword() ? $this->manageInfo('Choose your own password before using management. The initial login is admin / password.') : '';
        return $notice . $this->manageChangePasswordForm();
    }

    private function refreshPasswordSession(): void
    {
        if ($this->pendingSessionAccount === null) {
            return;
        }
        if (PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
        }
        $_SESSION['account_id'] = $this->pendingSessionAccount['id'];
        $_SESSION['account_fingerprint'] = hash('sha256', $this->pendingSessionAccount['password']);
        unset($_SESSION['initial_password'], $_SESSION['csrf']);
        $this->pendingSessionAccount = null;
    }
}
