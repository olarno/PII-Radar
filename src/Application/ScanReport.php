<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Schema\Schema;

final readonly class ScanReport
{
    /**
     * @param list<PiiCandidate> $candidates
     * @param list<FlowPoint>    $flows
     */
    public function __construct(
        public Schema $schema,
        public array $candidates,
        public array $flows,
    ) {
    }
}
