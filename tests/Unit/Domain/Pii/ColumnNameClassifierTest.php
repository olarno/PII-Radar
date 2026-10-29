<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Pii;

use App\Domain\Pii\ColumnNameClassifier;
use App\Domain\Pii\PiiCategory;
use App\Domain\Pii\Sensitivity;
use App\Domain\Schema\Column;
use App\Domain\Schema\FieldReference;
use App\Domain\SourceLocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColumnNameClassifierTest extends TestCase
{
    #[DataProvider('providePersonalDataNames')]
    public function testFlagsPersonalDataNames(string $name, PiiCategory $category, Sensitivity $sensitivity): void
    {
        $candidate = new ColumnNameClassifier()->classify(self::column($name));

        self::assertNotNull($candidate, $name);
        self::assertSame($category, $candidate->category, $name);
        self::assertSame($sensitivity, $candidate->sensitivity, $name);
        self::assertGreaterThan(0.0, $candidate->confidence);
        self::assertNotSame('', $candidate->reason);
    }

    /**
     * @return iterable<string, array{string, PiiCategory, Sensitivity}>
     */
    public static function providePersonalDataNames(): iterable
    {
        yield 'NAM' => ['nam', PiiCategory::GovernmentId, Sensitivity::High];
        yield 'SIN' => ['social_insurance_sin', PiiCategory::GovernmentId, Sensitivity::High];
        yield 'NAS' => ['nas', PiiCategory::GovernmentId, Sensitivity::High];
        yield 'health insurance' => ['numero_assurance_maladie', PiiCategory::GovernmentId, Sensitivity::High];
        yield 'birth date (fr)' => ['date_naissance', PiiCategory::Identity, Sensitivity::Medium];
        yield 'birth date (camelCase)' => ['dateOfBirth', PiiCategory::Identity, Sensitivity::Medium];
        yield 'first name' => ['prenom', PiiCategory::Identity, Sensitivity::Medium];
        yield 'last name (en)' => ['last_name', PiiCategory::Identity, Sensitivity::Medium];
        yield 'accented name' => ['prénom', PiiCategory::Identity, Sensitivity::Medium];
        yield 'username' => ['nom_utilisateur', PiiCategory::Identity, Sensitivity::Low];
        yield 'email (fr)' => ['courriel', PiiCategory::Contact, Sensitivity::Medium];
        yield 'email (en)' => ['customer_email', PiiCategory::Contact, Sensitivity::Medium];
        yield 'phone' => ['telephone', PiiCategory::Contact, Sensitivity::Medium];
        yield 'phone short' => ['tel_maison', PiiCategory::Contact, Sensitivity::Medium];
        yield 'postal code' => ['code_postal', PiiCategory::Location, Sensitivity::Medium];
        yield 'address' => ['adresse', PiiCategory::Location, Sensitivity::Medium];
        yield 'city' => ['ville', PiiCategory::Location, Sensitivity::Low];
        yield 'IP address (fr)' => ['adresse_ip', PiiCategory::Technical, Sensitivity::Low];
        yield 'last IP' => ['derniere_ip', PiiCategory::Technical, Sensitivity::Low];
        yield 'password (fr)' => ['mot_de_passe', PiiCategory::Technical, Sensitivity::High];
        yield 'password (en)' => ['password_hash', PiiCategory::Technical, Sensitivity::High];
        yield 'medical reason' => ['motif', PiiCategory::Health, Sensitivity::High];
        yield 'diagnosis' => ['diagnostic_principal', PiiCategory::Health, Sensitivity::High];
        yield 'card last digits' => ['carte_4_derniers', PiiCategory::Financial, Sensitivity::Low];
        yield 'card holder' => ['titulaire_carte', PiiCategory::Financial, Sensitivity::Medium];
        yield 'IBAN' => ['iban', PiiCategory::Financial, Sensitivity::High];
    }

    #[DataProvider('provideNeutralNames')]
    public function testIgnoresNeutralNames(string $name): void
    {
        self::assertNull(new ColumnNameClassifier()->classify(self::column($name)), $name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNeutralNames(): iterable
    {
        foreach (['id', 'patient_id', 'montant', 'statut', 'date_heure', 'cree_le', 'role', 'code_region', 'region', 'description', 'zipped_size', 'shipped'] as $name) {
            yield $name => [$name];
        }
    }

    public function testDampsConfidenceForTemplatesAndSettings(): void
    {
        $classifier = new ColumnNameClassifier();
        $email = $classifier->classify(self::column('email'));
        $template = $classifier->classify(self::column('email_template'));

        self::assertNotNull($email);
        self::assertNotNull($template);
        self::assertLessThan(0.5, $template->confidence);
        self::assertLessThan($email->confidence, $template->confidence);
        self::assertStringContainsString('template', $template->reason);
    }

    public function testAmbiguousNamesGetALowerConfidenceThanExplicitOnes(): void
    {
        $classifier = new ColumnNameClassifier();

        self::assertLessThan($classifier->classify(self::column('prenom'))?->confidence, $classifier->classify(self::column('nom'))?->confidence);
    }

    public function testTokenizesSnakeCaseCamelCaseAndAccents(): void
    {
        self::assertSame(['date', 'naissance'], ColumnNameClassifier::tokenize('date_naissance'));
        self::assertSame(['date', 'naissance'], ColumnNameClassifier::tokenize('dateNaissance'));
        self::assertSame(['prenom', 'du', 'pere'], ColumnNameClassifier::tokenize('Prénom-du-père'));
    }

    private static function column(string $name): Column
    {
        return new Column(new FieldReference('t', $name), 'VARCHAR(100)', true, new SourceLocation('schema.sql', 1));
    }
}
