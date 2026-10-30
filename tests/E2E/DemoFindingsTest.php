<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Application\ScanProject;
use App\Domain\Finding\Finding;

/**
 * The contract without the agent (pii:scan --no-ai): the heuristic alone finds every planted finding, but cannot tell
 * a trap from personal data. Extra findings are tolerated only when they come from trap fields.
 */
final class DemoFindingsTest extends DemoContractTestCase
{
    /** @var list<Finding>|null */
    private static ?array $findings = null;

    public function testExtraFindingsComeOnlyFromTrapFields(): void
    {
        $traps = array_map(static fn (array $trap): string => self::string($trap, 'field'), self::section('not_pii'));

        foreach (self::extraFindings() as $finding) {
            self::assertNotSame([], $finding->fields, \sprintf('Unexpected %s at %s.', $finding->rule, $finding->location));
            foreach ($finding->fields as $field) {
                self::assertContains((string) $field, $traps, \sprintf('Unexpected %s at %s on %s.', $finding->rule, $finding->location, $field));
            }
        }
    }

    protected static function findings(): array
    {
        if (null === self::$findings) {
            $scan = self::getContainer()->get(ScanProject::class);
            self::$findings = $scan(self::DEMO_DIR, arbitrate: false)->findings;
        }

        return self::$findings;
    }
}
