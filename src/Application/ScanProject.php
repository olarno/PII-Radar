<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Port\CodeScanner;
use App\Application\Port\DataFileFinder;
use App\Application\Port\SchemaReader;
use App\Domain\Finding\Finding;
use App\Domain\Pii\ColumnNameClassifier;
use App\Domain\Pii\PiiCandidate;
use App\Domain\Rule\AuditContext;
use App\Domain\Rule\Rule;

/**
 * Scans an audited project: schema, personal data candidates, code flows, then the rules that turn them into findings.
 */
final readonly class ScanProject
{
    /**
     * @param iterable<Rule> $rules
     */
    public function __construct(
        private SchemaReader $schemaReader,
        private CodeScanner $codeScanner,
        private DataFileFinder $dataFileFinder,
        private ColumnNameClassifier $classifier,
        private iterable $rules,
    ) {
    }

    public function __invoke(string $projectPath): ScanReport
    {
        $schema = $this->schemaReader->read($projectPath);

        $candidates = array_values(array_filter(array_map(
            $this->classifier->classify(...),
            $schema->columns(),
        ), static fn (?PiiCandidate $candidate): bool => null !== $candidate));

        $flows = $this->codeScanner->scan($projectPath, $schema);
        $context = new AuditContext($schema, $candidates, $flows, $this->dataFileFinder->findExposed($projectPath));

        $findings = [];
        foreach ($this->rules as $rule) {
            array_push($findings, ...$rule->evaluate($context));
        }
        usort($findings, static fn (Finding $a, Finding $b): int => [$b->severity->weight(), $a->rule, $a->location->file, $a->location->line]
            <=> [$a->severity->weight(), $b->rule, $b->location->file, $b->location->line]);

        return new ScanReport($schema, $candidates, $flows, $findings);
    }
}
