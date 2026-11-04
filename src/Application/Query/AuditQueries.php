<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Application\ScanReport;
use App\Domain\Finding\Finding;
use App\Domain\Finding\Severity;
use App\Domain\Flow\FlowOperation;
use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Pii\ColumnNameClassifier;
use App\Domain\Pii\Sensitivity;
use App\Domain\Schema\Column;

/**
 * Answers questions about the last scan: where a piece of personal data lives, whether a field may be logged, which findings remain.
 */
final class AuditQueries
{
    /** Words of a question that say nothing about the data. */
    private const array STOP_WORDS = ['le', 'la', 'les', 'l', 'un', 'une', 'des', 'du', 'de', 'd', 'ou', 'vit', 'est', 'sont', 'se', 'trouve', 'trouvent', 'stocke', 'stockee', 'dans', 'quel', 'quelle', 'quels', 'quelles', 'mon', 'ma', 'mes', 'the', 'of', 'where', 'is', 'are', 'does', 'live', 'stored', 'a', 'an', 'donnee', 'donnees', 'champ', 'colonne', 'data', 'field'];

    /** Groups of words that name the same kind of data. */
    private const array SYNONYMS = [
        ['courriel', 'email', 'mail', 'e', 'adresse_courriel'],
        ['telephone', 'tel', 'phone', 'cellulaire', 'mobile'],
        ['nom', 'name', 'prenom', 'firstname', 'lastname'],
        ['naissance', 'dob', 'birth', 'birthdate', 'anniversaire', 'age'],
        ['adresse', 'address', 'rue', 'street', 'ville', 'city', 'postal', 'localisation', 'location'],
        ['nam', 'ramq', 'assurance', 'maladie', 'nas', 'sin', 'gouvernemental', 'government'],
        ['mot', 'passe', 'password', 'mdp', 'identifiant', 'credential'],
        ['ip', 'technique', 'technical'],
        ['carte', 'card', 'paiement', 'payment', 'titulaire', 'financier', 'financial', 'bancaire'],
        ['sante', 'health', 'medical', 'medicale', 'motif', 'diagnostic', 'maladie'],
    ];

    /**
     * Finds a column by "table.column", or by its bare name when only one table has it.
     *
     * @return list<Column> every column the reference may designate
     */
    public function resolve(ScanReport $report, string $reference): array
    {
        $reference = strtolower(trim($reference, " \t`'\""));
        $exact = [];
        $byName = [];
        foreach ($report->schema->columns() as $column) {
            if ((string) $column->reference === $reference) {
                $exact[] = $column;
            } elseif ($column->name() === $reference) {
                $byName[] = $column;
            }
        }

        return [] !== $exact ? $exact : $byName;
    }

    public function sheet(ScanReport $report, Column $column): FieldSheet
    {
        $classification = null;
        foreach ($report->classifiedFields as $field) {
            if ($field->column->reference->equals($column->reference)) {
                $classification = $field;
            }
        }

        return new FieldSheet(
            $column,
            $classification,
            array_values(array_filter($report->flows, static fn (FlowPoint $flow): bool => $flow->involves($column->reference))),
            array_values(array_filter($report->findings, static function (Finding $finding) use ($column): bool {
                foreach ($finding->fields as $field) {
                    if ($field->equals($column->reference)) {
                        return true;
                    }
                }

                return false;
            })),
        );
    }

