<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Finding\Finding;
use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Schema\Schema;

final readonly class ScanReport
{
    /**
     * @param list<PiiCandidate> $candidates
     * @param list<FlowPoint>    $flows
     * @param list<Finding>      $findings   most severe first
     */
    public function __construct(
        public Schema $schema,
        public array $candidates,
        public array $flows,
        public array $findings,
    ) {
    }
}
