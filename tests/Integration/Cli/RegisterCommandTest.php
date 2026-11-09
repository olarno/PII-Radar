<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cli;

use App\Application\ScanProject;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class RegisterCommandTest extends KernelTestCase
{
    private const string DEMO = __DIR__.'/../../../demo/legacy-app';

    public function testPrintsTheLaw25DraftOfTheLastScan(): void
    {
        $tester = $this->scannedTester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringStartsWith('# Personal information inventory: legacy-app (draft)', $tester->getDisplay());
    }

    public function testWritesTheGdprDraftToAFileUnderAChosenName(): void
    {
        $file = sys_get_temp_dir().'/pii-radar-register-'.bin2hex(random_bytes(4)).'.md';
        $tester = $this->scannedTester();
        $tester->execute(['path' => self::DEMO, '--format' => 'gdpr', '--output' => $file, '--name' => 'Clinique Bonsoir']);

        $tester->assertCommandIsSuccessful();
        self::assertStringStartsWith('# Record of processing activities: Clinique Bonsoir', (string) file_get_contents($file));
        new Filesystem()->remove($file);
    }

    public function testAsksForAScanFirst(): void
    {
        new Filesystem()->remove(self::getContainer()->getParameter('kernel.cache_dir').'/pii-radar');
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('pii:register'));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        // The console wraps long messages: a long project path must not split the words checked here.
        self::assertStringContainsString('first', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testRejectsAnUnknownFramework(): void
    {
        self::assertSame(Command::INVALID, $this->scannedTester()->execute(['--format' => 'hipaa']));
    }

    private function scannedTester(): CommandTester
    {
        $application = new Application(self::bootKernel());
        self::getContainer()->get(ScanProject::class)(self::DEMO, arbitrate: false);

        return new CommandTester($application->find('pii:register'));
    }
}
