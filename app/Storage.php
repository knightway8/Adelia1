<?php

declare(strict_types=1);

namespace Adelia;

trait Storage
{
    private function initializeAccounts(): void
    {
        if ($this->database->count($this->config->dbaccounts) !== 0) {
            return;
        }
        $this->database->insert($this->config->dbaccounts, [
            'username' => 'admin', 'password' => Passwords::hash(Passwords::INITIAL_PASSWORD),
            'role' => Role::SuperAdministrator->value, 'lastactive' => 0,
        ]);
    }

    private function accountByID(int $id): array
    {
        return $this->database->row($this->config->dbaccounts, Filter::equal('id', $id));
    }
    private function accountByUsername(string $username): array
    {
        return $this->database->row($this->config->dbaccounts, Filter::equal('username', $username));
    }
    private function allAccounts(): array
    {
        return $this->database->rows($this->config->dbaccounts, order: ['role' => 'ASC', 'username' => 'ASC']);
    }

    private function insertAccount(array $account): int
    {
        $this->validateUsername($account['username']);
        Passwords::validateAccountPassword($account['password']);
        if ($this->accountByUsername($account['username']) !== []) {
            throw new BoardMessage('That username is already in use.');
        }
        return $this->database->insert($this->config->dbaccounts, [
            'username' => $account['username'],
            'password' => Passwords::hash($account['password']),
            'role' => $account['role'],
            'lastactive' => 0,
        ]);
    }

    private function updateAccount(array $account): void
    {
        $previous = $this->accountByID((int) $account['id']);
        if ($previous === []) {
            throw new BoardMessage('Account not found.');
        }
        $this->validateUsername($account['username']);
        if ($previous['role'] === Role::SuperAdministrator->value && $account['role'] !== Role::SuperAdministrator->value) {
            $this->requireAnotherSuperAdministrator($previous['id']);
        }
        if ($account['password'] !== $previous['password']) {
            Passwords::validateAccountPassword($account['password']);
        }
        $other = $this->accountByUsername($account['username']);
        if ($other !== [] && $other['id'] !== $previous['id']) {
            throw new BoardMessage('That username is already in use.');
        }
        $password = $account['password'] === $previous['password'] ? $account['password'] : Passwords::hash($account['password']);
        $this->database->update($this->config->dbaccounts, (int) $account['id'], [
            'username' => $account['username'], 'password' => $password, 'role' => (int) $account['role'], 'lastactive' => (int) $account['lastactive'],
        ]);
        if ($this->loggedin && $this->account['id'] === $previous['id'] && $password !== $previous['password']) {
            $this->pendingSessionAccount = $this->accountByID($previous['id']);
        }
    }

    private function deleteAccountByID(int $id): void
    {
        if (($this->accountByID($id)['role'] ?? null) === Role::SuperAdministrator->value) {
            $this->requireAnotherSuperAdministrator($id);
        }
        $this->database->delete($this->config->dbaccounts, Filter::equal('id', $id));
    }
    private function validateUsername(string $username): void
    {
        if (!mb_check_encoding($username, 'UTF-8') || trim($username) === '' || mb_strlen($username) > 64 || preg_match('/[\x00-\x1f\x7f]/', $username)) {
            throw new BoardMessage('Use a nonempty username of at most 64 characters without control characters.');
        }
    }

    private function requireAnotherSuperAdministrator(int $id): void
    {
        if ($this->database->count($this->config->dbaccounts, Filter::all(Filter::equal('role', Role::SuperAdministrator->value), Filter::notEqual('id', $id))) === 0) {
            throw new BoardMessage('Keep at least one enabled super-administrator account.');
        }
    }

    private function canViewPost(array $post): bool
    {
        if ($post === []) {
            return false;
        }
        if ($this->loggedin) {
            return true;
        }
        if ($post['moderated'] === 0) {
            return false;
        }
        $thread = $post['parent'] === 0 ? $post : $this->postByID($post['parent']);
        return $thread !== [] && $thread['parent'] === 0 && $thread['moderated'] > 0;
    }

    private function keywordByID(int $id): array
    {
        return $this->database->row($this->config->dbkeywords, Filter::equal('id', $id));
    }
    private function keywordByText(string $text): array
    {
        return $this->database->row($this->config->dbkeywords, Filter::equal('text', mb_strtolower($text)));
    }
    private function allKeywords(): array
    {
        return $this->database->rows($this->config->dbkeywords, order: ['text' => 'ASC']);
    }
    private function insertKeyword(array $keyword): void
    {
        $this->database->insert($this->config->dbkeywords, ['text' => mb_strtolower($keyword['text']), 'action' => $keyword['action']]);
    }
    private function deleteKeyword(int $id): void
    {
        $this->database->delete($this->config->dbkeywords, Filter::equal('id', $id));
    }

