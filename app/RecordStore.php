<?php

declare(strict_types=1);

namespace Adelia;

interface RecordStore
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function rows(string $table, ?Filter $filter, array $order, ?int $limit, int $offset): array;
    public function count(string $table, ?Filter $filter): int;
    public function insert(string $table, array $values): int;
    public function update(string $table, int $id, array $values): void;
    public function delete(string $table, Filter $filter): void;
    public function snapshot(): array;
    public function import(array $snapshot): void;
}
