<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Domain\Finding\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The end-to-end contract: scanning Clinique Bonsoir finds every finding of expected-findings.json, and nothing that
 * must not be flagged. Subclasses decide how the scan runs (with or without the agent) and how strict to be on extras.
 */
abstract class DemoContractTestCase extends KernelTestCase
{
    protected const string DEMO_DIR = __DIR__.'/../../demo/legacy-app';

    /**
     * @return list<Finding>
     */
    abstract protected static function findings(): array;

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('provideExpectedFindings')]
    public function testExpectedFindingIsFound(array $expected): void
    {
        $finding = self::matching($expected);
        self::assertNotNull($finding, \sprintf('%s (%s) is not found.', self::string($expected, 'id'), self::string($expected, 'rule')));

        self::assertSame(self::string($expected, 'severity'), $finding->severity->value, self::string($expected, 'id'));
        $actualFields = array_map('strval', $finding->fields);
        foreach (self::strings($expected['fields'] ?? []) as $field) {
            if (str_contains($field, '.')) {
                self::assertContains($field, $actualFields, self::string($expected, 'id'));
            } else {
                // Table-level finding (PII-006): every field reported belongs to that table.
                self::assertNotSame([], $actualFields);
                foreach ($actualFields as $actual) {
                    self::assertStringStartsWith($field.'.', $actual, self::string($expected, 'id'));
                }
            }
        }
        if (isset($expected['recipient'])) {
            self::assertSame($expected['recipient'], $finding->recipient);
        }
        if (isset($expected['jurisdiction'])) {
            self::assertSame($expected['jurisdiction'], $finding->jurisdiction);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideExpectedFindings(): iterable
    {
        foreach (self::section('findings') as $expected) {
            yield self::string($expected, 'id').' '.self::string($expected, 'rule') => [$expected];
        }
    }

    public function testNothingThatMustNotBeFlaggedIs(): void
    {
        foreach (self::section('must_not_flag') as $forbidden) {
            foreach (static::findings() as $finding) {
                if ($finding->rule !== self::string($forbidden, 'rule')) {
                    continue;
                }
                if (isset($forbidden['file'])) {
                    self::assertNotSame($forbidden['file'], $finding->location->file, self::string($forbidden, 'reason'));
                }
                foreach (self::strings($forbidden['fields'] ?? []) as $table) {
                    foreach ($finding->fields as $field) {
                        self::assertNotSame($table, $field->table, self::string($forbidden, 'reason'));
                    }
                }
            }
        }
    }

    /**
     * @return list<Finding> the findings the contract does not expect
     */
    protected static function extraFindings(): array
    {
        return array_values(array_filter(static::findings(), static fn (Finding $finding): bool => null === self::expectedFor($finding)));
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function matching(array $expected): ?Finding
    {
        foreach (static::findings() as $finding) {
            if (self::sameIdentity($expected, $finding)) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function expectedFor(Finding $finding): ?array
    {
        foreach (self::section('findings') as $expected) {
            if (self::sameIdentity($expected, $finding)) {
                return $expected;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function sameIdentity(array $expected, Finding $finding): bool
    {
        $location = $expected['location'] ?? null;
        self::assertIsArray($location);

        return $finding->rule === $expected['rule']
            && $finding->location->file === $location['file']
            && $finding->location->line === ($location['line'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function section(string $name): array
    {
        $contract = json_decode((string) file_get_contents(self::DEMO_DIR.'/expected-findings.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($contract);
        self::assertIsArray($contract[$name] ?? null);

        $entries = [];
        foreach ($contract[$name] as $entry) {
            self::assertIsArray($entry);
            $entries[] = array_filter($entry, is_string(...), \ARRAY_FILTER_USE_KEY);
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected static function string(array $data, string $key): string
    {
        self::assertIsString($data[$key] ?? null);

        return $data[$key];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        self::assertIsArray($values);

        return array_values(array_filter($values, is_string(...)));
    }
}
