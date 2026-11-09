<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Application\Query\FieldSheet;
use App\Application\StoredReport;
use App\Domain\Finding\Finding;
use App\Domain\Flow\FlowPoint;
use App\Domain\Rule\Rule;

/**
 * Renders query results as Markdown for MCP clients.
 */
final class MarkdownFormatter
{
    /** @var array<string, string> rule id => title */
    private array $titles = [];

    /**
     * @param iterable<Rule> $rules
     */
    public function __construct(iterable $rules = [])
    {
        foreach ($rules as $rule) {
            $this->titles[$rule->id()] = $rule->title();
        }
    }

    public function sheet(FieldSheet $sheet, string $heading = '##'): string
    {
        $column = $sheet->column;
        $lines = [\sprintf('%s %s', $heading, $column->reference)];
        $classification = $sheet->classification;
        if (null === $classification) {
            $lines[] = '- Personal data: **no**, nothing in its name suggests it.';
        } else {
            $lines[] = \sprintf(
                '- Personal data: **%s** · %s, %s sensitivity (confidence %d%%, decided by %s)',
                $classification->personal ? 'yes' : 'no',
                $classification->category->label(),
                mb_strtolower($classification->sensitivity->label()),
                (int) round($classification->confidence * 100),
                'agent' === $classification->decidedBy->value ? 'the agent' : 'the rules',
            );
            $lines[] = '- Justification: '.$classification->justification;
        }
        $lines[] = \sprintf('- Declared: %s (%s%s)', $column->location, $column->type, $column->nullable ? '' : ', NOT NULL');

        if ([] !== $sheet->flows) {
            $lines[] = '';
            $lines[] = '**Code flows**';
            foreach ($sheet->flows as $flow) {
                $lines[] = '- '.$this->flow($flow);
            }
        }
        if ([] !== $sheet->findings) {
            $lines[] = '';
            $lines[] = '**Findings**';
            foreach ($sheet->findings as $finding) {
                $lines[] = '- '.$this->finding($finding);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * A short sheet for answers that list several fields; the full sheet is the pii://field resource.
     */
    public function brief(FieldSheet $sheet): string
    {
        $column = $sheet->column;
        $classification = $sheet->classification;
        $lines = [\sprintf('### %s', $column->reference)];
        $lines[] = null === $classification
            ? '- Not personal data.'
            : \sprintf('- %s, %s sensitivity · %s', $classification->category->label(), mb_strtolower($classification->sensitivity->label()), $classification->justification);
        $lines[] = \sprintf('- Declared: %s', $column->location);

        $operations = [];
        foreach ($sheet->flows as $flow) {
            $operations[$flow->operation->label()][] = (string) $flow->location.(null === $flow->target || 'Read' === $flow->operation->label() ? '' : ' → '.$flow->target);
        }
        foreach ($operations as $operation => $places) {
            $lines[] = \sprintf('- %s: %s%s', $operation, implode(', ', \array_slice($places, 0, 3)), \count($places) > 3 ? \sprintf(' (+%d)', \count($places) - 3) : '');
        }
        if ([] !== $sheet->findings) {
            // Each rule is named: a bare "PII-007" invites the assistant to guess what it means.
            $lines[] = '- Findings:';
            foreach ($sheet->findings as $finding) {
                $lines[] = \sprintf('  - %s %s (%s) · %s', $finding->rule, $this->titles[$finding->rule] ?? '', mb_strtolower($finding->severity->label()), $finding->location);
            }
        }
        $lines[] = \sprintf('- Full sheet: pii://field/%s/%s', $column->reference->table, $column->reference->column);

        return implode("\n", $lines);
    }

    public function flow(FlowPoint $flow): string
    {
        return \sprintf(
            '%s · %s%s%s',
            $flow->operation->label(),
            $flow->location,
            null === $flow->target ? '' : ' → '.$flow->target,
            [] === $flow->concatenatedFields ? '' : ' (concatenated SQL)',
        );
    }

    public function finding(Finding $finding): string
    {
        return \sprintf('%s (%s) · %s — %s Fix: %s', $finding->rule, mb_strtolower($finding->severity->label()), $finding->location, $finding->summary, $finding->remediation);
    }

    public function provenance(StoredReport $stored): string
    {
        return \sprintf(
            '_Last scan: %s, %s._',
            $stored->scannedAt->format('Y-m-d H:i'),
            match (true) {
                $stored->report->replayed => 'ambiguous columns arbitrated by the agent (answers replayed from a recorded call)',
                $stored->report->arbitrated => 'ambiguous columns arbitrated by the agent',
                default => 'without the agent',
            },
        );
    }
}
