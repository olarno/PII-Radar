<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\AgentUsage;
use App\Domain\Pii\ClassifiedField;

final readonly class Arbitration
{
    /**
     * @param list<ClassifiedField> $decisions   one per candidate
     * @param ?string               $conclusions what the agent wrote at the end of its investigation
     * @param list<string>          $rawVerdicts the structured verdicts as returned, for diagnosis
     */
    public function __construct(
        public array $decisions,
        public ?AgentUsage $usage = null,
        public ?string $conclusions = null,
        public array $rawVerdicts = [],
    ) {
    }
}
