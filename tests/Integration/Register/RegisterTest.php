<?php

declare(strict_types=1);

namespace App\Tests\Integration\Register;

use App\Application\Port\RegisterRenderer;
use App\Application\Port\ReportStore;
use App\Application\Register\RegisterBuilder;
use App\Application\Register\RegisterFormat;
use App\Application\ScanProject;
use App\Domain\Register\Recipient;
use App\Domain\Register\Register;
use App\Domain\Register\RegisterEntry;
use App\Domain\Rule\Jurisdiction;
use App\Domain\SourceLocation;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The register of Clinique Bonsoir, built from the arbitrated scan (replayed cassette).
 */
final class RegisterTest extends KernelTestCase
{
    private const string DEMO = __DIR__.'/../../../demo/legacy-app';

    private static ?Register $register = null;

    public function testTheInventoryHoldsTheConfirmedPersonalDataOnly(): void
    {
        $fields = array_map(static fn (RegisterEntry $entry): string => (string) $entry->field->column->reference, self::register()->entries);

        self::assertCount(16, $fields);
        self::assertContains('rendezvous.motif', $fields);
        self::assertNotContains('ville_reference.nom', $fields, 'Rejected by the agent.');
        self::assertSame(['patients', 'users', 'rendezvous', 'paiements'], self::register()->tables());
    }

    public function testEachEntryKnowsWhereItIsCollectedLoggedAndShared(): void
    {
        $nam = self::entry('patients.nam');
        self::assertSame(['public/inscription.php:8'], self::strings($nam->collectedIn));
        self::assertSame(['public/patient.php:11'], self::strings($nam->loggedIn));

        self::assertSame(['api.twilio.com'], self::entry('patients.telephone')->sharedWith);
    }

    public function testTwilioIsAThirdPartyOutsideQuebecAndTheConfirmationMailIsNot(): void
    {
        $thirdParties = self::register()->thirdParties();
        self::assertCount(1, $thirdParties);
        self::assertSame('api.twilio.com', $thirdParties[0]->name);
        self::assertSame(Jurisdiction::UnitedStates, $thirdParties[0]->jurisdiction);
        self::assertTrue($thirdParties[0]->isOutsideQuebec());
        self::assertSame(['patients.telephone', 'patients.prenom', 'patients.nom'], array_map('strval', $thirdParties[0]->fields));

        $mails = array_values(array_filter(self::register()->recipients, static fn (Recipient $recipient): bool => $recipient->dataSubject));
        self::assertSame(['Courriel (patients.courriel)'], array_map(static fn (Recipient $recipient): string => $recipient->name, $mails));
        self::assertFalse($mails[0]->isOutsideQuebec());
    }

    public function testRetentionAndSecurityMeasuresComeFromTheCode(): void
    {
        $deletions = [];
        foreach (self::register()->retention as $retention) {
            $deletions[$retention->table] = self::strings($retention->deletions);
        }
        self::assertSame(['patients' => [], 'users' => [], 'rendezvous' => ['cron/purge_rendezvous.php:6'], 'paiements' => []], $deletions);

        $measures = array_map(static fn ($measure): string => $measure->description.' : '.implode(', ', self::strings($measure->evidence)), self::register()->measures);
        self::assertContains('Écritures de renseignements personnels sans concaténation SQL (requêtes préparées) : public/paiement.php:7, public/rendezvous.php:7', $measures);
        self::assertCount(14, self::register()->gaps);
    }

    public function testTheLoi25DraftNeverGuessesWhatTheCodeCannotTell(): void
    {
        $markdown = self::render(RegisterFormat::Loi25);

        self::assertStringStartsWith('# Inventaire des renseignements personnels : legacy-app (brouillon)', $markdown);
        self::assertStringContainsString('pas un avis juridique', $markdown);
        self::assertStringContainsString('| `patients.nam` | Élevée | db/01-schema.sql:11 | public/inscription.php:8 | _À confirmer par le responsable_ |', $markdown);
        self::assertStringContainsString('**Renseignements sensibles** (art. 12) : `patients.nam`, `rendezvous.motif`.', $markdown);
        self::assertStringContainsString('**Communication hors Québec : une EFVP est requise avant la communication', $markdown);
        self::assertStringContainsString('| rendezvous | 1 | cron/purge_rendezvous.php:6 |', $markdown);
        self::assertStringContainsString('- [ ] Réaliser l\'EFVP et signer une entente écrite avec api.twilio.com.', $markdown);
        self::assertMatchesRegularExpression('/- `rendezvous\.motif` : .+/', $markdown, 'The agent\'s justification is quoted.');
        self::assertStringNotContainsString('&#', $markdown, 'Markdown, not HTML.');
        self::assertSame(1 + 16 + 1 + 4 + 2, substr_count($markdown, '_À confirmer par le responsable_'), 'The warning, one purpose per field, the responsible person, one retention period per table, the purpose and the agreement of the third party.');
    }

    public function testTheGdprDraftFollowsArticle30(): void
    {
        $markdown = self::render(RegisterFormat::Gdpr);

        self::assertStringStartsWith('# Registre des activités de traitement : legacy-app (brouillon, art. 30 RGPD)', $markdown);
        self::assertStringContainsString('**Catégories particulières (art. 9)** : `rendezvous.motif`.', $markdown);
        self::assertStringContainsString('**Numéros d\'identification nationaux (art. 87)** : `patients.nam`.', $markdown);
        self::assertStringContainsString('- api.twilio.com : États-Unis. Garanties', $markdown);
    }

    private static function render(RegisterFormat $format): string
    {
        return self::getContainer()->get(RegisterRenderer::class)->render(self::register(), $format);
    }

    private static function register(): Register
    {
        if (null === self::$register) {
            self::getContainer()->get(ScanProject::class)(self::DEMO);
            $stored = self::getContainer()->get(ReportStore::class)->load(self::DEMO);
            self::assertNotNull($stored);
            self::$register = self::getContainer()->get(RegisterBuilder::class)->build($stored);
        }

        return self::$register;
    }

    private static function entry(string $field): RegisterEntry
    {
        foreach (self::register()->entries as $entry) {
            if ((string) $entry->field->column->reference === $field) {
                return $entry;
            }
        }

        self::fail(\sprintf('%s is not in the register.', $field));
    }

    /**
     * @param list<SourceLocation> $locations
     *
     * @return list<string>
     */
    private static function strings(array $locations): array
    {
        return array_map('strval', $locations);
    }
}
