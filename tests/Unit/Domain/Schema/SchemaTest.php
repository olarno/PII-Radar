<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Schema;

use App\Domain\Schema\Column;
use App\Domain\Schema\FieldReference;
use App\Domain\Schema\Schema;
use App\Domain\Schema\Table;
use App\Domain\SourceLocation;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testListsColumnsOfAllTables(): void
    {
        $schema = new Schema([
            self::table('patients', 'nom', 'nam'),
            self::table('users', 'courriel'),
        ]);

        self::assertSame(
            ['patients.nom', 'patients.nam', 'users.courriel'],
            array_map(static fn (Column $column): string => (string) $column->reference, $schema->columns()),
        );
        self::assertNotNull($schema->table('users'));
        self::assertNull($schema->table('unknown'));
    }

    public function testLastDeclarationOfATableWins(): void
    {
        $schema = new Schema([self::table('patients', 'nom'), self::table('patients', 'nom', 'nam')]);

        self::assertCount(1, $schema->tables());
        self::assertCount(2, $schema->columns());
    }

    public function testATableOnlyAcceptsItsOwnColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Table('patients', [self::column('users', 'courriel')], new SourceLocation('schema.sql', 1));
    }

    private static function table(string $name, string ...$columns): Table
    {
        return new Table($name, array_values(array_map(static fn (string $column): Column => self::column($name, $column), $columns)), new SourceLocation('schema.sql', 1));
    }

    private static function column(string $table, string $name): Column
    {
        return new Column(new FieldReference($table, $name), 'VARCHAR(100)', false, new SourceLocation('schema.sql', 2));
    }
}
