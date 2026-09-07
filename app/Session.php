<?php

declare(strict_types=1);

namespace Adelia;

final class Session
{
    private const array ACTIONS = ['delete','deleteaccount','deletekeyword','approve','sticky','lock','clearreports','rebuildall','logout','verify','report'];

    public static function start(): void
    {
        if (PHP_SAPI === 'cli') {
            $_SESSION = [];
            return;
        }
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        session_start([
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'use_trans_sid' => false,
            'cache_limiter' => '',
            'cookie_httponly' => true,
            'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'cookie_samesite' => 'Lax',
            'cookie_path' => '/',
        ]);
    }

    #[\NoDiscard]
    public static function token(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function validate(Request $request): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $action = array_any(self::ACTIONS, static fn(string $key): bool => isset($request->query[$key]));
        $post = $request->server['REQUEST_METHOD'] === 'POST';
        if ($action && !$post) {
            throw new BoardMessage('Use the board form to submit this action.');
        }
        if ($post && !hash_equals(self::token(), (string) ($request->form['_csrf'] ?? ''))) {
            throw new BoardMessage('The form expired. Refresh the page and try again.');
        }
    }

    public static function decorate(string $html): string
    {
        $token = self::token();
        return preg_replace('/(<form\b[^>]*\bmethod=["\']post["\'][^>]*>)/i', '$1<input type="hidden" name="_csrf" value="' . $token . '">', $html) ?? $html;
    }
}
