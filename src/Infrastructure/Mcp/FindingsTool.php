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
        description: 'Les risques de données personnelles du dernier scan (règles PII-001 à PII-008), avec preuve et correctif, filtrables par sévérité minimale, fichier ou règle.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function __invoke(
        #[Schema(description: 'Sévérité minimale', enum: ['low', 'medium', 'high', 'critical'])]
        ?string $severity = null,
        #[Schema(description: 'Partie du chemin du fichier, par exemple login.php')]
        ?string $file = null,
        #[Schema(description: 'Identifiant de règle, par exemple PII-002')]
        ?string $rule = null,
    ): string {
        try {
            $stored = $this->project->report();
        } catch (NoReport $exception) {
            throw new ToolCallException($exception->getMessage());
        }

        $minimum = null === $severity || '' === $severity ? null : (Severity::tryFrom($severity) ?? throw new ToolCallException(\sprintf('Sévérité « %s » inconnue : low, medium, high ou critical.', $severity)));
        $findings = $this->queries->findings($stored->report, $minimum, $file, $rule);
        if ([] === $findings) {
            return "Aucun finding ne correspond à ces filtres.\n\n".$this->format->provenance($stored);
        }

        return \sprintf(
            "%d finding(s) :\n\n%s\n\n%s",
            \count($findings),
            implode("\n", array_map(fn ($finding): string => '- '.$this->format->finding($finding), $findings)),
            $this->format->provenance($stored),
        );
    }
}