    private function getLogs(int $offset, int $limit): array
    {
        return $this->database->rows($this->config->dblogs, order: ['timestamp' => 'DESC', 'id' => 'DESC'], limit: max(0, $limit), offset: max(0, $offset));
    }
    private function allLogs(): array
    {
        return $this->database->rows($this->config->dblogs, order: ['timestamp' => 'ASC', 'id' => 'ASC']);
    }
    private function insertLog(array $log): void
    {
        $this->database->insert($this->config->dblogs, ['timestamp' => (int) $log['timestamp'], 'account' => (int) $log['account'], 'message' => $log['message']]);
    }

    private function postByID(int $id): array
    {
        return $this->database->row($this->config->dbposts, Filter::equal('id', $id));
    }
    private function threadExistsByID(int $id): bool
    {
        return $this->database->count($this->config->dbposts, Filter::all(Filter::equal('id', $id), Filter::equal('parent', 0))) > 0;
    }

    private function insertPost(array $post): int
    {
        unset($post['id']);
        $post['timestamp'] = time();
        $post['bumped'] = $post['timestamp'];
        return $this->database->insert($this->config->dbposts, $post);
    }

    private function updatePostBumped(int $id, int $bumped): void
    {
        $this->database->update($this->config->dbposts, $id, ['bumped' => $bumped]);
    }
    private function approvePostByID(int $id, int $moderated): void
    {
        $this->database->update($this->config->dbposts, $id, ['moderated' => $moderated]);
    }
    private function bumpThreadByID(int $id): void
    {
        $this->updatePostBumped($id, time());
    }
    private function stickyThreadByID(int $id, int $setsticky): void
    {
        $this->database->update($this->config->dbposts, $id, ['stickied' => $setsticky]);
    }
    private function lockThreadByID(int $id, int $setlock): void
    {
        $this->database->update($this->config->dbposts, $id, ['locked' => $setlock]);
    }
    private function countThreads(): int
    {
        return $this->database->count($this->config->dbposts, Filter::all(Filter::equal('parent', 0), Filter::greater('moderated', 0)));
    }
    private function allThreads(bool $moderated_only = true): array
    {
        return $this->database->rows($this->config->dbposts, Filter::all(Filter::equal('parent', 0), $moderated_only ? Filter::greater('moderated', 0) : Filter::all()), ['stickied' => 'DESC', 'bumped' => 'DESC', 'id' => 'DESC']);
    }
    private function numRepliesToThreadByID(int $id): int
    {
        return $this->database->count($this->config->dbposts, Filter::all(Filter::equal('parent', $id), Filter::greater('moderated', 0)));
    }
    private function threadFilter(int $id, bool $moderatedOnly): Filter
    {
        return Filter::all(
            Filter::any(Filter::equal('id', $id), Filter::equal('parent', $id)),
            $moderatedOnly ? Filter::greater('moderated', 0) : Filter::all(),
        );
    }

    private function postsInThreadByID(int $id, bool $moderated_only = true): array
    {
        return $this->database->rows($this->config->dbposts, $this->threadFilter($id, $moderated_only), ['id' => 'ASC']);
    }

    private function imagesInThreadByID(int $id, bool $moderated_only = true): int
    {
        return $this->database->count($this->config->dbposts, Filter::all($this->threadFilter($id, $moderated_only), Filter::notEqual('file', '')));
    }
    private function postsByHex(string $hex): array
    {
        return $this->database->rows($this->config->dbposts, Filter::equal('file_hex', $hex));
    }
    private function latestPosts(bool $moderated = true): array
    {
        return $this->database->rows($this->config->dbposts, $moderated ? Filter::greater('moderated', 0) : Filter::equal('moderated', 0), ['id' => 'DESC'], 10);
    }
    private function deletePostByID(int $id): void
    {
        $this->database->delete($this->config->dbposts, Filter::equal('id', $id));
    }

    private function trimThreads(): void
    {
        if ($this->config->maxthreads <= 0) {
            return;
        }
        foreach (array_slice($this->allThreads(), $this->config->maxthreads) as $post) {
            $this->deletePost($post['id']);
        }
    }

    private function reportsByPost(int $post): array
    {
        return $this->database->rows($this->config->dbreports, Filter::equal('post', $post));
    }
    private function allReports(): array
    {
        return $this->database->rows($this->config->dbreports, order: ['post' => 'ASC']);
    }
    private function insertReport(array $report): void
    {
        $this->database->insert($this->config->dbreports, ['post' => (int) $report['post']]);
    }
    private function deleteReportsByPost(int $post): void
    {
        $this->database->delete($this->config->dbreports, Filter::equal('post', $post));
    }

}
