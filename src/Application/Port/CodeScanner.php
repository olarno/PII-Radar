<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Flow\FlowPoint;
use App\Domain\Schema\Schema;

interface CodeScanner
{
    /**
     * Finds where the audited code collects, stores, logs, sends or hashes the fields of the schema.
     *
     * @return list<FlowPoint>
     */
    public function scan(string $projectPath, Schema $schema): array;
}
