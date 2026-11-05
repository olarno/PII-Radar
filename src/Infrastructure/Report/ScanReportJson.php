<?php

declare(strict_types=1);

namespace App\Infrastructure\Report;

use App\Application\AgentUsage;
use App\Application\Port\Arbitration;
use App\Application\ScanReport;
use App\Domain\Finding\Finding;
use App\Domain\Finding\Severity;
use App\Domain\Flow\FlowOperation;
use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Pii\DecisionSource;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Pii\PiiCategory;
use App\Domain\Pii\Sensitivity;
use App\Domain\Schema\Column;
use App\Domain\Schema\FieldReference;
use App\Domain\Schema\Schema;
use App\Domain\Schema\Table;
use App\Domain\SourceLocation;

/**
 * The JSON form of a scan report: what pii:scan --format=json prints and what the report store keeps.
 */
final class ScanReportJson
{
    public const int VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public function normalize(ScanReport $report): array
    {
        return [
            'version' => self::VERSION,
            'arbitrated' => $report->arbitrated,
            'replayed' => $report->replayed,
            'agent_usage' => null === $report->agentUsage ? null : [
                'calls' => $report->agentUsage->calls,
                'input_tokens' => $report->agentUsage->inputTokens,
                'output_tokens' => $report->agentUsage->outputTokens,
            ],
            'findings' => array_map(static fn (Finding $finding): array => [
                'rule' => $finding->rule,
                'severity' => $finding->severity->value,
                'location' => self::location($finding->location),
                'fields' => self::names($finding->fields),
                'summary' => $finding->summary,
                'remediation' => $finding->remediation,
                'recipient' => $finding->recipient,
                'jurisdiction' => $finding->jurisdiction,
            ], $report->findings),
            'classified_fields' => array_map(static fn (ClassifiedField $field): array => [
                'field' => (string) $field->column->reference,
                'personal_data' => $field->personal,
                'category' => $field->category->value,
                'sensitivity' => $field->sensitivity->value,
                'confidence' => $field->confidence,
                'decided_by' => $field->decidedBy->value,
                'justification' => $field->justification,
            ], $report->classifiedFields),
            'candidates' => array_map(static fn (PiiCandidate $candidate): array => [
                'field' => (string) $candidate->column->reference,
                'category' => $candidate->category->value,
                'sensitivity' => $candidate->sensitivity->value,
                'confidence' => $candidate->confidence,
                'reason' => $candidate->reason,
            ], $report->candidates),
            'flows' => array_map(static fn (FlowPoint $flow): array => [
                'operation' => $flow->operation->value,
                'fields' => self::names($flow->fields),
                'location' => self::location($flow->location),
                'target' => $flow->target,
                'concatenated_fields' => self::names($flow->concatenatedFields),
            ], $report->flows),
            'schema' => array_map(static fn (Table $table): array => [
                'name' => $table->name,
                'location' => self::location($table->location),
                'columns' => array_map(static fn (Column $column): array => [
                    'name' => $column->name(),
                    'type' => $column->type,
                    'nullable' => $column->nullable,
                    'location' => self::location($column->location),
                ], $table->columns),
            ], $report->schema->tables()),
            'agent' => null === $report->arbitration ? null : [
                'conclusions' => $report->arbitration->conclusions,
                'raw_verdicts' => $report->arbitration->rawVerdicts,
            ],
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidReport
     */
    public function denormalize(array $data): ScanReport
    {
        if (self::VERSION !== ($data['version'] ?? null)) {
            throw new InvalidReport('Unsupported report version: scan the project again.');
        }

        $tables = [];
        $columns = [];
        foreach (self::list($data, 'schema') as $tableData) {
            $name = self::string($tableData, 'name');
            $tableColumns = [];
            foreach (self::list($tableData, 'columns') as $columnData) {
                $column = new Column(new FieldReference($name, self::string($columnData, 'name')), self::string($columnData, 'type'), self::bool($columnData, 'nullable'), self::sourceLocation($columnData));
                $tableColumns[] = $column;
                $columns[(string) $column->reference] = $column;
            }
            $tables[] = new Table($name, $tableColumns, self::sourceLocation($tableData));
        }
        $column = static fn (string $reference): Column => $columns[$reference] ?? throw new InvalidReport(\sprintf('Unknown column "%s".', $reference));

        $usage = $data['agent_usage'] ?? null;
        $agent = $data['agent'] ?? null;

        return new ScanReport(
            new Schema($tables),
            array_map(static fn (array $candidate): PiiCandidate => new PiiCandidate(
                $column(self::string($candidate, 'field')),
                self::enum(PiiCategory::class, $candidate, 'category'),
                self::enum(Sensitivity::class, $candidate, 'sensitivity'),
                self::float($candidate, 'confidence'),
                self::string($candidate, 'reason'),
            ), self::list($data, 'candidates')),
            array_map(static fn (array $field): ClassifiedField => new ClassifiedField(
                $column(self::string($field, 'field')),
                self::bool($field, 'personal_data'),
                self::enum(PiiCategory::class, $field, 'category'),
                self::enum(Sensitivity::class, $field, 'sensitivity'),
                self::float($field, 'confidence'),
                self::string($field, 'justification'),
                self::enum(DecisionSource::class, $field, 'decided_by'),
            ), self::list($data, 'classified_fields')),
            array_map(static fn (array $flow): FlowPoint => new FlowPoint(
                self::enum(FlowOperation::class, $flow, 'operation'),
                self::references($flow, 'fields'),
                self::sourceLocation($flow),
                self::nullableString($flow, 'target'),
                self::references($flow, 'concatenated_fields'),
            ), self::list($data, 'flows')),
            array_map(static fn (array $finding): Finding => new Finding(
                self::string($finding, 'rule'),
                self::enum(Severity::class, $finding, 'severity'),
                self::sourceLocation($finding),
                self::references($finding, 'fields'),
                self::string($finding, 'summary'),
                self::string($finding, 'remediation'),
                self::nullableString($finding, 'recipient'),
                self::nullableString($finding, 'jurisdiction'),
            ), self::list($data, 'findings')),
            self::bool($data, 'arbitrated'),
            \is_array($usage) ? new AgentUsage((int) self::float($usage, 'calls'), self::nullableInt($usage, 'input_tokens'), self::nullableInt($usage, 'output_tokens')) : null,
            \is_array($agent) ? new Arbitration([], null, self::nullableString($agent, 'conclusions'), array_values(array_filter(self::list($agent, 'raw_verdicts', false), \is_string(...)))) : null,
            true === ($data['replayed'] ?? false),
        );
    }

    /**
     * @param list<FieldReference> $fields
     *
     * @return list<string>
     */
    private static function names(array $fields): array
    {
        return array_map(static fn (FieldReference $field): string => (string) $field, $fields);
    }

    /**
     * @return array{file: string, line: ?int}
     */
    private static function location(SourceLocation $location): array
    {
        return ['file' => $location->file, 'line' => $location->line];
    }

    /**
     * @param array<mixed> $data
     */
    private static function sourceLocation(array $data): SourceLocation
    {
        $location = $data['location'] ?? null;
        if (!\is_array($location)) {
            throw new InvalidReport('Missing location.');
        }

        return new SourceLocation(self::string($location, 'file'), self::nullableInt($location, 'line'));
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<FieldReference>
     */
    private static function references(array $data, string $key): array
    {
        return array_map(
            static fn (mixed $reference): FieldReference => FieldReference::fromString(\is_string($reference) ? $reference : throw new InvalidReport(\sprintf('Invalid field in "%s".', $key))),
            self::list($data, $key, false),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return ($arrays is true ? list<array<mixed>> : list<mixed>)
     */
    private static function list(array $data, string $key, bool $arrays = true): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw new InvalidReport(\sprintf('"%s" must be a list.', $key));
        }
        if ($arrays) {
            foreach ($value as $item) {
                if (!\is_array($item)) {
                    throw new InvalidReport(\sprintf('"%s" must contain objects.', $key));
                }
            }
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : throw new InvalidReport(\sprintf('"%s" must be a string.', $key));
    }

    /**
     * @param array<mixed> $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        return null === ($data[$key] ?? null) ? null : self::string($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    private static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return null === $value || \is_int($value) ? $value : throw new InvalidReport(\sprintf('"%s" must be an integer.', $key));
    }

    /**
     * @param array<mixed> $data
     */
    private static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : throw new InvalidReport(\sprintf('"%s" must be a number.', $key));
    }

    /**
     * @param array<mixed> $data
     */
    private static function bool(array $data, string $key): bool
    {
        return \is_bool($data[$key] ?? null) ? $data[$key] : throw new InvalidReport(\sprintf('"%s" must be a boolean.', $key));
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     * @param array<mixed>    $data
     *
     * @return T
     */
    private static function enum(string $enum, array $data, string $key): \BackedEnum
    {
        return $enum::tryFrom(self::string($data, $key)) ?? throw new InvalidReport(\sprintf('"%s" has an unknown value.', $key));
    }
}
