<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Port\SchemaReader;
use App\Domain\Pii\ColumnNameClassifier;
use App\Domain\Pii\PiiCandidate;

/**
 * Reads the audited schema and lists the columns that may hold personal data.
 */
final readonly class DetectPiiCandidates
{
    public function __construct(
        private SchemaReader $schemaReader,
        private ColumnNameClassifier $classifier,
    ) {
    }

    public function __invoke(string $projectPath): SchemaScanResult
    {
        $schema = $this->schemaReader->read($projectPath);

        $candidates = array_values(array_filter(array_map(
            $this->classifier->classify(...),
            $schema->columns(),
        ), static fn (?PiiCandidate $candidate): bool => null !== $candidate));

        return new SchemaScanResult($schema, $candidates);
    }
}
