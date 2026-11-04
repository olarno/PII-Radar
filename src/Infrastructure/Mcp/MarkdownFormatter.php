<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Application\Query\FieldSheet;
use App\Application\StoredReport;
use App\Domain\Finding\Finding;
use App\Domain\Flow\FlowPoint;

/**
 * Renders query results as Markdown for MCP clients.
 */
final class MarkdownFormatter
{
    public function sheet(FieldSheet $sheet, string $heading = '##'): string
    {
        $column = $sheet->column;
        $lines = [\sprintf('%s %s', $heading, $column->reference)];
        $classification = $sheet->classification;
        if (null === $classification) {
            $lines[] = '- Donnée personnelle : **non**, rien dans son nom ne le suggère.';
        } else {
            $lines[] = \sprintf(
                '- Donnée personnelle : **%s** · %s, sensibilité %s (confiance %d %%, décidé par %s)',
                $classification->personal ? 'oui' : 'non',
                $classification->category->label(),
                mb_strtolower($classification->sensitivity->label()),
                (int) round($classification->confidence * 100),
                'agent' === $classification->decidedBy->value ? 'l\'agent' : 'les règles',
            );
            $lines[] = '- Justification : '.$classification->justification;
        }
        $lines[] = \sprintf('- Déclarée : %s (%s%s)', $column->location, $column->type, $column->nullable ? '' : ', NOT NULL');

        if ([] !== $sheet->flows) {
            $lines[] = '';
            $lines[] = '**Flux dans le code**';
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
            ? '- Pas une donnée personnelle.'
            : \sprintf('- %s, sensibilité %s · %s', $classification->category->label(), mb_strtolower($classification->sensitivity->label()), $classification->justification);
        $lines[] = \sprintf('- Déclarée : %s', $column->location);

        $operations = [];
        foreach ($sheet->flows as $flow) {
            $operations[$flow->operation->label()][] = (string) $flow->location.(null === $flow->target || 'Lecture' === $flow->operation->label() ? '' : ' → '.$flow->target);
        }
        foreach ($operations as $operation => $places) {
            $lines[] = \sprintf('- %s : %s%s', $operation, implode(', ', \array_slice($places, 0, 3)), \count($places) > 3 ? \sprintf(' (+%d)', \count($places) - 3) : '');
        }
        if ([] !== $sheet->findings) {
            $lines[] = '- Findings : '.implode(', ', array_map(static fn (Finding $finding): string => \sprintf('%s (%s)', $finding->rule, mb_strtolower($finding->severity->label())), $sheet->findings));
        }
        $lines[] = \sprintf('- Fiche complète : pii://field/%s/%s', $column->reference->table, $column->reference->column);

        return implode("\n", $lines);
    }

    public function flow(FlowPoint $flow): string
    {
        return \sprintf(
            '%s · %s%s%s',
            $flow->operation->label(),
            $flow->location,
            null === $flow->target ? '' : ' → '.$flow->target,
            [] === $flow->concatenatedFields ? '' : ' (SQL concaténé)',
        );
    }

    public function finding(Finding $finding): string
    {
        return \sprintf('%s (%s) · %s — %s Correctif : %s', $finding->rule, mb_strtolower($finding->severity->label()), $finding->location, $finding->summary, $finding->remediation);
    }

    public function provenance(StoredReport $stored): string
    {
        return \sprintf(
            '_Dernier scan : %s, %s._',
            $stored->scannedAt->format('Y-m-d H:i'),
            $stored->report->arbitrated ? 'colonnes ambiguës arbitrées par l\'agent' : 'sans agent',
        );
    }
}
