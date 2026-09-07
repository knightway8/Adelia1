<?php

declare(strict_types=1);

namespace Adelia;

final class Passwords
{
    public const string INITIAL_PASSWORD = 'password';

    public static function validateAccountPassword(#[\SensitiveParameter] string $password): void
    {
        if (!mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0") || mb_strlen($password) < 12 || mb_strlen($password) > 64) {
            throw new BoardMessage('Use a password with 12 to 64 characters.');
        }
        // PASSWORD_DEFAULT currently uses bcrypt; never silently truncate its input.
        if (strlen($password) > 72) {
            throw new BoardMessage('This password has too many bytes. Use a shorter passphrase (up to 72 UTF-8 bytes).');
        }
    }

    #[\NoDiscard]
    public static function hash(#[\SensitiveParameter] string $password): string
    {
        if (str_contains($password, "\0") || strlen($password) > 72) {
            throw new BoardMessage('Passwords must contain no null bytes and at most 72 UTF-8 bytes.');
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }

    #[\NoDiscard]
    public static function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
