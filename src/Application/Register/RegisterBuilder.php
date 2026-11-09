<?php

declare(strict_types=1);

namespace App\Application\Register;

use App\Application\StoredReport;
use App\Domain\Flow\FlowOperation;
use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Register\Recipient;
use App\Domain\Register\Register;
use App\Domain\Register\RegisterEntry;
use App\Domain\Register\Retention;
use App\Domain\Register\SecurityMeasure;
use App\Domain\Rule\HostJurisdictions;
use App\Domain\Schema\FieldReference;
use App\Domain\SourceLocation;

/**
 * Builds the draft register from the last scan: only what the scan shows, nothing guessed.
 */
final readonly class RegisterBuilder
{
    private const array STRONG_HASHES = ['password_hash', 'crypt', 'bcrypt', 'argon2i', 'argon2id', 'sodium_crypto_pwhash_str'];

    public function __construct(
        private HostJurisdictions $jurisdictions,
    ) {
    }

    public function build(StoredReport $stored, ?string $application = null): Register
    {
        $report = $stored->report;
        $personal = [];
        foreach ($report->classifiedFields as $field) {
            if ($field->personal) {
                $personal[(string) $field->column->reference] = $field;
            }
        }
        $isPersonal = static fn (FieldReference $field): bool => isset($personal[(string) $field]);

        $entries = [];
        foreach ($report->schema->columns() as $column) {
            $field = $personal[(string) $column->reference] ?? null;
            if (!$field instanceof ClassifiedField) {
                continue;
            }
            $flows = array_values(array_filter($report->flows, static fn (FlowPoint $flow): bool => $flow->involves($column->reference)));
            $entries[] = new RegisterEntry(
                $field,
                self::locations($flows, FlowOperation::Collect),
                array_values(array_unique(array_map(self::recipientName(...), array_filter($flows, static fn (FlowPoint $flow): bool => FlowOperation::Send === $flow->operation && null !== $flow->target)))),
                self::locations($flows, FlowOperation::Log),
            );
        }

        $recipients = [];
        foreach ($report->flows as $flow) {
            $fields = array_values(array_filter($flow->fields, $isPersonal));
            if (FlowOperation::Send !== $flow->operation || null === $flow->target || [] === $fields) {
                continue;
            }
            $name = self::recipientName($flow);
            $previous = $recipients[$name] ?? null;
            $recipients[$name] = new Recipient(
                $name,
                str_starts_with($flow->target, 'mail:') ? null : $this->jurisdictions->of($flow->target),
                FlowPoint::unique([...$previous->fields ?? [], ...$fields]),
                [...$previous->evidence ?? [], $flow->location],
                self::toDataSubject($flow->target, $isPersonal),
            );
        }

        $deletions = [];
        foreach ($report->flows as $flow) {
            if (FlowOperation::Delete === $flow->operation && null !== $flow->target) {
                $deletions[$flow->target][] = $flow->location;
            }
        }
        $retention = [];
        foreach ($report->schema->tables() as $table) {
            $count = \count(array_filter($table->columns, static fn ($column): bool => $isPersonal($column->reference)));
            if ($count > 0) {
                $retention[] = new Retention($table->name, $table->location, $count, $deletions[$table->name] ?? []);
            }
        }

        return new Register(
            $application ?? basename(rtrim($stored->projectPath, '/')),
            $stored->scannedAt,
            $report->arbitrated,
            $entries,
            array_values($recipients),
            $retention,
            self::measures($report->flows, $isPersonal, $deletions),
            $report->findings,
            $report->replayed,
        );
    }

    /**
     * @param list<FlowPoint>                     $flows
     * @param \Closure(FieldReference): bool      $isPersonal
     * @param array<string, list<SourceLocation>> $deletions
     *
     * @return list<SecurityMeasure>
     */
    private static function measures(array $flows, \Closure $isPersonal, array $deletions): array
    {
        $measures = [];

        $parameterized = array_values(array_filter($flows, static fn (FlowPoint $flow): bool => \in_array($flow->operation, [FlowOperation::Store, FlowOperation::Read], true)
            && [] !== array_filter($flow->fields, $isPersonal)
            && [] === array_filter($flow->concatenatedFields, $isPersonal)));
        $writes = array_values(array_filter($parameterized, static fn (FlowPoint $flow): bool => FlowOperation::Store === $flow->operation));
        if ([] !== $writes) {
            $measures[] = new SecurityMeasure(
                'Personal information written without SQL concatenation (prepared statements)',
                array_map(static fn (FlowPoint $flow): SourceLocation => $flow->location, $writes),
            );
        }

        foreach ($flows as $flow) {
            if (FlowOperation::Hash === $flow->operation && \in_array(strtolower((string) $flow->target), self::STRONG_HASHES, true)) {
                $measures[] = new SecurityMeasure(\sprintf('Passwords hashed with %s', $flow->target), [$flow->location]);
            }
        }

        foreach ($deletions as $table => $locations) {
            $measures[] = new SecurityMeasure(\sprintf('Deletion of the data of the %s table', $table), $locations);
        }

        return $measures;
    }

    /**
     * @param list<FlowPoint> $flows
     *
     * @return list<SourceLocation>
     */
    private static function locations(array $flows, FlowOperation $operation): array
    {
        return array_values(array_map(
            static fn (FlowPoint $flow): SourceLocation => $flow->location,
            array_filter($flows, static fn (FlowPoint $flow): bool => $operation === $flow->operation),
        ));
    }

    private static function recipientName(FlowPoint $flow): string
    {
        $target = (string) $flow->target;

        return str_starts_with($target, 'mail:') ? 'E-mail ('.substr($target, 5).')' : $target;
    }

    /**
     * A message sent to an address stored as personal data goes back to the person it describes.
     *
     * @param \Closure(FieldReference): bool $isPersonal
     */
    private static function toDataSubject(string $target, \Closure $isPersonal): bool
    {
        if (!str_starts_with($target, 'mail:')) {
            return false;
        }
        foreach (explode(',', substr($target, 5)) as $address) {
            try {
                if ($isPersonal(FieldReference::fromString($address))) {
                    return true;
                }
            } catch (\InvalidArgumentException) {
                // A literal address (mail:admin@example.test) is not a stored field.
            }
        }

        return false;
    }
}
