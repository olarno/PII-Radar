<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ai;

use App\Domain\Pii\PiiMasker;
use App\Infrastructure\Ai\AuditSession;
use App\Infrastructure\Ai\Tool\DescribeTableTool;
use App\Infrastructure\Ai\Tool\FindColumnUsagesTool;
use App\Infrastructure\Ai\Tool\ReadCodeSnippetTool;
use App\Infrastructure\Scanner\Php\PhpCodeScanner;
use App\Infrastructure\Scanner\Sql\SqlStatementParser;
use App\Infrastructure\Scanner\SqlDumpSchemaReader;
use PHPUnit\Framework\TestCase;

final class AgentToolsTest extends TestCase
{
    private const string PROJECT = __DIR__.'/../../Fixtures/code/clinic';

    private AuditSession $session;

    protected function setUp(): void
    {
        $schema = new SqlDumpSchemaReader()->read(self::PROJECT);
        $this->session = new AuditSession(new PiiMasker());
        $this->session->start(self::PROJECT, $schema, new PhpCodeScanner(new SqlStatementParser())->scan(self::PROJECT, $schema));
    }

    public function testFindColumnUsagesListsFlows(): void
    {
        $usages = new FindColumnUsagesTool($this->session)('patients.nom');

        self::assertStringContainsString('src/patients.php:3: read → patients', $usages);
        self::assertStringContainsString('src/patients.php:4: log → error_log', $usages);
        self::assertSame('No code flow uses ville_reference.id.', new FindColumnUsagesTool($this->session)('ville_reference.id'));
        self::assertStringContainsString('not a "table.column"', new FindColumnUsagesTool($this->session)('nom'));
    }

    public function testReadCodeSnippetMasksPersonalDataValues(): void
    {
        $snippet = new ReadCodeSnippetTool($this->session)('src/patients.php', 2);

        self::assertStringContainsString('2 | // Test account: [COURRIEL], NAM [NAM]', $snippet);
        self::assertStringNotContainsString('jeanne', $snippet);
        self::assertStringNotContainsString('TREJ85011512', $snippet);
    }

    public function testReadCodeSnippetStaysInsideTheProject(): void
    {
        $tool = new ReadCodeSnippetTool($this->session);

        self::assertStringContainsString('not a readable source file', $tool('../../../../.env', 1));
        self::assertStringContainsString('not a readable source file', $tool('/etc/passwd', 1));
        self::assertStringContainsString('not a readable source file', $tool('src/missing.php', 1));
    }

    public function testDescribeTableNeverReturnsValues(): void
    {
        $tool = new DescribeTableTool($this->session);

        self::assertStringContainsString("- nom VARCHAR(100) NOT NULL\n", $tool('ville_reference').\PHP_EOL);
        self::assertStringContainsString('Unknown table "clients". Known tables: patients, ville_reference.', $tool('clients'));
    }
}
