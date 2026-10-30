<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\AgentUsage;
use App\Domain\Pii\ClassifiedField;

final readonly class Arbitration
{
    /**
     * @param list<ClassifiedField> $decisions one per candidate
     */
    public function __construct(
        public array $decisions,
        public ?AgentUsage $usage = null,
    ) {
    }
}
