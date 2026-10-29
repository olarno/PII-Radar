<?php

declare(strict_types=1);

namespace App\Domain\Schema;

/**
 * The tables of the audited application, as declared in its SQL files.
 */
final readonly class Schema
{
    /** @var array<string, Table> */
    private array $tables;

    /**
     * @param list<Table> $tables
     */
    public function __construct(array $tables)
    {
        $indexed = [];
        foreach ($tables as $table) {
            // When a table is declared twice (several dumps), the last declaration wins.
            $indexed[$table->name] = $table;
        }
        $this->tables = $indexed;
    }

    /**
     * @return list<Table>
     */
    public function tables(): array
    {
        return array_values($this->tables);
    }

    public function table(string $name): ?Table
    {
        return $this->tables[$name] ?? null;
    }

    /**
     * @return list<Column>
     */
    public function columns(): array
    {
        return array_merge([], ...array_map(static fn (Table $table): array => $table->columns, $this->tables()));
    }
}
