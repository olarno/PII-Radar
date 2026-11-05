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
        self::assertSame(1, $output['version']);
        self::assertIsArray($output['schema']);
        self::assertCount(6, $output['schema']);
        self::assertIsArray($output['candidates']);
        self::assertContains(
            ['field' => 'patients.nam', 'category' => 'government_id', 'sensitivity' => 'high', 'confidence' => 0.9, 'reason' => 'Le nom de colonne contient « nam ».'],
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

    public function testReplaysTheRecordedAgentRunOnTheDemoWithoutAToken(): void
    {
        $tester = $this->tester();
        $tester->execute(['path' => __DIR__.'/../../../demo/legacy-app', '--replay' => true, '--format' => 'json']);

        $tester->assertCommandIsSuccessful();
        $output = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($output);
        self::assertTrue($output['replayed']);
        self::assertTrue($output['arbitrated']);
        self::assertIsArray($output['findings']);
        self::assertCount(14, $output['findings'], 'The traps are rejected by the replayed agent.');
    }

    public function testSaysWhenAnswersAreReplayed(): void
    {
        $tester = $this->tester();
        $tester->execute(['path' => __DIR__.'/../../../demo/legacy-app', '--replay' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('réponses rejouées depuis un vrai appel enregistré', self::display($tester));
        self::assertStringContainsString('Rejeu : les réponses du modèle viennent de agent-arbitration.json', self::display($tester));
    }

    public function testReplayOnlyWorksOnTheDemo(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['path' => __DIR__.'/../../Fixtures/code/clinic', '--replay' => true]));
        self::assertStringContainsString('ne fonctionne que sur l\'appli de démo', self::display($tester));
        self::assertSame(Command::INVALID, $this->tester()->execute(['path' => '.', '--replay' => true, '--no-ai' => true]));
    }

    public function testFailsOnAMissingDirectory(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['path' => '/does/not/exist']));
        self::assertStringContainsString('does not exist', self::display($tester));
    }

    public function testRejectsAnUnknownFormat(): void
    {
        self::assertSame(Command::INVALID, $this->tester()->execute(['path' => '.', '--format' => 'xml']));
    }

    /**
     * The console wraps long lines (a long project path moves the breaks): compare without the line breaks.
     */
    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s*\n\s*(?:! )?/', ' ', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find('pii:scan'));
    }
}
