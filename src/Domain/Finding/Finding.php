<?php

declare(strict_types=1);

namespace App\Domain\Finding;

use App\Domain\Flow\FlowPoint;
use App\Domain\Schema\FieldReference;
use App\Domain\SourceLocation;

/**
 * A personal data risk found by a rule, with its evidence and the fix to apply.
 */
final readonly class Finding
{
    /** @var list<FieldReference> */
    public array $fields;

    /**
     * @param string               $rule         stable rule identifier, e.g. "PII-002"
     * @param list<FieldReference> $fields       the personal data concerned
     * @param ?string              $recipient    who receives the data, for disclosures (PII-003, PII-008)
     * @param ?string              $jurisdiction where the recipient is, for PII-008 ("US", "CA", or null when unknown)
     */
    public function __construct(
        public string $rule,
        public Severity $severity,
        public SourceLocation $location,
        array $fields,
        public string $summary,
        public string $remediation,
        public ?string $recipient = null,
        public ?string $jurisdiction = null,
    ) {
        if (1 !== preg_match('/^PII-\d{3}$/', $rule)) {
            throw new \InvalidArgumentException(\sprintf('Rule identifiers look like "PII-001", got "%s".', $rule));
        }
        $this->fields = FlowPoint::unique($fields);
    }
}
