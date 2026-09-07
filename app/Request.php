<?php

declare(strict_types=1);

namespace Adelia;

final class Request
{
    public static function integer(mixed $value): int
    {
        if ($value === '') {
            return 0;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 18) {
            throw new BoardMessage('A non-negative integer is required.');
        }
        return (int) $value;
    }

    public function queryInt(string $key): int
    {
        return self::integer($this->query[$key] ?? 0);
    }

    public function formInt(string $key): int
    {
        return self::integer($this->form[$key] ?? 0);
    }

    /** @return list<int> */
    public static function ids(string $value): array
    {
        return array_map(self::integer(...), explode(',', $value));
    }

    /** @return list<int> */
    public static function deletions(mixed $value): array
    {
        $values = is_string($value) ? [$value] : $value;
        if (!is_array($values) || !array_is_list($values) || $values === []) {
            throw new BoardMessage('Select valid post identifiers.');
        }
        $ids = [];
        foreach ($values as $item) {
            if (!is_string($item) || ($id = self::integer($item)) < 1) {
                throw new BoardMessage('Select valid post identifiers.');
            }
            $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    /** @param array<string, string|list<string>> $query
     * @param array<string, string|list<string>> $form
     * @param array<string, array<string, int|string>> $files
     * @param array<string, string> $server */
    public function __construct(
        public array $query,
        public array $form,
        public readonly array $files,
        public readonly array $server,
    ) {}

    #[\NoDiscard]
    public static function capture(): self
    {
        $normalize = static function (array $input, bool $form): array {
            foreach ($input as $key => $value) {
                if ($form && $key === 'delete') {
                    self::deletions($value);
                    continue;
                }
                if (is_array($value)) {
                    throw new BoardMessage('Invalid request field.');
                } elseif (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                    throw new BoardMessage('Invalid request field.');
                }
            }
            return $input;
        };
        $files = [];
        foreach ($_FILES as $key => $file) {
            if ($key !== 'file' || !is_array($file)
                || !is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null)
                || !is_string($file['type'] ?? null) || !is_int($file['error'] ?? null) || !is_int($file['size'] ?? null)
                || !mb_check_encoding($file['name'], 'UTF-8') || str_contains($file['name'], "\0")
                || $file['size'] < 0 || !in_array($file['error'], [0, 1, 2, 3, 4, 6, 7, 8], true)) {
                throw new BoardMessage('Invalid upload field.');
            }
            $files[$key] = array_intersect_key($file, array_flip(['name', 'tmp_name', 'type', 'error', 'size']));
        }
        $server = array_intersect_key(array_filter($_SERVER, is_string(...)), array_flip(['REQUEST_METHOD', 'HTTPS']));
        $server['PHP_SELF'] = '/imgboard.php';
        $server['REQUEST_METHOD'] ??= 'GET';
        return new self($normalize($_GET, false), $normalize($_POST, true), $files, $server);
    }
}
