<?php

declare(strict_types=1);

namespace App\Domain\Pii;

enum DecisionSource: string
{
    /** Name heuristic alone, confident enough not to need the agent. */
    case Rules = 'rules';
    /** Arbitrated by the agent. */
    case Agent = 'agent';
}
