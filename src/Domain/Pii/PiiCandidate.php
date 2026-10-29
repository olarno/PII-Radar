<?php

declare(strict_types=1);

namespace App\Domain\Pii;

use App\Domain\Schema\Column;

/**
 * A column that deterministic analysis suspects of holding personal data.
 *
 * It is only a candidate: the agent confirms or rejects it (step 4).
 */
final readonly class PiiCandidate
{
    public function __construct(
        public Column $column,
        public PiiCategory $category,
        public Sensitivity $sensitivity,
        public float $confidence,
        public string $reason,
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException(\sprintf('Confidence must be between 0 and 1, got %s.', $confidence));
        }
    }
}
