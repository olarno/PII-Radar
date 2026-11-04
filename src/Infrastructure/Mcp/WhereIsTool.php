<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Application\Query\AuditQueries;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

final readonly class WhereIsTool
{
    private const int MAX_RESULTS = 5;

    public function __construct(
        private AuditedProject $project,
        private AuditQueries $queries,
        private MarkdownFormatter $format,
    ) {
    }

    #[McpTool(
        name: 'pii_where_is',
        description: 'Où vit une donnée personnelle dans l\'application auditée (« courriel du patient », « téléphone », patients.nam) : colonne, classification, flux dans le code, findings. Lit le dernier scan, sans coût.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function __invoke(
        #[Schema(description: 'La donnée cherchée, en langage naturel (français ou anglais) ou sous la forme table.colonne')]
        string $data,
    ): string {
        try {
            $stored = $this->project->report();
        } catch (NoReport $exception) {
            throw new ToolCallException($exception->getMessage());
        }

        $sheets = $this->queries->whereIs($stored->report, $data);
        if ([] === $sheets) {
            return \sprintf("Aucune donnée personnelle ne correspond à « %s ».\n\n%s", $data, $this->format->provenance($stored));
        }

        $shown = \array_slice($sheets, 0, self::MAX_RESULTS);
        $blocks = array_map(fn ($sheet): string => $this->format->brief($sheet), $shown);

        return \sprintf(
            "%d emplacement(s) pour « %s »%s.\n\n%s\n\n%s",
            \count($sheets),
            $data,
            \count($sheets) > self::MAX_RESULTS ? \sprintf(', les %d plus pertinents', self::MAX_RESULTS) : '',
            implode("\n\n", $blocks),
            $this->format->provenance($stored),
        );
    }
}
