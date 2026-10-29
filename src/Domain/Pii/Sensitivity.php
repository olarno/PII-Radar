<?php

declare(strict_types=1);

namespace App\Domain\Pii;

enum Sensitivity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Faible',
            self::Medium => 'Moyenne',
            self::High => 'Élevée',
        };
    }
}
