<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Schema\Schema;

interface CandidateArbiter
{
    /**
     * Decides whether ambiguous candidates hold personal data. Only metadata and masked code may leave the process.
     *
     * @param list<PiiCandidate> $candidates
     * @param list<FlowPoint>    $flows
     *
     * @return Arbitration one decision per candidate, in any order, and what the arbitration cost
     *
     * @throws ArbitrationFailed
     */
    public function arbitrate(string $projectPath, Schema $schema, array $flows, array $candidates): Arbitration;
}
