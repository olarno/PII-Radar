<?php

declare(strict_types=1);

namespace App\Domain\Rule;

use App\Domain\Finding\Finding;
use App\Domain\Finding\Severity;
use App\Domain\Pii\PiiCategory;
use App\Domain\Pii\Sensitivity;

/**
 * PII-001: highly sensitive data stored as readable text.
 *
 * Credentials are left to PII-005, which knows whether they are hashed.
 */
final class SensitiveDataInClearRule implements Rule
{
    private const string ENCRYPTED = '/chiffr|crypt|encrypted|cipher|_enc$|hash/i';

    public function id(): string
    {
        return 'PII-001';
    }

    public function title(): string
    {
        return 'Sensitive data stored in clear';
    }

    public function evaluate(AuditContext $context): array
    {
        $findings = [];
        foreach ($context->personalData() as $candidate) {
            $column = $candidate->column;
            if (Sensitivity::High !== $candidate->sensitivity || PiiCategory::Technical === $candidate->category
                || 1 === preg_match(self::ENCRYPTED, $column->name()) || 1 === preg_match('/BLOB|BINARY/i', $column->type)) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                Severity::High,
                $column->location,
                [$column->reference],
                \sprintf('%s (%s) is stored in clear in a %s column.', $column->reference, mb_strtolower($candidate->category->label()), $column->type),
                'Encrypt the column at rest, for example with an encrypted Doctrine type (libsodium), and restrict who can read it.',
            );
        }

        return $findings;
    }
}
