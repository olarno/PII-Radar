<?php

declare(strict_types=1);

namespace App\Domain\Register;

use App\Domain\Rule\Jurisdiction;
use App\Domain\Schema\FieldReference;
use App\Domain\SourceLocation;

/**
 * Someone outside the application who receives personal data: an API, or the person themselves by e-mail.
 */
final readonly class Recipient
{
    /**
     * @param list<FieldReference> $fields
     * @param list<SourceLocation> $evidence
     * @param bool                 $dataSubject true when the data goes back to the person it describes (confirmation e-mail)
     */
    public function __construct(
        public string $name,
        public ?Jurisdiction $jurisdiction,
        public array $fields,
        public array $evidence,
        public bool $dataSubject = false,
    ) {
    }

    public function isOutsideQuebec(): bool
    {
        return !$this->dataSubject && Jurisdiction::Quebec !== $this->jurisdiction;
    }
}
