<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Flow\FlowPoint;

final readonly class LogAdvice
{
    /**
     * @param list<FlowPoint> $existingLogs where the code already logs the field
     */
    public function __construct(
        public FieldSheet $sheet,
        public LogVerdict $verdict,
        public string $reason,
        public string $alternative,
        public array $existingLogs,
    ) {
    }
}
