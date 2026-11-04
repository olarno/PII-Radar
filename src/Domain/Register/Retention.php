<?php

declare(strict_types=1);

namespace App\Domain\Register;

use App\Domain\SourceLocation;

/**
 * Whether the code ever deletes from a table holding personal data. The duration itself is never deduced.
 */
final readonly class Retention
{
    /**
     * @param list<SourceLocation> $deletions
     */
    public function __construct(
        public string $table,
        public SourceLocation $location,
        public int $personalFields,
        public array $deletions,
    ) {
    }
}
