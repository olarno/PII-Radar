<?php

declare(strict_types=1);

namespace App\Domain\Pii;

use App\Domain\Schema\Column;

/**
 * The final call on a candidate column: personal data or not, with a one-sentence justification.
 */
final readonly class ClassifiedField
{
    public function __construct(
        public Column $column,
        public bool $personal,
        public PiiCategory $category,
        public Sensitivity $sensitivity,
        public float $confidence,
        public string $justification,
        public DecisionSource $decidedBy,
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException(\sprintf('Confidence must be between 0 and 1, got %s.', $confidence));
        }
    }

    /**
     * Without arbitration, the heuristic decides alone (decision 12: personal data from 0.5 confidence).
     */
    public static function fromCandidate(PiiCandidate $candidate): self
    {
        return new self(
            $candidate->column,
            $candidate->confidence >= 0.5,
            $candidate->category,
            $candidate->sensitivity,
            $candidate->confidence,
            $candidate->reason,
            DecisionSource::Rules,
        );
    }
}
