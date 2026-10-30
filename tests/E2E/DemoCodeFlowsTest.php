<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Application\ScanProject;
use App\Domain\Flow\FlowOperation;
use App\Domain\Flow\FlowPoint;
use App\Domain\Schema\FieldReference;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The flows behind every finding planted in Clinique Bonsoir, at the line expected-findings.json names.
 */
final class DemoCodeFlowsTest extends KernelTestCase
{
    /** @var list<FlowPoint>|null */
    private static ?array $flows = null;

    /**
     * @param list<string> $fields
     * @param list<string> $concatenated
     */
    #[DataProvider('providePlantedFlows')]
    public function testPlantedFlowIsFound(FlowOperation $operation, string $location, ?string $target, array $fields, array $concatenated = []): void
    {
        $flow = $this->flowAt($operation, $location);

        self::assertSame($target, $flow->target);
        foreach ($fields as $field) {
            self::assertTrue($flow->involves(FieldReference::fromString($field)), \sprintf('%s does not involve %s.', $location, $field));
        }
        self::assertSame($concatenated, self::pii($flow->concatenatedFields));
    }

    /**
     * @return iterable<string, array{FlowOperation, string, ?string, list<string>, 4?: list<string>}>
     */
    public static function providePlantedFlows(): iterable
    {
        $patient = ['patients.prenom', 'patients.nom', 'patients.date_naissance', 'patients.nam', 'patients.courriel', 'patients.telephone', 'patients.adresse', 'patients.ville', 'patients.code_postal'];

        yield 'F03 error_log of the patient record' => [FlowOperation::Log, 'public/patient.php:11', 'error_log', $patient];
        yield 'F04 payment journal' => [FlowOperation::Log, 'public/paiement.php:13', 'logs/paiements.log', ['paiements.titulaire_carte', 'paiements.carte_4_derniers']];
        yield 'F05/F06 SMS sent to Twilio' => [FlowOperation::Send, 'public/rappel.php:14', 'api.twilio.com', ['patients.prenom', 'patients.nom', 'patients.telephone']];
        yield 'F07 concatenated INSERT' => [FlowOperation::Store, 'public/inscription.php:7', 'patients', $patient, $patient];
        yield 'F08 concatenated login query' => [FlowOperation::Read, 'public/login.php:9', 'users', ['users.courriel'], ['users.courriel']];
        yield 'F09 concatenated IP update' => [FlowOperation::Store, 'public/login.php:16', 'users', ['users.derniere_ip'], ['users.derniere_ip']];
        yield 'F10 MD5 password' => [FlowOperation::Hash, 'public/login.php:7', 'md5', ['users.mot_de_passe']];
        yield 'rendezvous purge (no PII-006)' => [FlowOperation::Delete, 'cron/purge_rendezvous.php:6', 'rendezvous', ['rendezvous.motif']];
        yield 'confirmation mailed to the patient (no PII-003)' => [FlowOperation::Send, 'public/rendezvous.php:15', 'mail:patients.courriel', ['patients.courriel', 'patients.prenom']];
    }

    public function testIntegerIdsAreNeverReportedAsConcatenated(): void
    {
        foreach (self::flows() as $flow) {
            if (\in_array($flow->location->file, ['public/patient.php', 'public/rappel.php', 'public/rendezvous.php'], true)) {
                self::assertSame([], self::pii($flow->concatenatedFields), (string) $flow->location);
            }
        }
    }

    public function testFormsAreCollectedIntoTheRightTable(): void
    {
        self::assertTrue($this->flowAt(FlowOperation::Collect, 'public/inscription.php:8')->involves(new FieldReference('patients', 'nom')));
        self::assertFalse($this->flowAt(FlowOperation::Collect, 'public/inscription.php:8')->involves(new FieldReference('ville_reference', 'nom')));
    }

    private function flowAt(FlowOperation $operation, string $location): FlowPoint
    {
        foreach (self::flows() as $flow) {
            if ($flow->operation === $operation && (string) $flow->location === $location) {
                return $flow;
            }
        }

        self::fail(\sprintf('No %s flow at %s.', $operation->value, $location));
    }

    /**
     * @return list<FlowPoint>
     */
    private static function flows(): array
    {
        if (null === self::$flows) {
            $scan = self::getContainer()->get(ScanProject::class);
            self::$flows = $scan(__DIR__.'/../../demo/legacy-app')->flows;
        }

        return self::$flows;
    }

    /**
     * Concatenated fields that the contract lists as personal data (ids are not).
     *
     * @param list<FieldReference> $fields
     *
     * @return list<string>
     */
    private static function pii(array $fields): array
    {
        return array_values(array_filter(array_map('strval', $fields), static fn (string $field): bool => !str_ends_with($field, '.id')));
    }
}
