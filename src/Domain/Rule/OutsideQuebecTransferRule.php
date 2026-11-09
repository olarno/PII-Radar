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
        return 'Personal information communicated outside Quebec';
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
                    ? \sprintf('%s sent to %s, whose jurisdiction is to be confirmed.', implode(', ', array_map('strval', $fields)), $host)
                    : \sprintf('%s sent to %s (%s): communication outside Quebec.', implode(', ', array_map('strval', $fields)), $host, $jurisdiction->label()),
                'Carry out a privacy impact assessment (PIA, "EFVP" in Quebec) before the communication, and govern it with a written agreement.',
                $host,
                $jurisdiction?->value,
            );
        }

        return $findings;
    }
}
