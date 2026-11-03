<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\AgentUsage;
use App\Application\Port\Arbitration;
use App\Application\Port\CandidateArbiter;
use App\Application\ScanProject;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Pii\ColumnNameClassifier;
use App\Domain\Pii\DecisionSource;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Schema\Schema;
use App\Infrastructure\Scanner\Php\PhpCodeScanner;
use App\Infrastructure\Scanner\Sql\SqlStatementParser;
use App\Infrastructure\Scanner\SqlDumpSchemaReader;
use App\Infrastructure\Scanner\WebrootDataFileFinder;
use PHPUnit\Framework\TestCase;

final class ScanProjectTest extends TestCase
{
    private const string PROJECT = __DIR__.'/../../Fixtures/code/clinic';

    public function testOnlyAmbiguousCandidatesReachTheArbiter(): void
    {
        $arbiter = new RecordingArbiter();
        $report = $this->scan($arbiter)(self::PROJECT);

        self::assertSame(['patients.nom', 'ville_reference.nom'], $arbiter->received, 'courriel (0.9) is decided by the rules.');
        self::assertTrue($report->arbitrated);
        self::assertSame(9000, $report->agentUsage?->inputTokens);

        $decided = [];
        foreach ($report->classifiedFields as $field) {
            $decided[(string) $field->column->reference] = [$field->personal, $field->decidedBy];
        }
        self::assertSame([
            'patients.nom' => [true, DecisionSource::Agent],
            'patients.courriel' => [true, DecisionSource::Rules],
            'ville_reference.nom' => [false, DecisionSource::Agent],
        ], $decided);
    }

    public function testARejectedCandidateRaisesNoFinding(): void
    {
        $report = $this->scan(new RecordingArbiter())(self::PROJECT);

        foreach ($report->findings as $finding) {
            foreach ($finding->fields as $field) {
                self::assertNotSame('ville_reference', $field->table);
            }
        }
    }

    public function testWithoutArbitrationTheHeuristicDecides(): void
    {
        $arbiter = new RecordingArbiter();
        $report = $this->scan($arbiter)(self::PROJECT, arbitrate: false);

        self::assertSame([], $arbiter->received);
        self::assertFalse($report->arbitrated);
        foreach ($report->classifiedFields as $field) {
            self::assertSame(DecisionSource::Rules, $field->decidedBy);
        }
    }

    private function scan(CandidateArbiter $arbiter): ScanProject
    {
        return new ScanProject(
            new SqlDumpSchemaReader(),
            new PhpCodeScanner(new SqlStatementParser()),
            new WebrootDataFileFinder(),
            new ColumnNameClassifier(),
            $arbiter,
            [new \App\Domain\Rule\NoRetentionRule(), new \App\Domain\Rule\PersonalDataInLogsRule()],
        );
    }
}

/**
 * Keeps names of tables that look like reference data out of personal data, like the agent would.
 */
final class RecordingArbiter implements CandidateArbiter
{
    /** @var list<string> */
    public array $received = [];

    public function arbitrate(string $projectPath, Schema $schema, array $flows, array $candidates): Arbitration
    {
        $this->received = array_map(static fn (PiiCandidate $candidate): string => (string) $candidate->column->reference, $candidates);

        return new Arbitration(array_map(static fn (PiiCandidate $candidate): ClassifiedField => new ClassifiedField(
            $candidate->column,
            !str_ends_with($candidate->column->reference->table, '_reference'),
            $candidate->category,
            $candidate->sensitivity,
            0.9,
            'Décision de test.',
            DecisionSource::Agent,
        ), $candidates), new AgentUsage(3, 9000, 400));
    }
}
