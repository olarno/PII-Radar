<?php

declare(strict_types=1);

namespace App\Infrastructure\Scanner\Sql;

use App\Domain\Flow\FlowOperation;
use App\Domain\Schema\FieldReference;

final readonly class SqlStatement
{
    /**
     * @param list<FieldReference>          $fields             columns read, written or deleted
     * @param array<string, FieldReference> $row                for a SELECT, the key of each column in a fetched row
     * @param list<FieldReference>          $concatenatedFields fields whose value is concatenated into the statement
     */
    public function __construct(
        public FlowOperation $operation,
        public string $table,
        public array $fields,
        public array $row = [],
        public array $concatenatedFields = [],
    ) {
    }
}
