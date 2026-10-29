<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the demo contract itself: expected-findings.json must stay consistent
 * with the Clinique Bonsoir code it describes, or the end-to-end test means nothing.
 */
final class ExpectedFindingsContractTest extends TestCase
{
    private const string DEMO_DIR = __DIR__.'/../../demo/legacy-app';

    public function testEveryRuleIsPlantedAtLeastOnce(): void
    {
        $rules = array_unique(array_map(
            static fn (array $finding): string => self::string($finding, 'rule'),
            self::findings(),
        ));
        sort($rules);

        self::assertSame(
            ['PII-001', 'PII-002', 'PII-003', 'PII-004', 'PII-005', 'PII-006', 'PII-007', 'PII-008'],
            $rules,
        );
    }

    public function testFindingIdsAreUnique(): void
    {
        $ids = array_map(static fn (array $finding): string => self::string($finding, 'id'), self::findings());

        self::assertSame($ids, array_unique($ids));
    }

    /**
     * @param array<mixed> $finding
     */
    #[DataProvider('provideFindings')]
    public function testFindingPointsAtRealEvidence(array $finding): void
    {
        $location = $finding['location'] ?? null;
        self::assertIsArray($location);
        $file = self::DEMO_DIR.'/'.self::string($location, 'file');
        self::assertFileExists($file);

        $scales = self::contract()['scales'] ?? null;
        self::assertIsArray($scales);
        self::assertIsArray($scales['severity'] ?? null);
        self::assertContains($finding['severity'] ?? null, $scales['severity']);

        if (!isset($location['line'])) {
            return;
        }

        $lines = file($file, \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertIsInt($location['line']);
        self::assertArrayHasKey($location['line'] - 1, $lines, 'Line is out of the file.');
        self::assertStringContainsString(self::string($finding, 'evidence'), $lines[$location['line'] - 1]);
    }

    public function testEveryReferencedFieldExistsInTheSchema(): void
    {
        $columns = self::schemaColumns();
        $contract = self::contract();

        foreach (['pii_fields', 'not_pii'] as $section) {
            self::assertIsArray($contract[$section] ?? null);
            foreach ($contract[$section] as $entry) {
                self::assertIsArray($entry);
                self::assertContains(self::string($entry, 'field'), $columns, $section);
            }
        }

        $tables = array_unique(array_map(static fn (string $column): string => explode('.', $column)[0], $columns));
        foreach (self::findings() as $finding) {
            self::assertIsArray($finding['fields'] ?? null);
            foreach ($finding['fields'] as $field) {
                self::assertIsString($field);
                self::assertContains($field, str_contains($field, '.') ? $columns : $tables, self::string($finding, 'id'));
            }
        }
    }

    public function testTrapsAreNeverClassifiedAsPersonalData(): void
    {
        $contract = self::contract();
        self::assertIsArray($contract['pii_fields'] ?? null);
        self::assertIsArray($contract['not_pii'] ?? null);

        $pii = array_map(static fn (mixed $entry): string => self::string(self::array($entry), 'field'), $contract['pii_fields']);
        $traps = array_map(static fn (mixed $entry): string => self::string(self::array($entry), 'field'), $contract['not_pii']);

        self::assertSame([], array_intersect($pii, $traps));
        self::assertContains('parametres_courriel.email_template', $traps);
        self::assertContains('ville_reference.nom', $traps);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function provideFindings(): iterable
    {
        foreach (self::findings() as $finding) {
            yield self::string($finding, 'id') => [$finding];
        }
    }

    /**
     * @return array<mixed>
     */
    private static function contract(): array
    {
        $contract = json_decode((string) file_get_contents(self::DEMO_DIR.'/expected-findings.json'), true, flags: \JSON_THROW_ON_ERROR);

        return self::array($contract);
    }

    /**
     * @return list<array<mixed>>
     */
    private static function findings(): array
    {
        $findings = self::contract()['findings'] ?? null;
        self::assertIsArray($findings);

        return array_values(array_map(self::array(...), $findings));
    }

    /**
     * @return list<string> "table.column" for every column declared in the demo schema
     */
    private static function schemaColumns(): array
    {
        $schema = (string) file_get_contents(self::DEMO_DIR.'/db/01-schema.sql');
        preg_match_all('/CREATE TABLE (\w+) \((.*?)\n\)/s', $schema, $tables, \PREG_SET_ORDER);

        $columns = [];
        foreach ($tables as [, $table, $body]) {
            preg_match_all('/^\s+(?!FOREIGN|PRIMARY|UNIQUE)(\w+) [A-Z]/m', $body, $names);
            foreach ($names[1] as $name) {
                $columns[] = $table.'.'.$name;
            }
        }

        return $columns;
    }

    /**
     * @return array<mixed>
     */
    private static function array(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        self::assertIsString($data[$key] ?? null, \sprintf('Missing string "%s".', $key));

        return $data[$key];
    }
}
