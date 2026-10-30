<?php

declare(strict_types=1);

namespace App\Domain\Rule;

use App\Domain\Finding\Finding;
use App\Domain\Finding\Severity;
use App\Domain\Flow\FlowOperation;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Pii\PiiCategory;
use App\Domain\Pii\Sensitivity;
use App\Domain\Schema\FieldReference;

/**
 * PII-005: passwords hashed with a fast, broken algorithm, or stored without any hashing.
 */
final class WeakPasswordStorageRule implements Rule
{
    private const array WEAK = ['md5', 'sha1', 'crc32', 'md4', 'sha224', 'sha256'];

    public function id(): string
    {
        return 'PII-005';
    }

    public function title(): string
    {
        return 'Mot de passe haché faiblement ou stocké en clair';
    }

    public function evaluate(AuditContext $context): array
    {
        $findings = [];
        $hashedFiles = [];
        foreach ($context->flowsOf(FlowOperation::Hash) as $flow) {
            $credentials = self::credentials($context, $flow->fields);
            if ([] === $credentials) {
                continue;
            }
            $hashedFiles[$flow->location->file] = true;
            if (!\in_array(strtolower(trim((string) $flow->target, "'\" ")), self::WEAK, true)) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                Severity::Critical,
                $flow->location,
                $credentials,
                \sprintf('%s haché avec %s, un algorithme rapide et cassé.', implode(', ', array_map('strval', $credentials)), $flow->target),
                'Hacher les mots de passe avec password_hash() ou le PasswordHasher de Symfony (bcrypt, argon2id), puis migrer les anciens hash à la connexion.',
            );
        }

        foreach ($context->flowsOf(FlowOperation::Store) as $flow) {
            $credentials = self::credentials($context, $flow->fields);
            if ([] === $credentials || isset($hashedFiles[$flow->location->file])) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                Severity::Critical,
                $flow->location,
                $credentials,
                \sprintf('%s enregistré sans aucun hachage.', implode(', ', array_map('strval', $credentials))),
                'Hacher les mots de passe avec password_hash() ou le PasswordHasher de Symfony avant de les enregistrer.',
            );
        }

        return $findings;
    }

    /**
     * @param list<FieldReference> $fields
     *
     * @return list<FieldReference>
     */
    private static function credentials(AuditContext $context, array $fields): array
    {
        return array_values(array_filter($fields, static function (FieldReference $field) use ($context): bool {
            $candidate = $context->personalDataFor($field);

            return $candidate instanceof ClassifiedField && PiiCategory::Technical === $candidate->category && Sensitivity::High === $candidate->sensitivity;
        }));
    }
}
