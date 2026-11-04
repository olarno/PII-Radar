<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\ScanReport;
use App\Application\StoredReport;

interface ReportStore
{
    public function save(string $projectPath, ScanReport $report): StoredReport;

    /**
     * @return ?StoredReport the last scan of the project, or null when it was never scanned
     */
    public function load(string $projectPath): ?StoredReport;
}
