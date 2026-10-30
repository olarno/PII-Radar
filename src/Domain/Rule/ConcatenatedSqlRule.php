<?php

declare(strict_types=1);

namespace App\Domain\Rule;

use App\Domain\Finding\Finding;
use App\Domain\Finding\Severity;
use App\Domain\Flow\FlowOperation;
use App\Domain\Pii\Sensitivity;

/**
 * PII-004: personal data concatenated into an SQL statement (injection risk on the data itself).
 */
final class ConcatenatedSqlRule implements Rule
{
    public function id(): string
    {
        return 'PII-004';
    }

    public function title(): string
    {
        return 'Requête SQL concaténée avec une donnée personnelle';
    }

    public function evaluate(AuditContext $context): array
    {
        $findings = [];
        foreach ($context->flowsOf(FlowOperation::Read, FlowOperation::Store, FlowOperation::Delete) as $flow) {
            $fields = $context->onlyPersonalData($flow->concatenatedFields);
            if ([] === $fields) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                Sensitivity::Low === $context->highestSensitivity($fields) ? Severity::Medium : Severity::High,
                $flow->location,
                $fields,
                \sprintf('Requête sur %s construite en concaténant %s.', $flow->target ?? 'la base', implode(', ', array_map('strval', $fields))),
                'Utiliser une requête préparée avec des paramètres liés (PDO, mysqli_prepare, QueryBuilder Doctrine).',
            );
        }

        return $findings;
    }
}
