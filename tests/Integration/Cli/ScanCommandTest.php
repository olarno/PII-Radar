<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cli;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ScanCommandTest extends KernelTestCase
{
    public function testPrintsCandidatesAsJson(): void
    {
        $tester = $this->tester();
        $tester->execute(['path' => __DIR__.'/../../../demo/legacy-app', '--format' => 'json', '--no-ai' => true]);

        $tester->assertCommandIsSuccessful();
        $output = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($output);
        self::assertSame(['tables' => 6, 'columns' => 38], $output['schema']);
        self::assertIsArray($output['candidates']);
        self::assertContains(
            ['field' => 'patients.nam', 'type' => 'VARCHAR(12)', 'category' => 'government_id', 'sensitivity' => 'high', 'confidence' => 0.9, 'location' => ['file' => 'db/01-schema.sql', 'line' => 11], 'reason' => 'Le nom de colonne contient « nam ».'],
            $output['candidates'],
        );
        self::assertIsArray($output['findings']);
        self::assertContains(
            ['rule' => 'PII-008', 'severity' => 'medium', 'location' => ['file' => 'public/rappel.php', 'line' => 14], 'fields' => ['patients.telephone', 'patients.prenom', 'patients.nom'], 'summary' => 'patients.telephone, patients.prenom, patients.nom envoyé à api.twilio.com (États-Unis) : communication hors Québec.', 'remediation' => 'Réaliser une évaluation des facteurs relatifs à la vie privée (EFVP) avant la communication, et l\'encadrer par une entente écrite.', 'recipient' => 'api.twilio.com', 'jurisdiction' => 'US'],
            $output['findings'],
        );
        self::assertIsArray($output['flows']);
        self::assertContains(
            ['operation' => 'send', 'fields' => ['patients.telephone', 'patients.prenom', 'patients.nom', 'rendezvous.date_heure'], 'location' => ['file' => 'public/rappel.php', 'line' => 14], 'target' => 'api.twilio.com', 'concatenated_fields' => []],
            $output['flows'],
        );
    }

    public function testPrintsATableByDefault(): void
    {
        $tester = $this->tester();
        $tester->execute(['path' => __DIR__.'/../../../demo/legacy-app', '--no-ai' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('patients.nam', $tester->getDisplay());
        self::assertStringContainsString('Identifiant gouvernemental', $tester->getDisplay());
        self::assertStringContainsString('api.twilio.com', $tester->getDisplay());
        self::assertStringContainsString('PII-005', $tester->getDisplay());
    }

    public function testFailsOnAMissingDirectory(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['path' => '/does/not/exist']));
        self::assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function testRejectsAnUnknownFormat(): void
    {
        self::assertSame(Command::INVALID, $this->tester()->execute(['path' => '.', '--format' => 'xml']));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find('pii:scan'));
    }
}
