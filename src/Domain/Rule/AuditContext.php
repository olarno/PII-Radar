<?php

declare(strict_types=1);

namespace App\Domain\Rule;

use App\Domain\DataFile;
use App\Domain\Flow\FlowOperation;
use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Pii\Sensitivity;
use App\Domain\Schema\FieldReference;
use App\Domain\Schema\Schema;

/**
 * Everything a rule may look at: the schema, which of its fields hold personal data, the code flows, the exposed data files.
 */
final readonly class AuditContext
{
    /** @var array<string, ClassifiedField> */
    private array $pii;

    /**
     * @param list<ClassifiedField> $classifiedFields
     * @param list<FlowPoint>       $flows
     * @param list<DataFile>        $dataFiles
     */
    public function __construct(
        public Schema $schema,
        array $classifiedFields,
        public array $flows,
        public array $dataFiles = [],
    ) {
        $pii = [];
        foreach ($classifiedFields as $field) {
            if ($field->personal) {
                $pii[(string) $field->column->reference] = $field;
            }
        }
        $this->pii = $pii;
    }

    /**
     * @return list<ClassifiedField> the fields classified as personal data
     */
    public function personalData(): array
    {
        return array_values($this->pii);
    }

    public function personalDataFor(FieldReference $field): ?ClassifiedField
    {
        return $this->pii[(string) $field] ?? null;
    }

    /**
     * @param list<FieldReference> $fields
     *
     * @return list<FieldReference> the fields that hold personal data
     */
    public function onlyPersonalData(array $fields): array
    {
        return array_values(array_filter($fields, fn (FieldReference $field): bool => isset($this->pii[(string) $field])));
    }

    /**
     * @param list<FieldReference> $fields
     */
    public function highestSensitivity(array $fields): ?Sensitivity
    {
        $highest = null;
        foreach ($fields as $field) {
            $sensitivity = $this->personalDataFor($field)?->sensitivity;
            if (null !== $sensitivity && (null === $highest || self::rank($sensitivity) > self::rank($highest))) {
                $highest = $sensitivity;
            }
        }

        return $highest;
    }

    /**
     * @return list<FlowPoint>
     */
    public function flowsOf(FlowOperation ...$operations): array
    {
        return array_values(array_filter($this->flows, static fn (FlowPoint $flow): bool => \in_array($flow->operation, $operations, true)));
    }

    private static function rank(Sensitivity $sensitivity): int
    {
        return match ($sensitivity) {
            Sensitivity::Low => 1,
            Sensitivity::Medium => 2,
            Sensitivity::High => 3,
        };
    }
}
