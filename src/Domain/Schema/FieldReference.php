<?php

declare(strict_types=1);

namespace App\Domain\Schema;

/**
 * Identifies a stored field as "table.column".
 */
final readonly class FieldReference
{
    public function __construct(
        public string $table,
        public string $column,
    ) {
        if ('' === $table || '' === $column) {
            throw new \InvalidArgumentException('A field reference needs a table and a column.');
        }
    }

    public static function fromString(string $reference): self
    {
        $parts = explode('.', $reference);
        if (2 !== \count($parts)) {
            throw new \InvalidArgumentException(\sprintf('Expected "table.column", got "%s".', $reference));
        }

        return new self($parts[0], $parts[1]);
    }

    public function equals(self $other): bool
    {
        return $this->table === $other->table && $this->column === $other->column;
    }

    public function __toString(): string
    {
        return $this->table.'.'.$this->column;
    }
}
