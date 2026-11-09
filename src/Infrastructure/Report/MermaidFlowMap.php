<?php

declare(strict_types=1);

namespace App\Infrastructure\Report;

use App\Application\Query\FieldSheet;
use App\Application\ScanReport;
use App\Domain\Flow\FlowOperation;
use App\Domain\Flow\FlowPoint;
use App\Domain\Schema\FieldReference;

/**
 * Draws where personal data goes as Mermaid flowcharts: forms → tables → logs, third parties and exposed files.
 *
 * Red marks what calls for action: a recipient outside Quebec (PII-008), a log of personal data (PII-002), a public export (PII-007).
 */
final class MermaidFlowMap
{
    private const string CLASSES = <<<'MERMAID'
            classDef source fill:#e8f1fb,stroke:#2b6cb0,color:#1a365d
            classDef store fill:#edf7ed,stroke:#2f855a,color:#1c4532
            classDef sink fill:#f4f4f5,stroke:#71717a,color:#27272a
            classDef danger fill:#fdecec,stroke:#c53030,color:#742a2a,stroke-width:2px
        MERMAID;

    /** @var array<string, string> node key => id */
    private array $ids = [];
    /** @var array<string, string> id => definition */
    private array $nodes = [];
    /** @var array<string, true> */
    private array $edges = [];

    public function project(ScanReport $report): string
    {
        $this->reset();
        $personal = self::personal($report);
        [$foreign, $exports] = self::danger($report);

        foreach ($report->flows as $flow) {
            $tables = array_values(array_unique(array_map(static fn (FieldReference $field): string => $field->table, array_filter($flow->fields, static fn (FieldReference $field): bool => isset($personal[(string) $field])))));
            foreach ($tables as $table) {
                // Nodes are created with their edge only: a table that is merely read does not clutter the map.
                match ($flow->operation) {
                    FlowOperation::Collect => $this->edge($this->node('file:'.$flow->location->file, '📝 '.$flow->location->file, 'source'), $this->tableNode($table), 'collect'),
                    FlowOperation::Log => $this->edge($this->tableNode($table), $this->node('log:'.$flow->target, '📄 '.$flow->target, 'danger'), (string) $flow->location),
                    FlowOperation::Send => $this->edge($this->tableNode($table), $this->recipient($flow, $foreign), (string) $flow->location),
                    default => null,
                };
            }
        }
        foreach ($exports as $file => $tables) {
            foreach ($tables as $table) {
                $this->edge($this->tableNode($table), $this->node('export:'.$file, '⚠️ '.$file.' (public)', 'danger'), 'export');
            }
        }

        return $this->render('No personal data flow.');
    }

    public function field(FieldSheet $sheet, ScanReport $report): string
    {
        $this->reset();
        [$foreign] = self::danger($report);
        $field = $this->node('field', '🗄️ '.$sheet->column->reference, 'store');

        foreach ($sheet->flows as $flow) {
            match ($flow->operation) {
                FlowOperation::Collect => $this->edge($this->node('file:'.$flow->location->file, '📝 '.$flow->location->file, 'source'), $field, 'collect'),
                FlowOperation::Log => $this->edge($field, $this->node('log:'.$flow->target, '📄 '.$flow->target, $sheet->isPersonal() ? 'danger' : 'sink'), (string) $flow->location),
                FlowOperation::Send => $this->edge($field, $this->recipient($flow, $foreign), (string) $flow->location),
                FlowOperation::Read => $this->edge($field, $this->node('read:'.$flow->location->file, '👁️ '.$flow->location->file, 'sink'), 'read'),
                FlowOperation::Hash => $this->edge($field, $this->node('hash:'.$flow->target, '🔒 '.$flow->target, 'md5' === $flow->target || 'sha1' === $flow->target ? 'danger' : 'sink'), (string) $flow->location),
                default => null,
            };
        }
        foreach ($sheet->findings as $finding) {
            if ('PII-007' === $finding->rule) {
                $this->edge($field, $this->node('export:'.$finding->location->file, '⚠️ '.$finding->location->file.' (public)', 'danger'), 'export');
            }
        }

        return $this->render('No flow for this field.');
    }

    /**
     * @param array<string, true> $foreign hosts outside Quebec
     */
    private function recipient(FlowPoint $flow, array $foreign): string
    {
        $target = (string) $flow->target;
        if (str_starts_with($target, 'mail:')) {
            return $this->node('mail:'.$target, '✉️ e-mail ('.substr($target, 5).')', 'sink');
        }

        return $this->node('host:'.$target, '🌐 '.$target.(isset($foreign[$target]) ? ' (outside Quebec)' : ''), isset($foreign[$target]) ? 'danger' : 'sink');
    }

    private function tableNode(string $table): string
    {
        return $this->node('table:'.$table, '🗄️ '.$table, 'store');
    }

    private function node(string $key, string $label, string $class): string
    {
        if (!isset($this->ids[$key])) {
            $id = 'n'.\count($this->ids);
            $this->ids[$key] = $id;
            $this->nodes[$id] = \sprintf('    %s["%s"]:::%s', $id, self::escape($label), $class);
        }

        return $this->ids[$key];
    }

    private function edge(string $from, string $to, string $label): void
    {
        $this->edges[\sprintf('    %s -->|"%s"| %s', $from, self::escape($label), $to)] = true;
    }

    private function render(string $empty): string
    {
        if ([] === $this->edges) {
            return "flowchart LR\n    empty[\"".self::escape($empty).'"]';
        }

        return "flowchart LR\n".implode("\n", $this->nodes)."\n".implode("\n", array_keys($this->edges))."\n".self::CLASSES;
    }

    private function reset(): void
    {
        $this->ids = [];
        $this->nodes = [];
        $this->edges = [];
    }

    private static function escape(string $label): string
    {
        return str_replace(['"', '|'], ['#quot;', '#124;'], $label);
    }

    /**
     * @return array<string, true>
     */
    private static function personal(ScanReport $report): array
    {
        $personal = [];
        foreach ($report->classifiedFields as $field) {
            if ($field->personal) {
                $personal[(string) $field->column->reference] = true;
            }
        }

        return $personal;
    }

    /**
     * @return array{array<string, true>, array<string, list<string>>} hosts outside Quebec, and exposed files with the tables they leak
     */
    private static function danger(ScanReport $report): array
    {
        $foreign = [];
        $exports = [];
        foreach ($report->findings as $finding) {
            if ('PII-008' === $finding->rule && null !== $finding->recipient) {
                $foreign[$finding->recipient] = true;
            }
            if ('PII-007' === $finding->rule) {
                $exports[$finding->location->file] = array_values(array_unique(array_map(static fn (FieldReference $field): string => $field->table, $finding->fields)));
            }
        }

        return [$foreign, $exports];
    }
}
