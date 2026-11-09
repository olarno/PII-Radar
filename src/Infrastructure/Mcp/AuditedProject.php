<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Application\Port\ReportStore;
use App\Application\StoredReport;
use App\Infrastructure\Report\InvalidReport;

/**
 * The project the MCP server answers about (PII_RADAR_PROJECT), and its last scan.
 */
final readonly class AuditedProject
{
    public function __construct(
        private string $project,
        private string $projectDir,
        private ReportStore $reports,
    ) {
    }

    public function path(): string
    {
        return str_starts_with($this->project, '/') ? $this->project : $this->projectDir.'/'.$this->project;
    }

    /**
     * @throws NoReport when the project was never scanned, or its report cannot be read
     */
    public function report(): StoredReport
    {
        try {
            $report = $this->reports->load($this->path());
        } catch (InvalidReport $exception) {
            throw new NoReport($exception->getMessage(), previous: $exception);
        }

        return $report ?? throw new NoReport(\sprintf('No scan of %s yet: run "bin/console pii:scan %s" or the pii_scan tool.', $this->project, $this->project));
    }
}
