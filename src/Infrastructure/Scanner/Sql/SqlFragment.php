<?php

declare(strict_types=1);

namespace App\Infrastructure\Scanner\Sql;

/**
 * A string built by PHP code, split into literal text and injected values.
 */
final readonly class SqlFragment
{
    /**
     * @param list<string|SqlPlaceholder> $parts
     * @param int                         $line  where the expression building the string starts
     */
    public function __construct(
        public array $parts,
        public int $line,
    ) {
    }

    public function append(self $other): self
    {
        return new self([...$this->parts, ...$other->parts], $this->line);
    }

    /**
     * The literal text only, injected values replaced by a neutral marker.
     */
    public function text(): string
    {
        return implode('', array_map(static fn (string|SqlPlaceholder $part): string => \is_string($part) ? $part : '?', $this->parts));
    }
}
