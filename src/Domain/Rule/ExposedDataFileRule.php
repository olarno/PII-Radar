<?php

declare(strict_types=1);

namespace App\Domain\Rule;

use App\Domain\Finding\Finding;
use App\Domain\Finding\Severity;
use App\Domain\Schema\FieldReference;

/**
 * PII-007: an export or dump left in a publicly served directory.
 */
final class ExposedDataFileRule implements Rule
{
    public function id(): string
    {
        return 'PII-007';
    }

    public function title(): string
    {
        return 'Export or dump in the webroot';
    }

    public function evaluate(AuditContext $context): array
    {
        $findings = [];
        foreach ($context->dataFiles as $file) {
            // Header columns are matched to personal fields by name.
            $fields = [];
            foreach ($context->personalData() as $candidate) {
                if (\in_array($candidate->column->name(), $file->columns, true)) {
                    $fields[] = $candidate->column->reference;
                }
            }

            $findings[] = new Finding(
                $this->id(),
                Severity::Critical,
                $file->location,
                self::preferTable($fields),
                \sprintf('%s can be downloaded publicly%s.', $file->location->file, [] === $fields ? '' : \sprintf(' and holds %d personal field(s)', \count(self::preferTable($fields)))),
                'Delete the file or move it out of the webroot, and block these extensions in the web server configuration.',
            );
        }

        return $findings;
    }

    /**
     * When a column name exists in several tables, keep the table that matches most header columns.
     *
     * @param list<FieldReference> $fields
     *
     * @return list<FieldReference>
     */
    private static function preferTable(array $fields): array
    {
        $byTable = [];
        foreach ($fields as $field) {
            $byTable[$field->table][] = $field;
        }
        uasort($byTable, static fn (array $a, array $b): int => \count($b) <=> \count($a));

        return array_shift($byTable) ?? [];
    }
}
