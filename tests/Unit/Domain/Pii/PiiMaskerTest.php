<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Pii;

use App\Domain\Pii\PiiMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PiiMaskerTest extends TestCase
{
    #[DataProvider('provideValues')]
    public function testMasksPersonalDataValues(string $text, string $expected): void
    {
        self::assertSame($expected, new PiiMasker()->mask($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideValues(): iterable
    {
        yield 'e-mail' => ["\$to = 'jeanne.tremblay@example.test';", "\$to = '[COURRIEL]';"];
        yield 'NAM' => ['// test avec TREJ 8501 1512', '// test avec [NAM]'];
        yield 'NAM compact' => ["'PELX92042136'", "'[NAM]'"];
        yield 'NAS' => ['NAS : 046 454 286', 'NAS : [NAS]'];
        yield 'phone' => ['Appeler le 514-803-4803 ou (450) 555-0199', 'Appeler le [TELEPHONE] ou [TELEPHONE]'];
        yield 'international phone' => ["'+15005550006'", "'[TELEPHONE]'"];
        yield 'postal code' => ['H2X 1Y4, Montréal', '[CODE_POSTAL], Montréal'];
        yield 'card' => ['carte 4111 1111 1111 1111', 'carte [CARTE]'];
        yield 'IP' => ['derniere_ip = 192.168.1.20', 'derniere_ip = [IP]'];
    }

    public function testLeavesCodeWithoutValuesUntouched(): void
    {
        $code = "mysqli_query(\$db, 'SELECT id, nom FROM patients WHERE id = ' . (int) \$_GET['id']);";

        self::assertSame($code, new PiiMasker()->mask($code));
    }
}
