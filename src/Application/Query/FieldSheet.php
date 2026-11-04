<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Finding\Finding;
use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\ClassifiedField;
use App\Domain\Schema\Column;

/**
 * Everything the last scan knows about one column: how it was classified, where the code uses it, what is wrong with it.
 */
final readonly class FieldSheet
{
    /**
     * @param ?ClassifiedField $classification null when the column did not even look like personal data
     * @param list<FlowPoint>  $flows
     * @param list<Finding>    $findings
     */
    public function __construct(
        public Column $column,
        public ?ClassifiedField $classification,
        public array $flows,
        public array $findings,
    ) {
    }

    public function isPersonal(): bool
    {
        return null !== $this->classification && $this->classification->personal;
    }
}
