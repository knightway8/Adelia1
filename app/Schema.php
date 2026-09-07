<?php

declare(strict_types=1);

namespace Adelia;

final readonly class Schema
{
    public array $tables;

    public function __construct(Config $config)
    {
        $names = [$config->dbaccounts, $config->dbkeywords, $config->dblogs, $config->dbreports, $config->dbposts, 'adelia_jobs'];
        if (count(array_unique(array_map(strtolower(...), $names))) !== count($names)) {
            throw new \InvalidArgumentException('Storage table names must be distinct.');
        }
        foreach ($names as $name) {
            self::identifier($name);
            if (str_starts_with(strtolower($name), 'sqlite_')) {
                throw new \InvalidArgumentException('Reserved storage table name.');
            }
        }
        $posts = ['id' => 'int'];
        foreach (['parent', 'timestamp', 'bumped', 'file_size', 'image_width', 'image_height', 'thumb_width', 'thumb_height', 'moderated', 'stickied', 'locked', 'source_known'] as $field) {
            $posts[$field] = 'int';
        }
        foreach (['name', 'tripcode', 'email', 'nameblock', 'subject', 'message', 'message_source', 'password', 'file', 'file_hex', 'file_original', 'file_size_formatted', 'thumb'] as $field) {
            $posts[$field] = 'string';
        }
        $this->tables = [
            $config->dbaccounts => ['id' => 'int', 'username' => 'string', 'password' => 'string', 'role' => 'int', 'lastactive' => 'int'],
            $config->dbkeywords => ['id' => 'int', 'text' => 'string', 'action' => 'string'],
            $config->dblogs => ['id' => 'int', 'timestamp' => 'int', 'account' => 'int', 'message' => 'string'],
            $config->dbreports => ['id' => 'int', 'post' => 'int'],
            $config->dbposts => $posts,
            'adelia_jobs' => ['id' => 'int', 'kind' => 'string', 'path' => 'string'],
        ];
    }

    public static function identifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
            throw new \InvalidArgumentException('Invalid storage identifier.');
        }
        return '"' . $name . '"';
    }

    public function columns(string $table): array
    {
        return $this->tables[$table] ?? throw new \InvalidArgumentException('Unknown storage table.');
    }

    public function values(string $table, array $values, bool $partial = false, bool $withID = false): void
    {
        $columns = $this->columns($table);
        if (!$withID) {
            unset($columns['id']);
        }
        if (array_diff_key($values, $columns) !== [] || (!$partial && array_diff_key($columns, $values) !== [])) {
            throw new \InvalidArgumentException('Unexpected or missing record fields.');
        }
        foreach ($values as $field => $value) {
            if (($columns[$field] === 'int' && !is_int($value)) || ($columns[$field] === 'string' && (!is_string($value) || !mb_check_encoding($value, 'UTF-8')))) {
                throw new \InvalidArgumentException('Invalid record value for ' . $field . '.');
            }
        }
        if ($table === 'adelia_jobs' && !$partial) {
            if ($values['kind'] === 'media') {
                MediaJournal::validatePath($values['path']);
            } elseif ($values['kind'] !== 'rebuild' || $values['path'] !== '') {
                throw new \InvalidArgumentException('Invalid maintenance job.');
            }
        }
    }

    public function emptySnapshot(): array
    {
        return ['format' => 'adelia', 'version' => 1, 'tables' => array_fill_keys(array_keys($this->tables), ['next_id' => 1, 'rows' => []])];
    }

    public function validateSnapshot(array $snapshot): void
    {
        if (array_diff(array_keys($snapshot), ['format', 'version', 'tables']) !== [] || ($snapshot['format'] ?? null) !== 'adelia' || ($snapshot['version'] ?? null) !== 1 || !is_array($snapshot['tables'] ?? null)) {
            throw new \RuntimeException('Unsupported or damaged Adelia data file.');
        }
        if (array_diff_key($snapshot['tables'], $this->tables) !== [] || array_diff_key($this->tables, $snapshot['tables']) !== []) {
            throw new \RuntimeException('Storage tables do not match the configured board.');
        }
        foreach ($snapshot['tables'] as $table => $data) {
            if (!is_array($data) || count($data) !== 2 || !is_int($data['next_id'] ?? null) || $data['next_id'] < 1 || !is_array($data['rows'] ?? null) || !array_is_list($data['rows'])) {
                throw new \RuntimeException('Invalid storage table data.');
            }
            $ids = [];
            foreach ($data['rows'] as $row) {
                if (!is_array($row)) {
                    throw new \RuntimeException('Invalid storage record.');
                }
                $this->values($table, $row, withID: true);
                if ($row['id'] < 1 || $row['id'] >= $data['next_id'] || isset($ids[$row['id']])) {
                    throw new \RuntimeException('Invalid or duplicate storage record identifier.');
                }
                $ids[$row['id']] = true;
            }
        }
    }
}
