<?php

declare(strict_types=1);

namespace App\Tests\Integration\Scanner;

use App\Domain\Schema\Column;
use App\Infrastructure\Scanner\SqlDumpSchemaReader;
use PHPUnit\Framework\TestCase;

final class SqlDumpSchemaReaderTest extends TestCase
{
    public function testReadsTheDemoSchemaWithLines(): void
    {
        $schema = new SqlDumpSchemaReader()->read(__DIR__.'/../../../demo/legacy-app');

        self::assertSame(
            ['patients', 'users', 'rendezvous', 'paiements', 'parametres_courriel', 'ville_reference'],
            array_map(static fn ($table): string => $table->name, $schema->tables()),
        );

        $nam = self::find($schema->columns(), 'patients.nam');
        self::assertSame('VARCHAR(12)', $nam->type);
        self::assertFalse($nam->nullable);
        self::assertSame('db/01-schema.sql:11', (string) $nam->location);

        self::assertTrue(self::find($schema->columns(), 'patients.courriel')->nullable);
        self::assertSame('db/01-schema.sql:6', (string) $schema->table('patients')?->location);
        self::assertCount(7, $schema->table('rendezvous')->columns ?? [], 'FOREIGN KEY lines are not columns.');
    }

    public function testReadsMysqldumpOutput(): void
    {
        $tables = new SqlDumpSchemaReader()->parse((string) file_get_contents(__DIR__.'/../../Fixtures/schema/mysqldump.sql'), 'dump.sql');

        self::assertCount(1, $tables);
        self::assertSame('clients', $tables[0]->name);
        self::assertSame('dump.sql:14', (string) $tables[0]->location);
        self::assertSame(
            ['id' => 'INT UNSIGNED', 'first_name' => 'VARCHAR(100)', 'email' => 'VARCHAR(255)', 'statut' => "ENUM('ACTIF','INACTIF')", 'solde' => 'DECIMAL(8,2)'],
            array_combine(
                array_map(static fn (Column $column): string => $column->name(), $tables[0]->columns),
                array_map(static fn (Column $column): string => $column->type, $tables[0]->columns),
            ),
        );
        self::assertSame('dump.sql:16', (string) $tables[0]->columns[1]->location);
        self::assertTrue($tables[0]->columns[2]->nullable);
        self::assertFalse($tables[0]->columns[0]->nullable);
    }

    public function testFailsOnAMissingDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SqlDumpSchemaReader()->read('/does/not/exist');
    }

    /**
     * @param list<Column> $columns
     */
    private static function find(array $columns, string $reference): Column
    {
        foreach ($columns as $column) {
            if ((string) $column->reference === $reference) {
                return $column;
            }
        }

        self::fail(\sprintf('Column %s not found.', $reference));
    }
}
