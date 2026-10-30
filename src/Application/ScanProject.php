<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Port\ArbitrationFailed;
use App\Application\Port\CandidateArbiter;
use App\Application\Port\CodeScanner;
use App\Application\Port\DataFileFinder;
use App\Application\Port\SchemaReader;
use App\Domain\Finding\Finding;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Pii\ColumnNameClassifier;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Rule\AuditContext;
use App\Domain\Rule\Rule;

/**
 * Scans an audited project: schema and candidates, code flows, arbitration of the ambiguous candidates by the agent,
 * then the rules that turn it all into findings.
 */
final readonly class ScanProject
{
    /** From this confidence the name heuristic decides alone: the LLM only arbitrates what is ambiguous. */
    public const float CONFIDENT = 0.8;

    /**
     * @param iterable<Rule> $rules
     */
    public function __construct(
        private SchemaReader $schemaReader,
        private CodeScanner $codeScanner,
        private DataFileFinder $dataFileFinder,
        private ColumnNameClassifier $classifier,
        private CandidateArbiter $arbiter,
        private iterable $rules,
    ) {
    }

    /**
     * @param bool $arbitrate false to skip the agent: the heuristic then decides every candidate
     *
     * @throws ArbitrationFailed
     */
    public function __invoke(string $projectPath, bool $arbitrate = true): ScanReport
    {
        $schema = $this->schemaReader->read($projectPath);

        $candidates = array_values(array_filter(array_map(
            $this->classifier->classify(...),
            $schema->columns(),
        ), static fn (?PiiCandidate $candidate): bool => null !== $candidate));

        $flows = $this->codeScanner->scan($projectPath, $schema);

        $ambiguous = array_values(array_filter($candidates, static fn (PiiCandidate $candidate): bool => $candidate->confidence < self::CONFIDENT));
        $decisions = [];
        foreach ($candidates as $candidate) {
            $decisions[(string) $candidate->column->reference] = ClassifiedField::fromCandidate($candidate);
        }
        $arbitrated = $arbitrate && [] !== $ambiguous;
        if ($arbitrated) {
            foreach ($this->arbiter->arbitrate($projectPath, $schema, $flows, $ambiguous) as $decision) {
                $decisions[(string) $decision->column->reference] = $decision;
            }
        }
        $classified = array_values($decisions);

        $context = new AuditContext($schema, $classified, $flows, $this->dataFileFinder->findExposed($projectPath));
        $findings = [];
        foreach ($this->rules as $rule) {
            array_push($findings, ...$rule->evaluate($context));
        }
        usort($findings, static fn (Finding $a, Finding $b): int => [$b->severity->weight(), $a->rule, $a->location->file, $a->location->line]
            <=> [$a->severity->weight(), $b->rule, $b->location->file, $b->location->line]);

        return new ScanReport($schema, $candidates, $classified, $flows, $findings, $arbitrated);
    }
}