    /**
     * Personal data matching a question such as "où vit le courriel du client ?", best matches first.
     *
     * @return list<FieldSheet>
     */
    public function whereIs(ScanReport $report, string $question): array
    {
        $exact = array_values(array_filter($this->resolve($report, $question), fn (Column $column): bool => $this->sheet($report, $column)->isPersonal()));
        if ([] !== $exact) {
            return array_map(fn (Column $column): FieldSheet => $this->sheet($report, $column), $exact);
        }

        $wanted = self::expand(self::singular(array_values(array_diff(ColumnNameClassifier::tokenize($question), self::STOP_WORDS))));
        if ([] === $wanted) {
            return [];
        }

        // Words about the data itself qualify a field; the table name only breaks ties ("du patient"),
        // unless the question names nothing but the table ("les données des patients").
        $scored = [];
        foreach ($report->classifiedFields as $field) {
            if (!$field->personal) {
                continue;
            }
            $data = self::singular([...ColumnNameClassifier::tokenize($field->column->name()), ...ColumnNameClassifier::tokenize($field->category->value), ...ColumnNameClassifier::tokenize($field->category->label())]);
            $table = self::singular(ColumnNameClassifier::tokenize($field->column->reference->table));
            $scored[] = [\count(array_intersect($wanted, $data)), \count(array_intersect($wanted, $table)), $field];
        }
        $byData = array_filter($scored, static fn (array $match): bool => $match[0] > 0);
        $scored = array_values([] !== $byData ? $byData : array_filter($scored, static fn (array $match): bool => $match[1] > 0));
        usort($scored, static fn (array $a, array $b): int => [$b[0], $b[1], Sensitivity::High === $b[2]->sensitivity] <=> [$a[0], $a[1], Sensitivity::High === $a[2]->sensitivity]);

        return array_map(fn (array $match): FieldSheet => $this->sheet($report, $match[2]->column), $scored);
    }

    public function canLog(ScanReport $report, Column $column): LogAdvice
    {
        $sheet = $this->sheet($report, $column);
        $logs = array_values(array_filter($sheet->flows, static fn (FlowPoint $flow): bool => FlowOperation::Log === $flow->operation));
        $classification = $sheet->classification;

        if (!$classification instanceof ClassifiedField || !$classification->personal) {
            return new LogAdvice(
                $sheet,
                LogVerdict::Allowed,
                null === $classification
                    ? \sprintf('%s ne ressemble pas à une donnée personnelle.', $column->reference)
                    : \sprintf('%s n\'est pas une donnée personnelle : %s', $column->reference, $classification->justification),
                'Rien à masquer.',
                $logs,
            );
        }

        $kind = \sprintf('%s est une donnée personnelle (%s, sensibilité %s)', $column->reference, mb_strtolower($classification->category->label()), mb_strtolower($classification->sensitivity->label()));

        return Sensitivity::Low === $classification->sensitivity
            ? new LogAdvice($sheet, LogVerdict::Avoid, $kind.' : à éviter dans les journaux (règle PII-002).', 'Si le journal en a vraiment besoin, le masquer ou le pseudonymiser (par exemple une IP tronquée), et le purger avec le journal.', $logs)
            : new LogAdvice($sheet, LogVerdict::Forbidden, $kind.' : ne pas la journaliser (règle PII-002).', 'Journaliser un identifiant technique (par exemple l\'id de la ligne) à la place, ou masquer la valeur avec un processor Monolog.', $logs);
    }

    /**
     * @return list<Finding> most severe first
     */
    public function findings(ScanReport $report, ?Severity $minimum = null, ?string $file = null, ?string $rule = null): array
    {
        return array_values(array_filter($report->findings, static fn (Finding $finding): bool => (null === $minimum || $finding->severity->weight() >= $minimum->weight())
            && (null === $file || '' === $file || str_contains($finding->location->file, $file))
            && (null === $rule || '' === $rule || strtoupper($rule) === $finding->rule)));
    }

    /**
     * "patients" and "patient" are the same word for matching.
     *
     * @param list<string> $words
     *
     * @return list<string>
     */
    private static function singular(array $words): array
    {
        return array_map(static fn (string $word): string => \strlen($word) > 3 && str_ends_with($word, 's') ? substr($word, 0, -1) : $word, $words);
    }

    /**
     * @param list<string> $words
     *
     * @return list<string>
     */
    private static function expand(array $words): array
    {
        $expanded = $words;
        foreach (self::SYNONYMS as $group) {
            if ([] !== array_intersect($words, $group)) {
                array_push($expanded, ...$group);
            }
        }

        return array_values(array_unique($expanded));
    }
}
