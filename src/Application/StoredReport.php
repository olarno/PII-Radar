<?php

declare(strict_types=1);

namespace App\Application;

/**
 * The last scan of a project, as kept between runs for the MCP server, the register and the web report.
 */
final readonly class StoredReport
{
    public function __construct(
        public ScanReport $report,
        public string $projectPath,
        public \DateTimeImmutable $scannedAt,
    ) {
    }
}
