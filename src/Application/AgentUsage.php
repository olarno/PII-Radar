<?php

declare(strict_types=1);

namespace App\Application;

/**
 * What one arbitration cost: calls to the model and tokens, as reported by the platform.
 */
final readonly class AgentUsage
{
    public function __construct(
        public int $calls,
        public ?int $inputTokens,
        public ?int $outputTokens,
    ) {
    }
}
