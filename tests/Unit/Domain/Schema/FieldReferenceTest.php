<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Schema;

use App\Domain\Schema\FieldReference;
use PHPUnit\Framework\TestCase;

final class FieldReferenceTest extends TestCase
{
    public function testParsesTableDotColumn(): void
    {
        $reference = FieldReference::fromString('patients.nam');

        self::assertSame('patients', $reference->table);
        self::assertSame('nam', $reference->column);
        self::assertSame('patients.nam', (string) $reference);
        self::assertTrue($reference->equals(new FieldReference('patients', 'nam')));
        self::assertFalse($reference->equals(new FieldReference('users', 'nam')));
    }

    public function testRejectsAReferenceWithoutTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FieldReference::fromString('nam');
    }
}
