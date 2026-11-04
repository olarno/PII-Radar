<?php

declare(strict_types=1);

namespace App\Tests\Integration\Report;

use App\Application\ScanProject;
use App\Infrastructure\Report\InvalidReport;
use App\Infrastructure\Report\JsonReportStore;
use App\Infrastructure\Report\ScanReportJson;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class JsonReportStoreTest extends KernelTestCase
{
    private const string DEMO = __DIR__.'/../../../demo/legacy-app';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/pii-radar-store-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testAReportSurvivesTheRoundTripUnchanged(): void
    {
        $report = self::getContainer()->get(ScanProject::class)(self::DEMO, arbitrate: false);
        $json = new ScanReportJson();
        $store = new JsonReportStore($this->directory, $json);

        $saved = $store->save(self::DEMO, $report);
        $loaded = $store->load(self::DEMO.'/');

        self::assertNotNull($loaded);
        self::assertSame($json->normalize($report), $json->normalize($loaded->report));
        self::assertSame((string) realpath(self::DEMO), $loaded->projectPath);
        self::assertSame($saved->scannedAt->format(\DATE_ATOM), $loaded->scannedAt->format(\DATE_ATOM));
        self::assertCount(1, glob($this->directory.'/legacy-app-*.json') ?: []);
    }

    public function testAProjectNeverScannedHasNoReport(): void
    {
        self::assertNull(new JsonReportStore($this->directory, new ScanReportJson())->load(self::DEMO));
    }

    public function testACorruptedReportAsksForANewScan(): void
    {
        $store = new JsonReportStore($this->directory, new ScanReportJson());
        $store->save(self::DEMO, self::getContainer()->get(ScanProject::class)(self::DEMO, arbitrate: false));
        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            file_put_contents($file, '{"project": "x", "scanned_at": "2026-11-03T10:00:00+00:00", "report": {"version": 0}}');
        }

        $this->expectException(InvalidReport::class);
        $this->expectExceptionMessage('scan the project again');
        $store->load(self::DEMO);
    }
}
