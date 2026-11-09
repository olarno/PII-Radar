<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Application\ScanReport;
use App\Domain\Finding\Finding;
use App\Domain\Finding\Severity;
use App\Domain\Flow\FlowOperation;
use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Pii\DecisionSource;
use App\Domain\Pii\PiiCategory;
use App\Domain\Pii\Sensitivity;
use App\Domain\Schema\Column;
use App\Domain\Schema\FieldReference;
use App\Domain\Schema\Schema;
use App\Domain\Schema\Table;
use App\Domain\SourceLocation;
use App\Infrastructure\Report\MermaidFlowMap;
use PHPUnit\Framework\TestCase;

final class MermaidFlowMapTest extends TestCase
{
    public function testDrawsFormsTablesAndRecipientsWithTheRisksInRed(): void
    {
        $map = new MermaidFlowMap()->project(self::report());

        self::assertStringStartsWith("flowchart LR\n", $map);
        self::assertStringContainsString('"📝 signup.php"]:::source', $map);
        self::assertStringContainsString('"🗄️ clients"]:::store', $map);
        self::assertStringContainsString('"🌐 api.crm.test (outside Quebec)"]:::danger', $map);
        self::assertStringContainsString('"🌐 api.qc.test"]:::sink', $map, 'A recipient without PII-008 finding is not red.');
        self::assertStringContainsString('"⚠️ public/clients.csv (public)"]:::danger', $map);
        self::assertStringContainsString('-->|"collect"|', $map);
        self::assertStringNotContainsString('settings', $map, 'Tables without personal data flows are left out.');
    }

    public function testLabelsCannotBreakTheDiagram(): void
    {
        $report = self::report([new FlowPoint(FlowOperation::Log, [new FieldReference('clients', 'email')], new SourceLocation('app.php', 3), 'log "a|b"')]);

        self::assertStringContainsString('"📄 log #quot;a#124;b#quot;"]:::danger', new MermaidFlowMap()->project($report));
    }

    public function testAnEmptyScanStillGivesAValidDiagram(): void
    {
        self::assertSame("flowchart LR\n    empty[\"No personal data flow.\"]", new MermaidFlowMap()->project(self::report([], [])));
    }

    /**
     * @param ?list<FlowPoint> $flows
     * @param ?list<Finding>   $findings
     */
    private static function report(?array $flows = null, ?array $findings = null): ScanReport
    {
        $email = new Column(new FieldReference('clients', 'email'), 'VARCHAR(255)', false, new SourceLocation('schema.sql', 2));
        $template = new Column(new FieldReference('settings', 'email_template'), 'TEXT', false, new SourceLocation('schema.sql', 6));
        $field = static fn (Column $column, bool $personal): ClassifiedField => new ClassifiedField($column, $personal, PiiCategory::Contact, Sensitivity::Medium, 0.9, 'test', DecisionSource::Rules);
        $reference = new FieldReference('clients', 'email');

        return new ScanReport(
            new Schema([new Table('clients', [$email], new SourceLocation('schema.sql', 1)), new Table('settings', [$template], new SourceLocation('schema.sql', 5))]),
            [],
            [$field($email, true), $field($template, false)],
            $flows ?? [
                new FlowPoint(FlowOperation::Collect, [$reference], new SourceLocation('signup.php', 4)),
                new FlowPoint(FlowOperation::Send, [$reference], new SourceLocation('crm.php', 9), 'api.crm.test'),
                new FlowPoint(FlowOperation::Send, [$reference], new SourceLocation('sync.php', 2), 'api.qc.test'),
                new FlowPoint(FlowOperation::Read, [new FieldReference('settings', 'email_template')], new SourceLocation('mail.php', 3), 'settings'),
            ],
            $findings ?? [
                new Finding('PII-008', Severity::Medium, new SourceLocation('crm.php', 9), [$reference], 'Sent outside Quebec.', 'PIA.', 'api.crm.test', 'US'),
                new Finding('PII-007', Severity::Critical, new SourceLocation('public/clients.csv'), [$reference], 'Export public.', 'Supprimer.'),
            ],
            false,
        );
    }
}
