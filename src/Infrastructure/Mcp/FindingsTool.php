<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Application\Query\AuditQueries;
use App\Domain\Finding\Severity;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

final readonly class FindingsTool
{
    public function __construct(
        private AuditedProject $project,
        private AuditQueries $queries,
        private MarkdownFormatter $format,
    ) {
    }

    #[McpTool(
        name: 'pii_findings',
        description: 'The personal data risks of the last scan (rules PII-001 to PII-008), with evidence and fix, filtered by minimum severity, file or rule.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function __invoke(
        #[Schema(description: 'Minimum severity', enum: ['low', 'medium', 'high', 'critical'])]
        ?string $severity = null,
        #[Schema(description: 'Part of the file path, e.g. login.php')]
        ?string $file = null,
        #[Schema(description: 'Rule identifier, e.g. PII-002')]
        ?string $rule = null,
    ): string {
        try {
            $stored = $this->project->report();
        } catch (NoReport $exception) {
            throw new ToolCallException($exception->getMessage());
        }

        $minimum = null === $severity || '' === $severity ? null : (Severity::tryFrom($severity) ?? throw new ToolCallException(\sprintf('Unknown severity "%s": low, medium, high or critical.', $severity)));
        $findings = $this->queries->findings($stored->report, $minimum, $file, $rule);
        if ([] === $findings) {
            return "No finding matches these filters.\n\n".$this->format->provenance($stored);
        }

        return \sprintf(
            "%d finding(s):\n\n%s\n\n%s",
            \count($findings),
            implode("\n", array_map(fn ($finding): string => '- '.$this->format->finding($finding), $findings)),
            $this->format->provenance($stored),
        );
    }
}
