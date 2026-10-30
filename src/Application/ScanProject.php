<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Port\CodeScanner;
use App\Application\Port\SchemaReader;
use App\Domain\Pii\ColumnNameClassifier;
use App\Domain\Pii\PiiCandidate;

/**
 * Scans an audited project: its schema, the columns that may hold personal data, and the code flows that touch them.
 */
final readonly class ScanProject
{
    public function __construct(
        private SchemaReader $schemaReader,
        private CodeScanner $codeScanner,
        private ColumnNameClassifier $classifier,
    ) {
    }

    public function __invoke(string $projectPath): ScanReport
    {
        $schema = $this->schemaReader->read($projectPath);

        $candidates = array_values(array_filter(array_map(
            $this->classifier->classify(...),
            $schema->columns(),
        ), static fn (?PiiCandidate $candidate): bool => null !== $candidate));

        return new ScanReport($schema, $candidates, $this->codeScanner->scan($projectPath, $schema));
    }
}
