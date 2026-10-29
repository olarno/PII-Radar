<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Pii\PiiCandidate;
use App\Domain\Schema\Schema;

final readonly class SchemaScanResult
{
    /**
     * @param list<PiiCandidate> $candidates
     */
    public function __construct(
        public Schema $schema,
        public array $candidates,
    ) {
    }
}
