<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Tool;

use App\Domain\Flow\FlowPoint;
use App\Domain\Schema\FieldReference;
use App\Infrastructure\Ai\AuditSession;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

#[AsTool('find_column_usages', 'Lists where the audited code collects, reads, stores, logs, sends or hashes a column (file, line, operation, target).')]
final readonly class FindColumnUsagesTool
{
    public function __construct(
        private AuditSession $session,
    ) {
    }

    public function __invoke(
        #[Schema(description: 'Column as "table.column", e.g. "patients.nom"')]
        string $field,
    ): string {
        try {
            $reference = FieldReference::fromString($field);
        } catch (\InvalidArgumentException) {
            return \sprintf('"%s" is not a "table.column" reference.', $field);
        }

        $usages = array_values(array_filter($this->session->flows(), static fn (FlowPoint $flow): bool => $flow->involves($reference)));
        if ([] === $usages) {
            return \sprintf('No code flow uses %s.', $reference);
        }

        $lines = array_map(static fn (FlowPoint $flow): string => \sprintf(
            '- %s: %s%s%s',
            $flow->location,
            $flow->operation->value,
            null === $flow->target ? '' : ' → '.$flow->target,
            [] === $flow->concatenatedFields ? '' : ' (concatenated SQL)',
        ), $usages);

        return \sprintf("%s is used in %d flow(s):\n%s", $reference, \count($usages), implode("\n", $lines));
    }
}
