<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Scanner\Sql;

use App\Domain\Flow\FlowOperation;
use App\Domain\Schema\Column;
use App\Domain\Schema\FieldReference;
use App\Domain\Schema\Schema;
use App\Domain\Schema\Table;
use App\Domain\SourceLocation;
use App\Infrastructure\Scanner\Sql\SqlFragment;
use App\Infrastructure\Scanner\Sql\SqlPlaceholder;
use App\Infrastructure\Scanner\Sql\SqlStatement;
use App\Infrastructure\Scanner\Sql\SqlStatementParser;
use PHPUnit\Framework\TestCase;

final class SqlStatementParserTest extends TestCase
{
    public function testReadsSelectedColumnsWithAliasesAndJoins(): void
    {
        $statement = $this->parse('SELECT c.email, o.total AS montant FROM customers c JOIN orders o ON o.customer_id = c.id');

        self::assertSame(FlowOperation::Read, $statement->operation);
        self::assertSame('customers', $statement->table);
        self::assertSame(['customers.email', 'orders.total'], self::names($statement->fields));
        self::assertSame(['email', 'montant'], array_keys($statement->row));
    }

    public function testExpandsStarToEveryColumn(): void
    {
        $statement = $this->parse('SELECT * FROM customers WHERE id = 1');

        self::assertSame(['customers.id', 'customers.email', 'customers.first_name'], self::names($statement->fields));
    }

    public function testMapsConcatenatedInsertValuesToTheirColumns(): void
    {
        $statement = $this->parse("INSERT INTO customers (email, first_name) VALUES ('", self::unsafe(), "', 'Bob')");

        self::assertSame(FlowOperation::Store, $statement->operation);
        self::assertSame(['customers.email', 'customers.first_name'], self::names($statement->fields));
        self::assertSame(['customers.email'], self::names($statement->concatenatedFields));
    }

    public function testMapsConcatenatedComparisonsToTheirColumns(): void
    {
        $statement = $this->parse("UPDATE customers SET first_name = '", self::unsafe(), "' WHERE email = '", self::unsafe(), "'");

        self::assertSame(['customers.first_name'], self::names($statement->fields));
        self::assertSame(['customers.first_name', 'customers.email'], self::names($statement->concatenatedFields));
    }

    public function testSafeValuesAreNotConcatenated(): void
    {
        $statement = $this->parse('SELECT email FROM customers WHERE id = ', new SqlPlaceholder(true));

        self::assertSame([], $statement->concatenatedFields);
    }

    public function testKeepsFieldsCarriedByAConcatenatedValue(): void
    {
        $statement = $this->parse('SELECT id FROM orders WHERE note LIKE ', new SqlPlaceholder(false, [new FieldReference('customers', 'email')]));

        self::assertSame(['orders.note', 'customers.email'], self::names($statement->concatenatedFields));
    }

    public function testDeleteTouchesEveryColumnOfTheTable(): void
    {
        $statement = $this->parse('DELETE FROM orders WHERE created_at < NOW()');

        self::assertSame(FlowOperation::Delete, $statement->operation);
        self::assertSame(['orders.id', 'orders.customer_id', 'orders.total', 'orders.note'], self::names($statement->fields));
    }

    public function testIgnoresNonSqlStringsAndUnknownTables(): void
    {
        $parser = new SqlStatementParser();

        self::assertNull($parser->parse(new SqlFragment(['Bonjour, votre rendez-vous est confirmé'], 1), self::schema()));
        self::assertNull($parser->parse(new SqlFragment(['SELECT * FROM unknown_table'], 1), self::schema()));
    }

    private function parse(string|SqlPlaceholder ...$parts): SqlStatement
    {
        $statement = new SqlStatementParser()->parse(new SqlFragment(array_values($parts), 1), self::schema());
        self::assertNotNull($statement);

        return $statement;
    }

    private static function unsafe(): SqlPlaceholder
    {
        return new SqlPlaceholder(false);
    }

    /**
     * @param list<FieldReference> $fields
     *
     * @return list<string>
     */
    private static function names(array $fields): array
    {
        return array_map('strval', $fields);
    }

    private static function schema(): Schema
    {
        $table = static fn (string $name, string ...$columns): Table => new Table($name, array_values(array_map(
            static fn (string $column): Column => new Column(new FieldReference($name, $column), 'VARCHAR(100)', true, new SourceLocation('schema.sql', 1)),
            $columns,
        )), new SourceLocation('schema.sql', 1));

        return new Schema([$table('customers', 'id', 'email', 'first_name'), $table('orders', 'id', 'customer_id', 'total', 'note')]);
    }
}
