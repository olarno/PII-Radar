<?php

declare(strict_types=1);

namespace App\Tests\Integration\Scanner;

use App\Domain\Flow\FlowOperation;
use App\Domain\Flow\FlowPoint;
use App\Infrastructure\Scanner\Php\PhpCodeScanner;
use App\Infrastructure\Scanner\Sql\SqlStatementParser;
use App\Infrastructure\Scanner\SqlDumpSchemaReader;
use PHPUnit\Framework\TestCase;

final class PhpCodeScannerTest extends TestCase
{
    private const string PROJECT = __DIR__.'/../../Fixtures/code/shop';

    public function testPreparedStatementsAreStoredWithoutConcatenation(): void
    {
        $insert = $this->flow(FlowOperation::Store, 12);

        self::assertSame('customers', $insert->target);
        self::assertSame(['customers.email', 'customers.first_name', 'customers.password'], self::names($insert->fields));
        self::assertSame([], $insert->concatenatedFields);
    }

    public function testPasswordHashIsAHashFlow(): void
    {
        $hash = $this->flow(FlowOperation::Hash, 13);

        self::assertSame('password_hash', $hash->target);
        self::assertSame(['customers.password'], self::names($hash->fields));
    }

    public function testLoggerCallsAreLogFlows(): void
    {
        $log = $this->flow(FlowOperation::Log, 15);

        self::assertSame('logger', $log->target);
        self::assertSame(['customers.email'], self::names($log->fields));
    }

    public function testHttpClientCallsAreSendFlowsToTheirHost(): void
    {
        $send = $this->flow(FlowOperation::Send, 16);

        self::assertSame('hooks.example-crm.com', $send->target);
        self::assertSame(['customers.email'], self::names($send->fields));
    }

    public function testInterpolatedAndAppendedSqlIsConcatenated(): void
    {
        $search = $this->flow(FlowOperation::Read, 21);

        self::assertSame(['customers.id', 'customers.first_name'], self::names($search->fields));
        self::assertSame(['customers.email'], self::names($search->concatenatedFields));
    }

    public function testRequestKeysAreCollected(): void
    {
        $collect = $this->flow(FlowOperation::Collect, 11);

        self::assertSame(['customers.email', 'customers.first_name', 'customers.password'], self::names($collect->fields));
    }

    public function testFilesThatDoNotParseAreSkipped(): void
    {
        foreach ($this->scan() as $flow) {
            self::assertNotSame('src/broken.php', $flow->location->file);
        }
    }

    private function flow(FlowOperation $operation, int $line): FlowPoint
    {
        foreach ($this->scan() as $flow) {
            if ($flow->operation === $operation && $line === $flow->location->line) {
                return $flow;
            }
        }

        self::fail(\sprintf('No %s flow at line %d.', $operation->value, $line));
    }

    /**
     * @return list<FlowPoint>
     */
    private function scan(): array
    {
        $schema = new SqlDumpSchemaReader()->read(self::PROJECT);

        return new PhpCodeScanner(new SqlStatementParser())->scan(self::PROJECT, $schema);
    }

    /**
     * @param list<\App\Domain\Schema\FieldReference> $fields
     *
     * @return list<string>
     */
    private static function names(array $fields): array
    {
        return array_map('strval', $fields);
    }
}
