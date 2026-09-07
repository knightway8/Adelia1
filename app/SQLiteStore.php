<?php

declare(strict_types=1);

namespace Adelia;

use PDO;
use PDOStatement;

final readonly class SQLiteStore implements RecordStore
{
    private PDO $connection;

    public function __construct(Config $config, private Schema $schema)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException('SQLite storage requires the pdo_sqlite PHP extension.');
        }
        if ($config->dbpath === '' || str_contains($config->dbpath, "\0") || str_contains($config->dbpath, '://') || str_starts_with($config->dbpath, 'file:')) {
            throw new \InvalidArgumentException('Use a local SQLite file path.');
        }
        $this->connection = new PDO('sqlite:' . $config->dbpath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->connection->exec('PRAGMA busy_timeout = 60000');
        $this->connection->exec('PRAGMA foreign_keys = ON');
        foreach ($schema->tables as $table => $columns) {
            $definitions = ['"id" INTEGER PRIMARY KEY AUTOINCREMENT'];
            foreach ($columns as $column => $type) {
                if ($column !== 'id') {
                    $definitions[] = Schema::identifier($column) . ($type === 'int' ? ' INTEGER' : ' TEXT') . ' NOT NULL';
                }
            }
            $this->connection->exec('CREATE TABLE IF NOT EXISTS ' . Schema::identifier($table) . ' (' . implode(', ', $definitions) . ')');
        }
        foreach (['parent', 'bumped', 'moderated'] as $column) {
            $this->connection->exec('CREATE INDEX IF NOT EXISTS ' . Schema::identifier($config->dbposts . '_' . $column) . ' ON ' . Schema::identifier($config->dbposts) . ' (' . Schema::identifier($column) . ')');
        }
    }

    public function begin(): void
    {
        if ($this->connection->inTransaction()) {
            throw new \LogicException('Nested storage transactions are not supported.');
        }
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollback(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

    /** @param list<int|string> $parameters */
    private function statement(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->connection->prepare($sql);
        foreach ($parameters as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
        return $statement;
    }

    public function rows(string $table, ?Filter $filter, array $order, ?int $limit, int $offset): array
    {
        $parameters = [];
        $sql = 'SELECT * FROM ' . Schema::identifier($table);
        if ($filter !== null) {
            $sql .= ' WHERE ' . $filter->sql($parameters);
        }
        $sorting = [];
        foreach ($order as $column => $direction) {
            $sorting[] = Schema::identifier($column) . ' ' . $direction;
        }
        if ($sorting !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $sorting);
        }
        $sql .= ' LIMIT ? OFFSET ?';
        return $this->statement($sql, [...$parameters, $limit ?? -1, $offset])->fetchAll();
    }

    public function count(string $table, ?Filter $filter): int
    {
        $parameters = [];
        $sql = 'SELECT COUNT(*) FROM ' . Schema::identifier($table);
        if ($filter !== null) {
            $sql .= ' WHERE ' . $filter->sql($parameters);
        }
        return (int) $this->statement($sql, $parameters)->fetchColumn();
    }

    public function insert(string $table, array $values): int
    {
        $columns = array_map(Schema::identifier(...), array_keys($values));
        $this->statement('INSERT INTO ' . Schema::identifier($table) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')', array_values($values));
        return (int) $this->connection->lastInsertId();
    }

    public function update(string $table, int $id, array $values): void
    {
        $columns = array_map(static fn(string $column): string => Schema::identifier($column) . ' = ?', array_keys($values));
        $this->statement('UPDATE ' . Schema::identifier($table) . ' SET ' . implode(', ', $columns) . ' WHERE id = ?', [...array_values($values), $id]);
    }

    public function delete(string $table, Filter $filter): void
    {
        $parameters = [];
        $sql = 'DELETE FROM ' . Schema::identifier($table) . ' WHERE ' . $filter->sql($parameters);
        $this->statement($sql, $parameters);
    }

    public function snapshot(): array
    {
        $automatic = !$this->connection->inTransaction();
        if ($automatic) {
            $this->begin();
        }
        try {
            $snapshot = $this->schema->emptySnapshot();
            $hasSequence = (int) $this->statement("SELECT COUNT(*) FROM sqlite_master WHERE name = 'sqlite_sequence'")->fetchColumn() > 0;
            foreach ($snapshot['tables'] as $table => &$data) {
                $data['rows'] = $this->rows($table, null, ['id' => 'ASC'], null, 0);
                $maximum = $data['rows'] === [] ? 0 : max(array_column($data['rows'], 'id'));
                $sequence = $hasSequence ? (int) $this->statement('SELECT seq FROM sqlite_sequence WHERE name = ?', [$table])->fetchColumn() : 0;
                $data['next_id'] = max($maximum, $sequence) + 1;
            }
            unset($data);
            if ($automatic) {
                $this->commit();
            }
            return $snapshot;
        } catch (\Throwable $exception) {
            if ($automatic) {
                $this->rollback();
            }
            throw $exception;
        }
    }

    public function import(array $snapshot): void
    {
        $automatic = !$this->connection->inTransaction();
        if ($automatic) {
            $this->begin();
        }
        try {
            foreach (array_keys($this->schema->tables) as $table) {
                if ($this->count($table, null) !== 0) {
                    throw new \RuntimeException('Import requires empty storage; existing records were not changed.');
                }
            }
            foreach ($snapshot['tables'] as $table => $data) {
                foreach ($data['rows'] as $row) {
                    $this->insert($table, $row);
                }
                $this->statement('DELETE FROM sqlite_sequence WHERE name = ?', [$table]);
                $this->statement('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)', [$table, $data['next_id'] - 1]);
            }
            if ($automatic) {
                $this->commit();
            }
        } catch (\Throwable $exception) {
            if ($automatic) {
                $this->rollback();
            }
            throw $exception;
        }
    }
}
