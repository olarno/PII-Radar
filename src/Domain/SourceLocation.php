<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where something was found in the audited project: a path relative to the project root, and a line when known.
 */
final readonly class SourceLocation
{
    public function __construct(
        public string $file,
        public ?int $line = null,
    ) {
        if ('' === $file) {
            throw new \InvalidArgumentException('A source location needs a file.');
        }
        if (null !== $line && $line < 1) {
            throw new \InvalidArgumentException(\sprintf('Line numbers start at 1, got %d.', $line));
        }
    }

    public function __toString(): string
    {
        return null === $this->line ? $this->file : $this->file.':'.$this->line;
    }
}
