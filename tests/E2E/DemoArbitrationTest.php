<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Application\ScanProject;
use App\Application\ScanReport;
use App\Domain\Finding\Finding;
use App\Domain\Pii\DecisionSource;

/**
 * The full contract, with the arbiter agent: every planted finding, no extra finding, traps rejected.
 *
 * It replays tests/Fixtures/cassettes/demo-arbitration.json, a real Hugging Face run recorded once (no key needed to
 * replay). To record it again after changing the prompt, the tools or the demo:
 *
 *     rm tests/Fixtures/cassettes/demo-arbitration.json
 *     HUGGINGFACE_API_KEY=hf_... vendor/bin/phpunit tests/E2E/DemoArbitrationTest.php
 */
final class DemoArbitrationTest extends DemoContractTestCase
{
    private const string CASSETTE = __DIR__.'/../Fixtures/cassettes/demo-arbitration.json';

    private static ?ScanReport $report = null;

    protected function setUp(): void
    {
        $key = $_SERVER['HUGGINGFACE_API_KEY'] ?? getenv('HUGGINGFACE_API_KEY');
        if (!is_file(self::CASSETTE) && (!\is_string($key) || '' === $key)) {
            self::markTestIncomplete('Cassette absente : enregistrez-la une fois avec HUGGINGFACE_API_KEY=hf_... vendor/bin/phpunit tests/E2E/DemoArbitrationTest.php');
        }
    }

    public function testNoFindingBeyondTheContract(): void
    {
        self::assertSame([], array_map(
            static fn (Finding $finding): string => \sprintf('%s at %s on %s', $finding->rule, $finding->location, implode(', ', array_map('strval', $finding->fields))),
            self::extraFindings(),
        ));
    }

    public function testTheAgentRejectsTheTraps(): void
    {
        $decisions = self::decisions();

        foreach (self::section('not_pii') as $trap) {
            $field = self::string($trap, 'field');
            if (isset($decisions[$field])) {
                self::assertFalse($decisions[$field]->personal, \sprintf('%s: %s', $field, $decisions[$field]->justification));
            }
        }
    }

    public function testTheAgentConfirmsAmbiguousPersonalData(): void
    {
        $decisions = self::decisions();

        foreach (['patients.nom', 'rendezvous.motif'] as $field) {
            self::assertTrue($decisions[$field]->personal, \sprintf('%s: %s', $field, $decisions[$field]->justification));
            self::assertSame(DecisionSource::Agent, $decisions[$field]->decidedBy);
            self::assertNotSame('', $decisions[$field]->justification);
        }
    }

    protected static function findings(): array
    {
        return self::report()->findings;
    }

    /**
     * @return array<string, \App\Domain\Pii\ClassifiedField>
     */
    private static function decisions(): array
    {
        $decisions = [];
        foreach (self::report()->classifiedFields as $field) {
            $decisions[(string) $field->column->reference] = $field;
        }

        return $decisions;
    }

    private static function report(): ScanReport
    {
        if (null === self::$report) {
            $scan = self::getContainer()->get(ScanProject::class);
            self::$report = $scan(self::DEMO_DIR);
            self::assertTrue(self::$report->arbitrated);
        }

        return self::$report;
    }
}
