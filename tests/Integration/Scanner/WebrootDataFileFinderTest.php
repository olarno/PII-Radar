<?php

declare(strict_types=1);

namespace App\Tests\Integration\Scanner;

use App\Domain\DataFile;
use App\Infrastructure\Scanner\WebrootDataFileFinder;
use PHPUnit\Framework\TestCase;

final class WebrootDataFileFinderTest extends TestCase
{
    public function testFindsDataFilesInServedDirectoriesOnly(): void
    {
        $files = new WebrootDataFileFinder()->findExposed(__DIR__.'/../../Fixtures/webroot');

        self::assertSame(
            ['public/assets/export.csv' => ['nom', 'courriel', 'total'], 'public/backup.sql' => []],
            array_combine(
                array_map(static fn (DataFile $file): string => $file->location->file, $files),
                array_map(static fn (DataFile $file): array => $file->columns, $files),
            ),
        );
    }

    public function testAProjectWithoutWebrootExposesNothing(): void
    {
        self::assertSame([], new WebrootDataFileFinder()->findExposed(__DIR__.'/../../Fixtures/code/shop'));
    }
}
