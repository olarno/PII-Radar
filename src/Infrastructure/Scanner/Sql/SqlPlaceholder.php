<?php

declare(strict_types=1);

namespace App\Infrastructure\Scanner\Sql;

use App\Domain\Schema\FieldReference;

/**
 * A value injected into an SQL string by the PHP code.
 */
final readonly class SqlPlaceholder
{
    /**
     * @param bool                 $safe   true when the value cannot carry text (cast to int, numeric literal)
     * @param list<FieldReference> $fields the stored fields the value is known to carry
     */
    public function __construct(
        public bool $safe,
        public array $fields = [],
    ) {
    }
}
