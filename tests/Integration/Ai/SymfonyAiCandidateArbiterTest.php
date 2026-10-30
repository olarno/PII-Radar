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
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Drives the real agents (investigation with tools, then structured verdicts) against a scripted platform: no network, no key.
 */
final class SymfonyAiCandidateArbiterTest extends TestCase
{
    private const string PROJECT = __DIR__.'/../../Fixtures/code/clinic';

    /** @var list<mixed> payloads sent to the model */
    private array $payloads = [];

    /** @var list<mixed> options of each call to the model */
    private array $options = [];

    public function testTheAgentUsesItsToolsAndItsVerdictsBecomeDecisions(): void
    {
        $arbiter = $this->arbiter([
            new ToolCallResult([new ToolCall('call_1', 'read_code_snippet', ['file' => 'src/patients.php', 'line' => 2])]),
            new TextResult("patients.nom | personal data: yes | identity | medium | 0.95 | Nom d'un patient, journalisé dans src/patients.php.\nville_reference.nom | personal data: no | location | low | 0.9 | Liste de référence des villes."),
            new TextResult((string) json_encode(['verdicts' => [
                ['field' => 'patients.nom', 'isPersonalData' => true, 'category' => 'identity', 'sensitivity' => 'medium', 'confidence' => 0.95, 'justification' => 'Nom d\'un patient, journalisé dans src/patients.php.'],
                ['field' => 'ville_reference.nom', 'isPersonalData' => false, 'category' => 'location', 'sensitivity' => 'low', 'confidence' => 0.9, 'justification' => 'Liste de référence des villes, sans lien avec une personne.'],
            ]])),
        ]);

        [$schema, $flows, $candidates] = $this->audit();
        $arbitration = $arbiter->arbitrate(self::PROJECT, $schema, $flows, $candidates);
        $decisions = $arbitration->decisions;

        self::assertCount(2, $decisions);
        self::assertSame([3, 3 * 1200, 3 * 80], [$arbitration->usage?->calls, $arbitration->usage?->inputTokens, $arbitration->usage?->outputTokens], 'Token usage is summed over both agents.');
        foreach ($this->options as $index => $options) {
            self::assertIsArray($options);
            self::assertSame(4096, $options['max_output_tokens'] ?? null, 'Every call caps the output.');
            self::assertFalse(isset($options['tools']) && isset($options['response_format']), \sprintf('Call %d mixes "tools" and "response_format", which providers reject.', $index));
        }
        self::assertArrayHasKey('tools', self::array($this->options[0]), 'The investigation uses tools.');
        self::assertArrayHasKey('response_format', self::array($this->options[2]), 'The verdicts are structured.');
        self::assertStringContainsString("Investigator's conclusions", (string) json_encode($this->payloads[2]), 'The verdicts agent receives the investigation.');
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
        $arbiter = $this->arbiter([new TextResult('patients.nom | personal data: yes'), new TextResult('{"verdicts": [{"field": "patients.nom", "isPersonalData": true, "category": "identity", "sensitivity": "medium", "confidence": 0.9, "justification": "Nom du patient."}]}')]);

        [$schema, $flows, $candidates] = $this->audit();
        $decisions = $arbiter->arbitrate(self::PROJECT, $schema, $flows, $candidates)->decisions;

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
            $script instanceof \Closure ? $script : function ($model, $input, $options) use ($script, &$calls) {
                $this->payloads[] = $input;
                $this->options[] = $options;
                $result = $script[$calls++] ?? new TextResult('{"verdicts": []}');
                $result->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 1200, completionTokens: 80, totalTokens: 1280));

                return $result;
            },
            eventDispatcher: $dispatcher,
        );

        $session = new AuditSession(new PiiMasker());
        $toolbox = new Toolbox([new DescribeTableTool($session), new FindColumnUsagesTool($session), new ReadCodeSnippetTool($session)]);

        return new SymfonyAiCandidateArbiter(new Agent($platform, 'test-model', toolbox: $toolbox), new Agent($platform, 'test-model'), $session);
    }

    /**
     * @return array<mixed>
     */
    private static function array(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
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
