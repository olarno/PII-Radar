<?php

declare(strict_types=1);

namespace App\Domain\Register;

use App\Domain\SourceLocation;

final readonly class SecurityMeasure
{
    /**
     * @param list<SourceLocation> $evidence
     */
    public function __construct(
        public string $description,
        public array $evidence,
    ) {
    }
}
