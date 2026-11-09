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
        description: 'May a field be written to a log (error_log, Monolog, file)? Answers yes, avoid or no, with the rule, the alternative and the logs that already write it. Call it before adding a log.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function __invoke(
        #[Schema(description: 'The field as table.column (or the bare column name when it is unique), e.g. patients.nam')]
        string $field,
    ): string {
        try {
            $stored = $this->project->report();
        } catch (NoReport $exception) {
            throw new ToolCallException($exception->getMessage());
        }

        $columns = $this->queries->resolve($stored->report, $field);
        if ([] === $columns) {
            throw new ToolCallException(\sprintf('Field "%s" is not in the audited schema. Use pii_where_is to find it.', $field));
        }

        $answers = array_map(fn ($column): string => $this->answer($this->queries->canLog($stored->report, $column)), $columns);

        return implode("\n\n---\n\n", $answers)."\n\n".$this->format->provenance($stored);
    }

    private function answer(LogAdvice $advice): string
    {
        $lines = [
            \sprintf('**%s** %s', match ($advice->verdict) {
                LogVerdict::Allowed => 'Yes.',
                LogVerdict::Avoid => 'Avoid it.',
                LogVerdict::Forbidden => 'No.',
            }, $advice->reason),
            '',
            'Instead: '.$advice->alternative,
        ];
        if ([] !== $advice->existingLogs && LogVerdict::Allowed !== $advice->verdict) {
            $lines[] = '';
            $lines[] = 'Already logged, to fix:';
            foreach ($advice->existingLogs as $flow) {
                $lines[] = '- '.$this->format->flow($flow);
            }
        }

        return implode("\n", $lines);
    }
}
