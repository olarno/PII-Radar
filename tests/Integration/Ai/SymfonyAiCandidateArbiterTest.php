<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ai;

use App\Application\Port\ArbitrationFailed;
use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\ColumnNameClassifier;
use App\Domain\Pii\DecisionSource;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Pii\PiiMasker;
use App\Domain\Schema\Schema;
use App\Infrastructure\Ai\AuditSession;
use App\Infrastructure\Ai\SymfonyAiCandidateArbiter;
use App\Infrastructure\Ai\Tool\DescribeTableTool;
use App\Infrastructure\Ai\Tool\FindColumnUsagesTool;
use App\Infrastructure\Ai\Tool\ReadCodeSnippetTool;
use App\Infrastructure\Scanner\Php\PhpCodeScanner;
use App\Infrastructure\Scanner\Sql\SqlStatementParser;
use App\Infrastructure\Scanner\SqlDumpSchemaReader;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Drives the real agent loop (tools, structured output) against a scripted platform: no network, no key.
 */
final class SymfonyAiCandidateArbiterTest extends TestCase
{
    private const string PROJECT = __DIR__.'/../../Fixtures/code/clinic';

    /** @var list<mixed> payloads sent to the model */
    private array $payloads = [];

    public function testTheAgentUsesItsToolsAndItsVerdictsBecomeDecisions(): void
    {
        $arbiter = $this->arbiter([
            new ToolCallResult([new ToolCall('call_1', 'read_code_snippet', ['file' => 'src/patients.php', 'line' => 2])]),
            new TextResult((string) json_encode(['verdicts' => [
                ['field' => 'patients.nom', 'isPersonalData' => true, 'category' => 'identity', 'sensitivity' => 'medium', 'confidence' => 0.95, 'justification' => 'Nom d\'un patient, journalisé dans src/patients.php.'],
                ['field' => 'ville_reference.nom', 'isPersonalData' => false, 'category' => 'location', 'sensitivity' => 'low', 'confidence' => 0.9, 'justification' => 'Liste de référence des villes, sans lien avec une personne.'],
            ]])),
        ]);

        [$schema, $flows, $candidates] = $this->audit();
        $decisions = $arbiter->arbitrate(self::PROJECT, $schema, $flows, $candidates);

        self::assertCount(2, $decisions);
        self::assertTrue($decisions[0]->personal);
        self::assertSame(DecisionSource::Agent, $decisions[0]->decidedBy);
        self::assertSame(0.95, $decisions[0]->confidence);
        self::assertFalse($decisions[1]->personal);
        self::assertSame('Liste de référence des villes, sans lien avec une personne.', $decisions[1]->justification);

        $sentBack = (string) json_encode($this->payloads[1]);
        self::assertStringContainsString('[COURRIEL]', $sentBack, 'The tool result reached the model, masked.');
        self::assertStringNotContainsString('jeanne', $sentBack);
        self::assertStringNotContainsString('TREJ85011512', $sentBack);
        self::assertStringContainsString('ville_reference (id, nom)', (string) json_encode($this->payloads[0]), 'The prompt describes the schema.');
    }

    public function testACandidateWithoutVerdictFallsBackToTheHeuristic(): void
    {
        $arbiter = $this->arbiter([new TextResult('{"verdicts": [{"field": "patients.nom", "isPersonalData": true, "category": "identity", "sensitivity": "medium", "confidence": 0.9, "justification": "Nom du patient."}]}')]);

        [$schema, $flows, $candidates] = $this->audit();
        $decisions = $arbiter->arbitrate(self::PROJECT, $schema, $flows, $candidates);

        self::assertSame(DecisionSource::Rules, $decisions[1]->decidedBy);
        self::assertStringContainsString('pas rendu de verdict', $decisions[1]->justification);
    }

    public function testPlatformErrorsBecomeArbitrationFailures(): void
    {
        $arbiter = $this->arbiter(static fn () => throw new \RuntimeException('401 Unauthorized'));

        [$schema, $flows, $candidates] = $this->audit();

        $this->expectException(ArbitrationFailed::class);
        $this->expectExceptionMessage('401 Unauthorized');
        $arbiter->arbitrate(self::PROJECT, $schema, $flows, $candidates);
    }

    /**
     * @param list<ToolCallResult|TextResult>|\Closure $script
     */
    private function arbiter(array|\Closure $script): SymfonyAiCandidateArbiter
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PlatformSubscriber());

        $calls = 0;
        $platform = MockPlatformFactory::createPlatform(
            $script instanceof \Closure ? $script : function ($model, $input) use ($script, &$calls) {
                $this->payloads[] = $input;

                return $script[$calls++] ?? new TextResult('{"verdicts": []}');
            },
            eventDispatcher: $dispatcher,
        );

        $session = new AuditSession(new PiiMasker());
        $toolbox = new Toolbox([new DescribeTableTool($session), new FindColumnUsagesTool($session), new ReadCodeSnippetTool($session)]);

        return new SymfonyAiCandidateArbiter(new Agent($platform, 'test-model', toolbox: $toolbox), $session);
    }

    /**
     * @return array{Schema, list<FlowPoint>, list<PiiCandidate>}
     */
    private function audit(): array
    {
        $schema = new SqlDumpSchemaReader()->read(self::PROJECT);
        $classifier = new ColumnNameClassifier();
        $candidates = array_values(array_filter(
            array_map($classifier->classify(...), $schema->columns()),
            static fn (?PiiCandidate $candidate): bool => null !== $candidate && $candidate->confidence < 0.8,
        ));

        return [$schema, new PhpCodeScanner(new SqlStatementParser())->scan(self::PROJECT, $schema), $candidates];
    }
}
