<?php

declare(strict_types=1);

namespace Adelia;

final class FlatFileStore implements RecordStore
{
    private const int MAX_DATA = 16 * 1024 * 1024;
    private const int MAX_ENVELOPE = 2 * self::MAX_DATA + 4096;
    private readonly string $path;
    private array $head = [];
    private array $data = [];
    private array $baseline = [];
    private array $indexes = [];
    private ?StorageLock $lock = null;
    private bool $dirty = false;

    public function __construct(string $path, private readonly Schema $schema)
    {
        $this->path = self::path($path);
        $this->refresh();
    }

    public static function path(string $path): string
    {
        if (str_contains($path, "\0") || str_contains($path, '://') || !preg_match('/^\.[a-zA-Z0-9_-]+\.json$/D', basename($path))) {
            throw new \InvalidArgumentException('Use a hidden local JSON filename, such as .adelia.json.');
        }
        $directory = realpath(dirname($path));
        if ($directory === false || !is_dir($directory) || is_link(dirname($path))) {
            throw new \InvalidArgumentException('Storage needs an existing local directory.');
        }
        return $directory . DIRECTORY_SEPARATOR . basename($path);
    }

    public static function create(string $path, Schema $schema, ?array $snapshot = null): self
    {
        $path = self::path($path);
        $lock = new StorageLock($path . '.lock');
        foreach ([$path, $path . '.head', $path . '.journal'] as $file) {
            if (file_exists($file) || is_link($file)) {
                throw new \RuntimeException('Storage already exists or an interrupted initialization needs inspection. Nothing was overwritten.');
            }
        }
        $snapshot ??= $schema->emptySnapshot();
        $schema->validateSnapshot($snapshot);
        [$head, $envelope] = self::encode($snapshot, bin2hex(random_bytes(16)), 1);
        StorageFiles::replace($path . '.journal', $envelope);
        StorageFiles::replace($path, $envelope);
        StorageFiles::replace($path . '.head', self::json($head));
        $lock->release();
        return new self($path, $schema);
    }

