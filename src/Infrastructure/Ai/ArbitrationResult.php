<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

final class ArbitrationResult
{
    /** @var list<FieldVerdict> one verdict per candidate column */
    public array $verdicts = [];
}
