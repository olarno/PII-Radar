<?php

declare(strict_types=1);

namespace App\Domain\Rule;

use App\Domain\Finding\Finding;
use App\Domain\Finding\Severity;
use App\Domain\Flow\FlowOperation;

/**
 * PII-008: personal data communicated outside Quebec (Loi 25, art. 17), which calls for a privacy impact assessment (EFVP).
 *
 * The rest of Canada is outside Quebec too. An unknown host is reported as "to confirm", never assumed local.
 */
final readonly class OutsideQuebecTransferRule implements Rule
{
    public function __construct(
        private HostJurisdictions $jurisdictions,
    ) {
    }

    public function id(): string
    {
        return 'PII-008';
    }

    public function title(): string
    {
        return 'Communication de renseignements personnels hors Québec';
    }

    public function evaluate(AuditContext $context): array
    {
        $findings = [];
        foreach ($context->flowsOf(FlowOperation::Send) as $flow) {
            $fields = $context->onlyPersonalData($flow->fields);
            $host = $flow->target;
            if ([] === $fields || null === $host || str_starts_with($host, 'mail:')) {
                continue;
            }

            $jurisdiction = $this->jurisdictions->of($host);
            if (Jurisdiction::Quebec === $jurisdiction) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                null === $jurisdiction ? Severity::Low : Severity::Medium,
                $flow->location,
                $fields,
                null === $jurisdiction
                    ? \sprintf('%s envoyé à %s, dont la juridiction est à confirmer.', implode(', ', array_map('strval', $fields)), $host)
                    : \sprintf('%s envoyé à %s (%s) : communication hors Québec.', implode(', ', array_map('strval', $fields)), $host, $jurisdiction->label()),
                'Réaliser une évaluation des facteurs relatifs à la vie privée (EFVP) avant la communication, et l\'encadrer par une entente écrite.',
                $host,
                $jurisdiction?->value,
            );
        }

        return $findings;
    }
}
