<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Application\Query\AuditQueries;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\ResourceReadException;

final readonly class FieldResource
{
    public function __construct(
        private AuditedProject $project,
        private AuditQueries $queries,
        private MarkdownFormatter $format,
    ) {
    }

    #[McpResourceTemplate(
        uriTemplate: 'pii://field/{table}/{column}',
        name: 'field',
        title: 'Field sheet',
        description: 'Everything the last scan knows about a column: classification, justification, code flows, findings.',
        mimeType: 'text/markdown',
    )]
    public function __invoke(string $table, string $column): string
    {
        try {
            $stored = $this->project->report();
        } catch (NoReport $exception) {
            throw new ResourceReadException($exception->getMessage());
        }

        $columns = $this->queries->resolve($stored->report, $table.'.'.$column);
        if (1 !== \count($columns) || (string) $columns[0]->reference !== strtolower($table.'.'.$column)) {
            throw new ResourceReadException(\sprintf('Column %s.%s does not exist in the audited schema.', $table, $column));
        }

        return $this->format->sheet($this->queries->sheet($stored->report, $columns[0]), '#')."\n\n".$this->format->provenance($stored)."\n";
    }
}
