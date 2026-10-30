<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\DataFile;

interface DataFileFinder
{
    /**
     * Lists exports, dumps and backups that sit in a publicly served directory of the audited project.
     *
     * @return list<DataFile>
     */
    public function findExposed(string $projectPath): array;
}
