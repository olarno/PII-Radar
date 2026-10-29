<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Schema\Schema;

interface SchemaReader
{
    /**
     * Reads the tables declared in the audited project.
     *
     * @param string $projectPath root directory of the audited application
     */
    public function read(string $projectPath): Schema;
}
