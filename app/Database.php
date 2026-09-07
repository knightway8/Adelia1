<?php

declare(strict_types=1);

namespace Adelia;

final readonly class Database
{
    private Schema $schema;
    private RecordStore $store;

    public function __construct(Config $config, bool $create = false, ?array $initial = null)
    {
        $this->schema = new Schema($config);
        $this->store = match ($config->dbdriver) {
            'sqlite' => new SQLiteStore($config, $this->schema),
            'flatfile' => $create ? FlatFileStore::create($config->dbpath, $this->schema, $initial) : new FlatFileStore($config->dbpath, $this->schema),
            default => throw new \InvalidArgumentException('Use sqlite or flatfile storage.'),
        };
        if ($create && $initial !== null && $config->dbdriver === 'sqlite') {
            $this->import($initial);
        }
    }

    public function begin(): void
    {
        $this->store->begin();
    }
    public function commit(): void
    {
        $this->store->commit();
    }
    public function rollback(): void
    {
        $this->store->rollback();
    }

    public function schedule(string $kind, string $path = ''): void
    {
        if ($this->row('adelia_jobs', Filter::all(Filter::equal('kind', $kind), Filter::equal('path', $path))) === []) {
            $this->insert('adelia_jobs', ['kind' => $kind, 'path' => $path]);
        }
    }

    /** @param array<string, string> $order Column names and directions are validated at runtime. */
    public function rows(string $table, ?Filter $filter = null, array $order = [], ?int $limit = null, int $offset = 0): array
    {
        $columns = $this->schema->columns($table);
        $filter?->validate($this->schema, $table);
        foreach ($order as $column => $direction) {
            if (!isset($columns[$column]) || !in_array($direction, ['ASC', 'DESC'], true)) {
                throw new \InvalidArgumentException('Invalid storage ordering.');
            }
        }
        if ($offset < 0 || ($limit !== null && $limit < 0)) {
            throw new \InvalidArgumentException('Storage limits must be nonnegative.');
        }
        return $this->store->rows($table, $filter, $order + ['id' => 'ASC'], $limit, $offset);
    }

    public function row(string $table, ?Filter $filter = null): array
    {
        return array_first($this->rows($table, $filter, limit: 1)) ?? [];
    }

    /** @phpstan-impure Storage contents may change between calls. */
    public function count(string $table, ?Filter $filter = null): int
    {
        $this->schema->columns($table);
        $filter?->validate($this->schema, $table);
        return $this->store->count($table, $filter);
    }

    public function insert(string $table, array $values): int
    {
        $this->schema->values($table, $values);
        return $this->store->insert($table, $values);
    }

    public function update(string $table, int $id, array $values): void
    {
        $this->schema->values($table, $values, partial: true);
        if ($id < 1) {
            throw new \InvalidArgumentException('Invalid storage record identifier.');
        }
        if ($values !== []) {
            $this->store->update($table, $id, $values);
        }
    }

    public function delete(string $table, Filter $filter): void
    {
        $this->schema->columns($table);
        $filter->validate($this->schema, $table);
        $this->store->delete($table, $filter);
    }

    public function snapshot(): array
    {
        $snapshot = $this->store->snapshot();
        $this->schema->validateSnapshot($snapshot);
        return $snapshot;
    }

    public function import(array $snapshot): void
    {
        $this->schema->validateSnapshot($snapshot);
        $this->store->import($snapshot);
    }
}
