<?php

declare(strict_types=1);

namespace App\Domain\Rule;

use App\Domain\Finding\Finding;
use App\Domain\Finding\Severity;
use App\Domain\Flow\FlowOperation;

/**
 * PII-003: personal data sent to a third party that the register does not declare.
 *
 * A message sent to the person's own e-mail address is not a disclosure to a third party.
 */
final readonly class UndeclaredThirdPartyRule implements Rule
{
    /**
     * @param list<string> $declaredRecipients hosts already listed in the register
     */
    public function __construct(
        private array $declaredRecipients = [],
    ) {
    }

    public function id(): string
    {
        return 'PII-003';
    }

    public function title(): string
    {
        return 'Donnée personnelle envoyée à un tiers non déclaré';
    }

    public function evaluate(AuditContext $context): array
    {
        $findings = [];
        foreach ($context->flowsOf(FlowOperation::Send) as $flow) {
            $fields = $context->onlyPersonalData($flow->fields);
            $host = $flow->target;
            if ([] === $fields || null === $host || str_starts_with($host, 'mail:') || \in_array($host, $this->declaredRecipients, true)) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                Severity::Medium,
                $flow->location,
                $fields,
                \sprintf('%s envoyé à %s, absent du registre.', implode(', ', array_map('strval', $fields)), $host),
                'Inscrire ce destinataire au registre avec la finalité de la communication, et vérifier qu\'un contrat encadre la protection des renseignements.',
                $host,
            );
        }

        return $findings;
    }
}
