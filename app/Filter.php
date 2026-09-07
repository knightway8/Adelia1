<?php

declare(strict_types=1);

namespace Adelia;

final readonly class Filter
{
    private function __construct(
        private FilterOperator $operator,
        private string $field = '',
        private int|string $value = 0,
        private array $children = [],
    ) {}

    public static function equal(string $field, int|string $value): self
    {
        return new self(FilterOperator::Equal, $field, $value);
    }

    public static function greater(string $field, int $value): self
    {
        return new self(FilterOperator::Greater, $field, $value);
    }

    public static function notEqual(string $field, int|string $value): self
    {
        return new self(FilterOperator::NotEqual, $field, $value);
    }

    public static function all(self ...$children): self
    {
        return new self(FilterOperator::All, children: $children);
    }

    public static function any(self ...$children): self
    {
        return new self(FilterOperator::Any, children: $children);
    }

    public function validate(Schema $schema, string $table): void
    {
        if (in_array($this->operator, [FilterOperator::All, FilterOperator::Any], true)) {
            foreach ($this->children as $child) {
                $child->validate($schema, $table);
            }
            return;
        }
        $schema->values($table, [$this->field => $this->value], partial: true, withID: true);
    }

    public function matches(array $row): bool
    {
        return match ($this->operator) {
            FilterOperator::All => array_all($this->children, static fn(self $child): bool => $child->matches($row)),
            FilterOperator::Any => array_any($this->children, static fn(self $child): bool => $child->matches($row)),
            FilterOperator::Equal => $row[$this->field] === $this->value,
            FilterOperator::NotEqual => $row[$this->field] !== $this->value,
            FilterOperator::Greater => $row[$this->field] > $this->value,
        };
    }

    public static function key(int|string $value): string
    {
        return (is_int($value) ? 'i:' : 's:') . $value;
    }

    /** @return array<int, true>|null Null means that scanning is needed. */
    public function candidates(array $indexes): ?array
    {
        if ($this->operator === FilterOperator::Equal && isset($indexes[$this->field])) {
            return $indexes[$this->field][self::key($this->value)] ?? [];
        }
        if ($this->operator === FilterOperator::All) {
            $result = null;
            foreach ($this->children as $child) {
                $subset = $child->candidates($indexes);
                if ($subset !== null) {
                    $result = $result === null ? $subset : array_intersect_key($result, $subset);
                }
            }
            return $result;
        }
        if ($this->operator === FilterOperator::Any) {
            $result = [];
            foreach ($this->children as $child) {
                $subset = $child->candidates($indexes);
                if ($subset === null) {
                    return null;
                }
                $result += $subset;
            }
            return $result;
        }
        return null;
    }

    /** @param list<int|string> $parameters */
    public function sql(array &$parameters): string
    {
        if (in_array($this->operator, [FilterOperator::All, FilterOperator::Any], true)) {
            if ($this->children === []) {
                return $this->operator === FilterOperator::All ? '1 = 1' : '1 = 0';
            }
            $parts = [];
            foreach ($this->children as $child) {
                $parts[] = $child->sql($parameters);
            }
            return '(' . implode(' ' . $this->operator->value . ' ', $parts) . ')';
        }
        $parameters[] = $this->value;
        return Schema::identifier($this->field) . ' ' . $this->operator->value . ' ?';
    }
}
