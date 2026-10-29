<?php

declare(strict_types=1);

namespace App\Domain\Pii;

/**
 * A word sequence that, found in a column name, suggests personal data.
 */
final readonly class NameSignal
{
    /** @var non-empty-list<string> */
    public array $words;

    /**
     * @param non-empty-list<string> $words
     */
    public function __construct(
        array $words,
        public PiiCategory $category,
        public Sensitivity $sensitivity,
        public float $confidence,
    ) {
        $this->words = $words;
    }

    /**
     * @param list<string> $tokens
     */
    public function matches(array $tokens): bool
    {
        $length = \count($this->words);
        for ($start = 0, $last = \count($tokens) - $length; $start <= $last; ++$start) {
            if (\array_slice($tokens, $start, $length) === $this->words) {
                return true;
            }
        }

        return false;
    }

    public function label(): string
    {
        return implode('_', $this->words);
    }
}
