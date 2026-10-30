<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Application\ScanProject;
use App\Domain\Pii\PiiCandidate;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Deterministic part of the contract: every expected personal field of Clinique Bonsoir is a candidate,
 * with the expected category and sensitivity. Rejecting the traps is the agent's job (step 4).
 */
final class DemoSchemaCandidatesTest extends KernelTestCase
{
    private const string DEMO_DIR = __DIR__.'/../../demo/legacy-app';

    public function testEveryExpectedPersonalFieldIsACandidate(): void
    {
        $candidates = $this->candidates();

        foreach (self::contractSection('pii_fields') as $expected) {
            $field = $expected['field'];
            self::assertArrayHasKey($field, $candidates, \sprintf('%s is not detected.', $field));
            self::assertSame($expected['category'], $candidates[$field]->category->value, $field);
            self::assertSame($expected['sensitivity'], $candidates[$field]->sensitivity->value, $field);
        }
    }

    public function testNothingOutsideTheContractIsFlagged(): void
    {
        $known = array_column([...self::contractSection('pii_fields'), ...self::contractSection('not_pii')], 'field');

        self::assertSame([], array_values(array_diff(array_keys($this->candidates()), $known)));
    }

    public function testTrapsThatSurviveTheNameHeuristicAreLowConfidence(): void
    {
        $candidates = $this->candidates();

        foreach (self::contractSection('not_pii') as $trap) {
            if (isset($candidates[$trap['field']])) {
                self::assertLessThan(0.7, $candidates[$trap['field']]->confidence, $trap['field']);
            }
        }
    }

    /**
     * @return array<string, PiiCandidate>
     */
    private function candidates(): array
    {
        $detect = self::getContainer()->get(ScanProject::class);

        $indexed = [];
        foreach ($detect(self::DEMO_DIR)->candidates as $candidate) {
            $indexed[(string) $candidate->column->reference] = $candidate;
        }

        return $indexed;
    }

    /**
     * @return list<array{field: string, category: ?string, sensitivity: ?string}>
     */
    private static function contractSection(string $section): array
    {
        $contract = json_decode((string) file_get_contents(self::DEMO_DIR.'/expected-findings.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($contract);
        self::assertIsArray($contract[$section] ?? null);

        $entries = [];
        foreach ($contract[$section] as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['field'] ?? null);
            $entries[] = [
                'field' => $entry['field'],
                'category' => \is_string($entry['category'] ?? null) ? $entry['category'] : null,
                'sensitivity' => \is_string($entry['sensitivity'] ?? null) ? $entry['sensitivity'] : null,
            ];
        }

        return $entries;
    }
}
