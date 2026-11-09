<?php

declare(strict_types=1);

namespace App\Domain\Rule;

use App\Domain\Finding\Finding;
use App\Domain\Finding\Severity;
use App\Domain\Flow\FlowOperation;
use App\Domain\Pii\Sensitivity;
use App\Domain\Schema\FieldReference;

/**
 * PII-002: personal data written to a log.
 */
final class PersonalDataInLogsRule implements Rule
{
    public function id(): string
    {
        return 'PII-002';
    }

    public function title(): string
    {
        return 'Personal data written to a log';
    }

    public function evaluate(AuditContext $context): array
    {
        $findings = [];
        foreach ($context->flowsOf(FlowOperation::Log) as $flow) {
            $fields = $context->onlyPersonalData($flow->fields);
            if ([] === $fields) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                Sensitivity::High === $context->highestSensitivity($fields) ? Severity::High : Severity::Medium,
                $flow->location,
                $fields,
                \sprintf('%s written to the %s log.', self::list($fields), $flow->target ?? 'application'),
                'Mask personal data before logging, for example with a masking Monolog processor, and log technical identifiers only.',
            );
        }

        return $findings;
    }

    /**
     * @param list<FieldReference> $fields
     */
    private static function list(array $fields): string
    {
        return implode(', ', array_map('strval', $fields));
    }
}
