<?php

declare(strict_types=1);

namespace App\Domain\Rule;

use App\Domain\Finding\Finding;
use App\Domain\Finding\Severity;
use App\Domain\Flow\FlowOperation;

/**
 * PII-006: a table holding personal data that the code never deletes from (decision 4).
 *
 * The rule only sees the code it scans: a purge run from outside it (database event, manual script) is not visible.
 */
final class NoRetentionRule implements Rule
{
    public function id(): string
    {
        return 'PII-006';
    }

    public function title(): string
    {
        return 'No sign of retention or deletion';
    }

    public function evaluate(AuditContext $context): array
    {
        $purged = [];
        foreach ($context->flowsOf(FlowOperation::Delete) as $flow) {
            if (null !== $flow->target) {
                $purged[$flow->target] = true;
            }
        }

        $findings = [];
        foreach ($context->schema->tables() as $table) {
            $fields = $context->onlyPersonalData(array_map(static fn ($column) => $column->reference, $table->columns));
            if ([] === $fields || isset($purged[$table->name])) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                Severity::Medium,
                $table->location,
                $fields,
                \sprintf('No deletion or purge of the %s table in the code, which holds %d personal field(s).', $table->name, \count($fields)),
                'Set a retention period with the person in charge, then add a scheduled purge command (DELETE or anonymization).',
            );
        }

        return $findings;
    }
}
