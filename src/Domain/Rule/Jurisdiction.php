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
            self::Quebec => 'Québec',
            self::CanadaOutsideQuebec => 'Canada, hors Québec',
            self::UnitedStates => 'États-Unis',
            self::EuropeanUnion => 'Union européenne',
        };
    }
}
