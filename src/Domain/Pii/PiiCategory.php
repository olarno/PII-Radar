<?php

declare(strict_types=1);

namespace App\Domain\Pii;

enum PiiCategory: string
{
    case Identity = 'identity';
    case Contact = 'contact';
    case Financial = 'financial';
    case Health = 'health';
    case GovernmentId = 'government_id';
    case Location = 'location';
    case Technical = 'technical';

    public function label(): string
    {
        return match ($this) {
            self::Identity => 'Identité',
            self::Contact => 'Coordonnées',
            self::Financial => 'Financier',
            self::Health => 'Santé',
            self::GovernmentId => 'Identifiant gouvernemental',
            self::Location => 'Localisation',
            self::Technical => 'Technique',
        };
    }
}
