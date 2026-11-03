<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Application\Query\AuditQueries;
use App\Application\Query\LogAdvice;
use App\Application\Query\LogVerdict;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

final readonly class CanILogTool
{
    public function __construct(
        private AuditedProject $project,
        private AuditQueries $queries,
        private MarkdownFormatter $format,
    ) {
    }

    #[McpTool(
        name: 'pii_can_i_log',
        description: 'Un champ peut-il être écrit dans un journal (error_log, Monolog, fichier) ? Répond oui, à éviter ou non, avec la règle, l\'alternative et les journaux qui l\'écrivent déjà. À appeler avant d\'ajouter un log.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function __invoke(
        #[Schema(description: 'Le champ, sous la forme table.colonne (ou le nom de colonne seul s\'il est unique), par exemple patients.nam')]
        string $field,
    ): string {
        try {
            $stored = $this->project->report();
        } catch (NoReport $exception) {
            throw new ToolCallException($exception->getMessage());
        }

        $columns = $this->queries->resolve($stored->report, $field);
        if ([] === $columns) {
            throw new ToolCallException(\sprintf('Champ « %s » inconnu du schéma audité. Utilisez pii_where_is pour le trouver.', $field));
        }

        $answers = array_map(fn ($column): string => $this->answer($this->queries->canLog($stored->report, $column)), $columns);

        return implode("\n\n---\n\n", $answers)."\n\n".$this->format->provenance($stored);
    }

    private function answer(LogAdvice $advice): string
    {
        $lines = [
            \sprintf('**%s** %s', match ($advice->verdict) {
                LogVerdict::Allowed => 'Oui.',
                LogVerdict::Avoid => 'À éviter.',
                LogVerdict::Forbidden => 'Non.',
            }, $advice->reason),
            '',
            'À la place : '.$advice->alternative,
        ];
        if ([] !== $advice->existingLogs && LogVerdict::Allowed !== $advice->verdict) {
            $lines[] = '';
            $lines[] = 'Déjà journalisé, à corriger :';
            foreach ($advice->existingLogs as $flow) {
                $lines[] = '- '.$this->format->flow($flow);
            }
        }

        return implode("\n", $lines);
    }
}
