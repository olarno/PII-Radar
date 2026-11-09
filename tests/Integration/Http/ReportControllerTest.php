<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Application\ScanProject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The web report of the arbitrated scan of Clinique Bonsoir (replayed cassette).
 */
final class ReportControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTheReportShowsFindingsPersonalDataAndTheFlowMap(): void
    {
        $this->scan();
        $crawler = $this->client->request('GET', '/report');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Personal data report');
        self::assertSelectorTextContains('h2', 'Flow map');
        self::assertCount(14, $crawler->filter('#findings tbody tr'));
        self::assertCount(16, $crawler->filter('#personal tbody tr'));
        self::assertStringContainsString('ville_reference.nom', $crawler->filter('#rejected')->text(), 'Rejected traps are listed apart, with the agent\'s reason.');

        $map = $crawler->filter('pre.mermaid')->text();
        self::assertStringStartsWith('flowchart LR', $map);
        self::assertStringContainsString('🌐 api.twilio.com (outside Quebec)"]:::danger', $map);
        self::assertStringContainsString('⚠️ public/export_patients.csv (public)"]:::danger', $map);
        self::assertStringNotContainsString('ville_reference', $map);
    }

    public function testAReplayedScanIsAnnouncedAsSuch(): void
    {
        self::getContainer()->get(ScanProject::class)(self::getContainer()->getParameter('kernel.project_dir').'/demo/legacy-app', replayed: true);
        $this->client->request('GET', '/report');

        self::assertSelectorTextContains('p.muted', 'answers replayed from a real recorded Hugging Face call');
    }

    public function testAFieldPageShowsItsJourneyAndWhetherItMayBeLogged(): void
    {
        $this->scan();
        $crawler = $this->client->request('GET', '/report/field/patients/nam');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'patients.nam');
        self::assertSame('No', $crawler->filter('.card strong')->last()->text(), 'Can be logged? No');
        self::assertStringContainsString('📄 error_log"]:::danger', $crawler->filter('pre.mermaid')->text());
        self::assertCount(5, $crawler->filter('#findings li'));
    }

    public function testAnUnknownFieldIsNotFound(): void
    {
        $this->scan();
        $this->client->request('GET', '/report/field/patients/nope');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheRegisterIsDownloadedAsMarkdown(): void
    {
        $this->scan();
        $this->client->request('GET', '/report/register-gdpr.md');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/markdown; charset=UTF-8');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="register-gdpr.md"');
        self::assertStringStartsWith('# Record of processing activities', (string) $this->client->getResponse()->getContent());
    }

    public function testWithoutAScanThePageSaysHowToRunOne(): void
    {
        new Filesystem()->remove(self::getContainer()->getParameter('kernel.cache_dir').'/pii-radar');
        $this->client->request('GET', '/report');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'No scan');
        self::assertSelectorTextContains('pre', 'pii:scan');
    }

    public function testTheHomePageLeadsToTheReport(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/report');
    }

    private function scan(): void
    {
        self::getContainer()->get(ScanProject::class)(self::getContainer()->getParameter('kernel.project_dir').'/demo/legacy-app');
    }
}
