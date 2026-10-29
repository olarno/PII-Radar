<?php

declare(strict_types=1);

namespace App\Domain\Schema;

use App\Domain\SourceLocation;

final readonly class Table
{
    /**
     * @param list<Column> $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public SourceLocation $location,
    ) {
        foreach ($columns as $column) {
            if ($column->reference->table !== $name) {
                throw new \InvalidArgumentException(\sprintf('Column "%s" does not belong to table "%s".', $column->reference, $name));
            }
        }
    }
}
