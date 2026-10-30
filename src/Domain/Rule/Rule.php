<?php

declare(strict_types=1);

namespace App\Domain\Rule;

use App\Domain\Finding\Finding;

interface Rule
{
    /** Stable identifier, e.g. "PII-001". */
    public function id(): string;

    public function title(): string;

    /**
     * @return list<Finding>
     */
    public function evaluate(AuditContext $context): array;
}
