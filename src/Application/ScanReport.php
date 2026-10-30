<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Finding\Finding;
use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Schema\Schema;

final readonly class ScanReport
{
    /**
     * @param list<PiiCandidate>    $candidates       what the name heuristic suspects
     * @param list<ClassifiedField> $classifiedFields the final call on every candidate
     * @param list<FlowPoint>       $flows
     * @param list<Finding>         $findings         most severe first
     * @param bool                  $arbitrated       whether the agent arbitrated the ambiguous candidates
     */
    public function __construct(
        public Schema $schema,
        public array $candidates,
        public array $classifiedFields,
        public array $flows,
        public array $findings,
        public bool $arbitrated,
    ) {
    }
}
