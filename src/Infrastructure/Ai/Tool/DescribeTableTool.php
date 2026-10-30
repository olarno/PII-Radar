<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Tool;

use App\Infrastructure\Ai\AuditSession;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

#[AsTool('describe_table', 'Describes a table of the audited schema: its columns, their SQL type and nullability. Never returns stored values.')]
final readonly class DescribeTableTool
{
    public function __construct(
        private AuditSession $session,
    ) {
    }

    public function __invoke(
        #[Schema(description: 'Table name, e.g. "patients"')]
        string $table,
    ): string {
        $definition = $this->session->schema()->table($table);
        if (null === $definition) {
            return \sprintf('Unknown table "%s". Known tables: %s.', $table, implode(', ', array_map(static fn ($known): string => $known->name, $this->session->schema()->tables())));
        }

        $columns = array_map(
            static fn ($column): string => \sprintf('- %s %s%s', $column->name(), $column->type, $column->nullable ? ' NULL' : ' NOT NULL'),
            $definition->columns,
        );

        return \sprintf("Table %s (%s):\n%s", $table, $definition->location, implode("\n", $columns));
    }
}
