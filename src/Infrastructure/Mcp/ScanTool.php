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
        description: 'Runs a full scan of the audited application again after a code change. With the agent it calls the LLM (cost): the other tools read the last scan at no cost.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true),
    )]
    public function __invoke(
        #[Schema(description: 'Have the agent arbitrate ambiguous columns (LLM call). false for a deterministic scan at no cost.')]
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
            "Scan done: %d finding(s) (%s), %d personal field(s) out of %d columns.%s\n\nMost severe:\n%s",
            \count($report->findings),
            implode(', ', array_map(static fn (string $severity, int $count): string => \sprintf('%d %s', $count, mb_strtolower($severity)), array_keys($bySeverity), $bySeverity)),
            \count($personal),
            \count($report->schema->columns()),
            null === $report->agentUsage ? '' : \sprintf(' Agent: %d call(s), %s input tokens.', $report->agentUsage->calls, $report->agentUsage->inputTokens ?? '?'),
            implode("\n", array_map(static fn (Finding $finding): string => \sprintf('- %s · %s — %s', $finding->rule, $finding->location, $finding->summary), \array_slice($report->findings, 0, 5))),
        );
    }
}
