<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * The agent's structured answer for one candidate column.
 */
final class FieldVerdict
{
    #[Schema(description: 'The candidate column, exactly as given, e.g. "patients.nom"')]
    public string $field = '';

    #[Schema(description: 'True when the column holds information about an identifiable person')]
    public bool $isPersonalData = false;

    #[Schema(enum: ['identity', 'contact', 'financial', 'health', 'government_id', 'location', 'technical'])]
    public string $category = 'identity';

    #[Schema(enum: ['low', 'medium', 'high'])]
    public string $sensitivity = 'low';

    #[Schema(description: 'How sure you are, from 0 to 1', minimum: 0, maximum: 1)]
    public float $confidence = 0.0;

    #[Schema(description: 'One sentence in French citing the evidence (table purpose, code usage)')]
    public string $justification = '';
}
