<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Application\Port\ArbitrationFailed;
use App\Application\ScanProject;
use App\Domain\Finding\Finding;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

final readonly class ScanTool
{
    public function __construct(
        private AuditedProject $project,
        private ScanProject $scanProject,
    ) {
    }

    #[McpTool(
        name: 'pii_scan',
        description: 'Relance un scan complet de l\'application auditée après une modification du code. Avec l\'agent, appelle le LLM (coût) : les autres outils lisent le dernier scan sans coût.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true),
    )]
    public function __invoke(
        #[Schema(description: 'Faire arbitrer les colonnes ambiguës par l\'agent (appel au LLM). false pour un scan déterministe, sans coût.')]
        bool $withAgent = true,
    ): string {
        try {
            $report = ($this->scanProject)($this->project->path(), $withAgent);
        } catch (ArbitrationFailed|\InvalidArgumentException $exception) {
            throw new ToolCallException($exception->getMessage());
        }

        $bySeverity = [];
        foreach ($report->findings as $finding) {
            $bySeverity[$finding->severity->label()] = ($bySeverity[$finding->severity->label()] ?? 0) + 1;
        }
        $personal = array_filter($report->classifiedFields, static fn ($field): bool => $field->personal);

        return \sprintf(
            "Scan terminé : %d finding(s) (%s), %d champ(s) personnel(s) sur %d colonnes.%s\n\nPlus graves :\n%s",
            \count($report->findings),
            implode(', ', array_map(static fn (string $severity, int $count): string => \sprintf('%d %s', $count, mb_strtolower($severity)), array_keys($bySeverity), $bySeverity)),
            \count($personal),
            \count($report->schema->columns()),
            null === $report->agentUsage ? '' : \sprintf(' Agent : %d appel(s), %s tokens en entrée.', $report->agentUsage->calls, $report->agentUsage->inputTokens ?? '?'),
            implode("\n", array_map(static fn (Finding $finding): string => \sprintf('- %s · %s — %s', $finding->rule, $finding->location, $finding->summary), \array_slice($report->findings, 0, 5))),
        );
    }
}
