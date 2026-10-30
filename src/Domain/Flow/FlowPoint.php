<?php

declare(strict_types=1);

namespace App\Domain\Flow;

use App\Domain\Schema\FieldReference;
use App\Domain\SourceLocation;

/**
 * A place in the audited code where stored fields are collected, read, written, deleted, logged, sent or hashed.
 */
final readonly class FlowPoint
{
    /** @var list<FieldReference> */
    public array $fields;

    /** @var list<FieldReference> */
    public array $concatenatedFields;

    /**
     * @param list<FieldReference> $fields             the fields involved, without duplicates
     * @param ?string              $target             what receives the data: a host, a log file, "mail:patients.courriel", a hash algorithm, a table
     * @param list<FieldReference> $concatenatedFields fields whose value is concatenated into an SQL statement instead of bound as a parameter
     */
    public function __construct(
        public FlowOperation $operation,
        array $fields,
        public SourceLocation $location,
        public ?string $target = null,
        array $concatenatedFields = [],
    ) {
        $this->fields = self::unique($fields);
        $this->concatenatedFields = self::unique($concatenatedFields);
    }

    public function involves(FieldReference $field): bool
    {
        foreach ($this->fields as $candidate) {
            if ($candidate->equals($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<FieldReference> $fields
     *
     * @return list<FieldReference>
     */
    public static function unique(array $fields): array
    {
        $unique = [];
        foreach ($fields as $field) {
            $unique[(string) $field] = $field;
        }

        return array_values($unique);
    }
}
