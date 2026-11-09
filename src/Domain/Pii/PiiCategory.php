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
            self::Identity => 'Identity',
            self::Contact => 'Contact details',
            self::Financial => 'Financial',
            self::Health => 'Health',
            self::GovernmentId => 'Government identifier',
            self::Location => 'Location',
            self::Technical => 'Technical',
        };
    }
}
