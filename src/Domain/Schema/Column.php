<?php

declare(strict_types=1);

namespace App\Domain\Schema;

use App\Domain\SourceLocation;

final readonly class Column
{
    public function __construct(
        public FieldReference $reference,
        public string $type,
        public bool $nullable,
        public SourceLocation $location,
    ) {
    }

    public function name(): string
    {
        return $this->reference->column;
    }
}
