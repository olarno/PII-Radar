<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A data file (CSV export, SQL dump, backup) found in a publicly served directory of the audited project.
 */
final readonly class DataFile
{
    /**
     * @param list<string> $columns column names read from the file header, when it has one
     */
    public function __construct(
        public SourceLocation $location,
        public array $columns = [],
    ) {
    }
}
