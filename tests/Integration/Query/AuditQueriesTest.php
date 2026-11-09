<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Application\Query\AuditQueries;
use App\Application\Query\FieldSheet;
use App\Application\Query\LogVerdict;
use App\Application\ScanProject;
use App\Application\ScanReport;
use App\Domain\Finding\Severity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Queries over the arbitrated scan of Clinique Bonsoir (replayed cassette: no key, no network).
 */
final class AuditQueriesTest extends KernelTestCase
{
    private static ?ScanReport $report = null;

    public function testWhereIsAnswersAQuestionInFrench(): void
    {
        $sheets = new AuditQueries()->whereIs(self::report(), 'Où vit le courriel du patient ?');

        self::assertSame('patients.courriel', self::names($sheets)[0], 'The patient table wins over the staff table.');
        self::assertContains('users.courriel', self::names($sheets));
        self::assertNotContains('parametres_courriel.email_template', self::names($sheets), 'Rejected traps are not personal data.');
    }

    public function testWhereIsUnderstandsSynonymsAndCategories(): void
    {
        $queries = new AuditQueries();

        self::assertContains('patients.telephone', self::names($queries->whereIs(self::report(), 'phone number')));
        self::assertSame(['patients.telephone'], self::names($queries->whereIs(self::report(), 'téléphone du patient')), 'The table name does not drag every patient field along.');
        self::assertCount(9, $queries->whereIs(self::report(), 'les données des patients'), 'Naming only the table lists its personal fields.');
        self::assertContains('patients.nam', self::names($queries->whereIs(self::report(), "numéro d'assurance maladie")));
        self::assertSame(['rendezvous.motif'], self::names($queries->whereIs(self::report(), 'données de santé')));
        self::assertSame(['patients.nam'], self::names($queries->whereIs(self::report(), 'patients.nam')));
        self::assertSame([], $queries->whereIs(self::report(), 'le prix du café'));
    }

    public function testTheSheetCollectsFlowsAndFindings(): void
    {
        $queries = new AuditQueries();
        $sheet = $queries->sheet(self::report(), $queries->resolve(self::report(), 'patients.nam')[0]);

        self::assertTrue($sheet->isPersonal());
        $rules = array_values(array_unique(array_map(static fn ($finding): string => $finding->rule, $sheet->findings)));
        sort($rules);
        self::assertSame(['PII-001', 'PII-002', 'PII-004', 'PII-006', 'PII-007'], $rules);
        self::assertContains('public/patient.php:11', array_map(static fn ($flow): string => (string) $flow->location, $sheet->flows));
    }

    public function testCanILogDependsOnTheClassification(): void
    {
        $queries = new AuditQueries();
        $advice = static fn (string $field) => $queries->canLog(self::report(), $queries->resolve(self::report(), $field)[0]);

        self::assertSame(LogVerdict::Forbidden, $advice('patients.nam')->verdict);
        self::assertSame(['public/patient.php:11'], array_map(static fn ($flow): string => (string) $flow->location, $advice('patients.nam')->existingLogs));
        self::assertSame(LogVerdict::Avoid, $advice('users.derniere_ip')->verdict);
        self::assertSame(LogVerdict::Allowed, $advice('ville_reference.nom')->verdict, 'The agent rejected this trap.');
        self::assertStringStartsWith('ville_reference.nom is not personal data: ', $advice('ville_reference.nom')->reason);
        self::assertSame(LogVerdict::Allowed, $advice('patients.id')->verdict);
    }

    public function testResolveAcceptsBareColumnNames(): void
    {
        $queries = new AuditQueries();

        self::assertCount(1, $queries->resolve(self::report(), 'nam'));
        self::assertCount(2, $queries->resolve(self::report(), 'courriel'), 'Ambiguous: patients and users.');
        self::assertSame([], $queries->resolve(self::report(), 'unknown'));
    }

    public function testFindingsCanBeFiltered(): void
    {
        $queries = new AuditQueries();

        self::assertCount(14, $queries->findings(self::report()));
        self::assertCount(2, $queries->findings(self::report(), Severity::Critical));
        self::assertCount(3, $queries->findings(self::report(), file: 'login.php'));
        self::assertCount(3, $queries->findings(self::report(), rule: 'pii-006'));
    }

    private static function report(): ScanReport
    {
        return self::$report ??= self::getContainer()->get(ScanProject::class)(__DIR__.'/../../../demo/legacy-app');
    }

    /**
     * @param list<FieldSheet> $sheets
     *
     * @return list<string>
     */
    private static function names(array $sheets): array
    {
        return array_map(static fn (FieldSheet $sheet): string => (string) $sheet->column->reference, $sheets);
    }
}
