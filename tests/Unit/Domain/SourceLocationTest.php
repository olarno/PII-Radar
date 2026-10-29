<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\SourceLocation;
use PHPUnit\Framework\TestCase;

final class SourceLocationTest extends TestCase
{
    public function testPrintsFileAndLine(): void
    {
        self::assertSame('db/schema.sql:11', (string) new SourceLocation('db/schema.sql', 11));
        self::assertSame('public/export.csv', (string) new SourceLocation('public/export.csv'));
    }

    public function testRejectsAnEmptyFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SourceLocation('');
    }

    public function testRejectsLineZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SourceLocation('db/schema.sql', 0);
    }
}
