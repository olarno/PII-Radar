<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Rule;

use App\Domain\DataFile;
use App\Domain\Finding\Finding;
use App\Domain\Flow\FlowOperation;
use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\ColumnNameClassifier;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Rule\AuditContext;
use App\Domain\Schema\Column;
use App\Domain\Schema\FieldReference;
use App\Domain\Schema\Schema;
use App\Domain\Schema\Table;
use App\Domain\SourceLocation;
use PHPUnit\Framework\TestCase;

/**
 * Builds small audit contexts: a "clients" table classified by the real name classifier.
 */
abstract class RuleTestCase extends TestCase
{
    /**
     * @param list<FlowPoint> $flows
     * @param list<DataFile>  $dataFiles
     */
    protected static function context(array $flows = [], array $dataFiles = [], string ...$columns): AuditContext
    {
        $columns = [] === $columns ? ['id', 'nom', 'courriel', 'nas', 'mot_de_passe', 'derniere_ip', 'email_template'] : $columns;
        $definitions = [];
        foreach (array_values($columns) as $index => $name) {
            $definitions[] = new Column(new FieldReference('clients', $name), 'VARCHAR(100)', true, new SourceLocation('schema.sql', $index + 2));
        }
        $table = new Table('clients', $definitions, new SourceLocation('schema.sql', 1));

        $classifier = new ColumnNameClassifier();
        $candidates = array_values(array_filter(array_map($classifier->classify(...), $table->columns), static fn (?PiiCandidate $candidate): bool => null !== $candidate));

        return new AuditContext(new Schema([$table]), $candidates, $flows, $dataFiles);
    }

    /**
     * @param list<string> $fields       column names of "clients"
     * @param list<string> $concatenated
     */
    protected static function flow(FlowOperation $operation, array $fields, ?string $target = null, array $concatenated = [], string $file = 'app.php', int $line = 10): FlowPoint
    {
        return new FlowPoint(
            $operation,
            array_map(static fn (string $field): FieldReference => new FieldReference('clients', $field), $fields),
            new SourceLocation($file, $line),
            $target,
            array_map(static fn (string $field): FieldReference => new FieldReference('clients', $field), $concatenated),
        );
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    protected static function fieldsOf(array $findings, int $index = 0): array
    {
        self::assertArrayHasKey($index, $findings);

        return array_map('strval', $findings[$index]->fields);
    }
}