    public static function upgrade(string $path, Schema $schema): void
    {
        $path = self::path($path);
        $lock = new StorageLock($path . '.lock');
        if (file_exists($path . '.head')) {
            throw new \RuntimeException('Storage already has a commit record. Use the check command.');
        }
        $legacy = StorageFiles::read($path, self::MAX_DATA);
        $snapshot = json_decode($legacy, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || ($snapshot['format'] ?? null) !== 'adelia' || ($snapshot['version'] ?? null) !== 1) {
            throw new \RuntimeException('This is not an Adelia version 1 JSON database.');
        }
        $snapshot['tables']['adelia_jobs'] ??= ['next_id' => 1, 'rows' => []];
        $schema->validateSnapshot($snapshot);
        $backup = $path . '.legacy';
        if (file_exists($backup)) {
            if (StorageFiles::read($backup, self::MAX_DATA) !== $legacy) {
                throw new \RuntimeException('The legacy backup differs; inspect it before upgrading.');
            }
        } else {
            StorageFiles::replace($backup, $legacy);
        }
        [$head, $envelope] = self::encode($snapshot, bin2hex(random_bytes(16)), 1);
        StorageFiles::replace($path . '.journal', $envelope);
        StorageFiles::replace($path . '.head', self::json($head));
        StorageFiles::replace($path, $envelope);
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private static function encode(array $snapshot, string $id, int $revision): array
    {
        $payload = self::json($snapshot);
        if (strlen($payload) > self::MAX_DATA) {
            throw new \RuntimeException('Flat-file records exceed 16 MiB. Convert to SQLite before adding more data.');
        }
        $head = ['format' => 'adelia-commit', 'version' => 2, 'store' => $id, 'revision' => $revision, 'sha256' => hash('sha256', $payload)];
        return [$head, self::json(['commit' => $head, 'payload' => $payload])];
    }

    private function readHead(): array
    {
        if (!is_file($this->path . '.head')) {
            throw new \RuntimeException('The storage commit record is missing. Initialize a new board explicitly, or inspect/upgrade existing storage. An empty board was not created.');
        }
        $head = json_decode(StorageFiles::read($this->path . '.head', 2048), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($head) || count($head) !== 5 || ($head['format'] ?? null) !== 'adelia-commit' || ($head['version'] ?? null) !== 2 || !is_string($head['store'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $head['store']) || !is_int($head['revision'] ?? null) || $head['revision'] < 1 || !is_string($head['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $head['sha256'])) {
            throw new \RuntimeException('The storage commit record is damaged. Recovery requires inspection; no older data was substituted.');
        }
        return $head;
    }

    private function candidate(string $path, array $head): ?array
    {
        try {
            $encoded = StorageFiles::read($path, self::MAX_ENVELOPE);
            $envelope = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($envelope) || count($envelope) !== 2 || ($envelope['commit'] ?? null) !== $head || !is_string($envelope['payload'] ?? null) || strlen($envelope['payload']) > self::MAX_DATA || !hash_equals($head['sha256'], hash('sha256', $envelope['payload']))) {
                return null;
            }
            return [$envelope['payload'], $encoded];
        } catch (\JsonException|\RuntimeException|\ErrorException) {
            return null;
        }
    }

    private function load(bool $repair): void
    {
        $head = $this->readHead();
        if (!$repair && $this->head === $head && $this->data !== []) {
            return;
        }
        $main = $this->candidate($this->path, $head);
        $candidate = $main ?? $this->candidate($this->path . '.journal', $head);
        if ($candidate === null) {
            throw new \RuntimeException('Neither storage copy matches the last committed revision. Restore a verified backup; no older revision was selected.');
        }
        $data = $this->head === $head && $this->data !== [] ? $this->data : json_decode($candidate[0], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('The committed storage payload is invalid.');
        }
        if ($this->head !== $head || $this->data === []) {
            $this->schema->validateSnapshot($data);
        }
        if ($repair && $main === null) {
            // The old committed copy must be durable before the next journal is prepared.
            StorageFiles::replace($this->path, $candidate[1]);
        }
        $this->head = $head;
        $this->data = $data;
        $this->indexes = [];
    }

    public function refresh(): void
    {
        if ($this->lock !== null) {
            return;
        }
        $lock = new StorageLock($this->path . '.lock', LOCK_SH);
        $this->load(false);
    }

    public function begin(): void
    {
        if ($this->lock !== null) {
            throw new \LogicException('Nested storage transactions are not supported.');
        }
        $this->lock = new StorageLock($this->path . '.lock');
        try {
            $this->load(true);
            $this->baseline = $this->data;
            $this->dirty = false;
        } catch (\Throwable $exception) {
            $this->lock->release();
            $this->lock = null;
            throw $exception;
        }
    }

    public function commit(): void
    {
        if ($this->lock === null) {
            throw new \LogicException('No active storage transaction.');
        }
        $committed = false;
        $next = [];
        try {
            if (!$this->dirty) {
                return;
            }
            $this->schema->validateSnapshot($this->data);
            if ($this->head['revision'] >= PHP_INT_MAX) {
                throw new \RuntimeException('Storage revision counter is exhausted.');
            }
            [$next, $envelope] = self::encode($this->data, $this->head['store'], $this->head['revision'] + 1);
            StorageFiles::replace($this->path . '.journal', $envelope);
            StorageFiles::replace($this->path . '.head', self::json($next));
            $committed = true;
            $this->head = $next;
            try {
                StorageFiles::replace($this->path, $envelope);
            } catch (\Throwable $exception) {
                // The checked journal and head already contain the complete committed change.
                error_log('Adelia will repair its committed storage copy on the next write: ' . $exception->getMessage());
            }
        } catch (\Throwable $exception) {
            if ($next !== []) {
                try {
                    $committed = $this->readHead() === $next;
                } catch (\Throwable) {
                    $this->head = [];
                }
            }
            if ($committed) {
                $this->head = $next;
                throw new CommitUncertain('The commit record was published but its durability confirmation failed. Reopen storage and inspect the saved result before retrying.', previous: $exception);
            }
            $this->data = $this->baseline;
            throw $exception;
        } finally {
            $this->baseline = [];
            $this->dirty = false;
            $this->indexes = [];
            $this->lock->release();
            $this->lock = null;
        }
    }

    public function rollback(): void
    {
        if ($this->lock !== null) {
            $this->data = $this->baseline;
            $this->baseline = [];
            $this->indexes = [];
            $this->dirty = false;
            $this->lock->release();
            $this->lock = null;
        }
    }

    private function mutate(string $table, \Closure $operation): mixed
    {
        $automatic = $this->lock === null;
        if ($automatic) {
            $this->begin();
        }
        try {
            $result = $operation();
            unset($this->indexes[$table]);
            if ($automatic) {
                $this->commit();
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($automatic) {
                $this->rollback();
            }
            throw $exception;
        }
    }

    private function selected(string $table, ?Filter $filter): array
    {
        $this->refresh();
        if (!isset($this->indexes[$table])) {
            $maps = [];
            $rows = [];
            foreach ($this->data['tables'][$table]['rows'] as $row) {
                $rows[$row['id']] = $row;
                foreach (['id', 'parent', 'username', 'post', 'file_hex', 'kind', 'path'] as $field) {
                    if (isset($row[$field])) {
                        $maps[$field][Filter::key($row[$field])][$row['id']] = true;
                    }
                }
            }
            $this->indexes[$table] = ['rows' => $rows, 'maps' => $maps];
        }
        $index = $this->indexes[$table];
        $ids = $filter?->candidates($index['maps']);
        $rows = $ids === null ? $index['rows'] : [];
        if ($ids !== null) {
            foreach ($ids as $id => $_) {
                if (isset($index['rows'][$id])) {
                    $rows[$id] = $index['rows'][$id];
                }
            }
        }
        return $filter === null ? array_values($rows) : array_values(array_filter($rows, $filter->matches(...)));
    }

    public function rows(string $table, ?Filter $filter, array $order, ?int $limit, int $offset): array
    {
        $rows = $this->selected($table, $filter);
        if (count($rows) > 1) {
            usort($rows, static function (array $left, array $right) use ($order): int {
                foreach ($order as $field => $direction) {
                    $comparison = is_int($left[$field]) ? $left[$field] <=> $right[$field] : strcmp($left[$field], $right[$field]);
                    if ($comparison !== 0) {
                        return $direction === 'DESC' ? -$comparison : $comparison;
                    }
                }
                return 0;
            });
        }
        return array_slice($rows, $offset, $limit);
    }

    public function count(string $table, ?Filter $filter): int
    {
        return count($this->selected($table, $filter));
    }

    public function insert(string $table, array $values): int
    {
        return $this->mutate($table, function () use ($table, $values): int {
            $id = $this->data['tables'][$table]['next_id'];
            if ($id >= PHP_INT_MAX) {
                throw new \RuntimeException('Record identifiers are exhausted.');
            }
            $this->data['tables'][$table]['next_id']++;
            $this->data['tables'][$table]['rows'][] = ['id' => $id, ...$values];
            $this->dirty = true;
            return $id;
        });
    }

    public function update(string $table, int $id, array $values): void
    {
        $this->mutate($table, function () use ($table, $id, $values): void {
            foreach ($this->data['tables'][$table]['rows'] as &$row) {
                if ($row['id'] === $id) {
                    $updated = array_replace($row, $values);
                    if ($updated !== $row) {
                        $row = $updated;
                        $this->dirty = true;
                    }
                    break;
                }
            }
        });
    }

    public function delete(string $table, Filter $filter): void
    {
        $this->mutate($table, function () use ($table, $filter): void {
            $rows = $this->data['tables'][$table]['rows'];
            $remaining = array_values(array_filter($rows, static fn(array $row): bool => !$filter->matches($row)));
            if (count($remaining) !== count($rows)) {
                $this->data['tables'][$table]['rows'] = $remaining;
                $this->dirty = true;
            }
        });
    }

    public function snapshot(): array
    {
        $this->refresh();
        return $this->data;
    }

    public function import(array $snapshot): void
    {
        $this->mutate('', function () use ($snapshot): void {
            foreach ($this->data['tables'] as $table) {
                if ($table['rows'] !== []) {
                    throw new \RuntimeException('Import requires empty storage.');
                }
            }
            $this->data = $snapshot;
            $this->indexes = [];
            $this->dirty = true;
        });
    }

    public function __destruct()
    {
        $this->rollback();
    }
}
