<?php

declare(strict_types=1);

namespace Adelia;

final class Tripcodes
{
    /** @return array{string, string} */
    public static function split(#[\SensitiveParameter] string $name, #[\SensitiveParameter] string $key): array
    {
        if (strlen($name) > 4096 || !mb_check_encoding($name, 'UTF-8')) {
            throw new BoardMessage('The name or tripcode secret is too long or invalid.');
        }
        $parts = preg_split('/(##|[#!])/', $name, 2, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) !== 3) {
            return [$name, ''];
        }
        if ($key === '') {
            throw new \RuntimeException('A private board key is required for tripcodes.');
        }
        if ($parts[1] === '##') {
            if ($parts[2] === '') {
                throw new BoardMessage('Enter a secret after ## for your secure tripcode.');
            }
            $digest = hash_hmac('sha256', "Adelia secure tripcode v1\0" . $parts[2], $key, true);
            // 128 bits, with an extra ! distinguishing the public secure-trip marker.
            $tripcode = '!' . rtrim(strtr(base64_encode(substr($digest, 0, 16)), '+/', '-_'), '=');
        } else {
            // Preserve identities already created with the original Adelia # / ! syntax.
            $tripcode = hash_hmac('sha256', $parts[2], $key, true)
                |> base64_encode(...)
                |> (static fn(string $value): string => substr(strtr($value, '+/', '-_'), 0, 12));
        }
        return [$parts[0], $tripcode];
    }
}
