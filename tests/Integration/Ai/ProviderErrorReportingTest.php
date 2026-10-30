<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ai;

use App\Application\Port\ArbitrationFailed;
use App\Domain\Pii\ColumnNameClassifier;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Pii\PiiMasker;
use App\Infrastructure\Ai\AuditSession;
use App\Infrastructure\Ai\Http\ErrorBodyRecordingHttpClient;
use App\Infrastructure\Ai\SymfonyAiCandidateArbiter;
use App\Infrastructure\Scanner\SqlDumpSchemaReader;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Bridge\HuggingFace\Factory;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * With the real Hugging Face bridge: a provider error must reach the user with its reason, not as a bare "Bad Request".
 */
final class ProviderErrorReportingTest extends TestCase
{
    private const string PROJECT = __DIR__.'/../../Fixtures/code/clinic';

    public function testABadRequestKeepsTheProviderReason(): void
    {
        $failure = $this->arbitrate(new MockResponse('{"error": "response_format json_schema is not supported by this provider"}', ['http_code' => 400]));

        self::assertStringContainsString('Bad Request', $failure->getMessage());
        self::assertStringContainsString('HTTP 400: {"error": "response_format json_schema is not supported by this provider"}', $failure->getMessage());
        self::assertFalse($failure->authentication);
    }

    public function testAnInvalidTokenIsReportedAsSuch(): void
    {
        $failure = $this->arbitrate(new MockResponse('{"error": "Invalid credentials in Authorization header"}', ['http_code' => 401]));

        self::assertTrue($failure->authentication);
    }

    private function arbitrate(MockResponse $response): ArbitrationFailed
    {
        $http = new ErrorBodyRecordingHttpClient(new MockHttpClient($response));
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PlatformSubscriber());
        $platform = Factory::createPlatform('hf_test', httpClient: $http, eventDispatcher: $dispatcher);

        $session = new AuditSession(new PiiMasker());
        $arbiter = new SymfonyAiCandidateArbiter(new Agent($platform, 'openai/gpt-oss-120b'), $session, $http);

        $schema = new SqlDumpSchemaReader()->read(self::PROJECT);
        $classifier = new ColumnNameClassifier();
        $candidates = array_values(array_filter(array_map($classifier->classify(...), $schema->columns()), static fn (?PiiCandidate $candidate): bool => null !== $candidate));

        try {
            $arbiter->arbitrate(self::PROJECT, $schema, [], $candidates);
        } catch (ArbitrationFailed $failure) {
            return $failure;
        }

        self::fail('The arbitration should have failed.');
    }
}
