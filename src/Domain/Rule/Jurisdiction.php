<?php

declare(strict_types=1);

namespace App\Domain\Rule;

enum Jurisdiction: string
{
    case Quebec = 'CA-QC';
    case CanadaOutsideQuebec = 'CA';
    case UnitedStates = 'US';
    case EuropeanUnion = 'EU';

    public function label(): string
    {
        return match ($this) {
            self::Quebec => 'Quebec',
            self::CanadaOutsideQuebec => 'Canada, outside Quebec',
            self::UnitedStates => 'United States',
            self::EuropeanUnion => 'European Union',
        };
    }
}
