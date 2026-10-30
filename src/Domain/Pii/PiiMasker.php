<?php

declare(strict_types=1);

namespace App\Domain\Pii;

/**
 * Replaces personal data values in a text by typed placeholders, so that a code excerpt can reach the LLM (decision 6).
 *
 * Order matters: the most specific formats go first (a NAM contains digits that look like a phone number).
 */
final class PiiMasker
{
    private const array PATTERNS = [
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[COURRIEL]',
        '/\b[A-Z]{4}\s?\d{4}\s?\d{4}\b/' => '[NAM]',
        '/\b(?:\d[ -]?){13,19}\b/' => '[CARTE]',
        '/\b\d{3}[ -]\d{3}[ -]\d{3}\b/' => '[NAS]',
        '/(?<![\d+])(?:\+?1[ .-]?)?\(?\d{3}\)?[ .-]?\d{3}[ .-]?\d{4}(?!\d)/' => '[TELEPHONE]',
        '/\b[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z] ?\d[ABCEGHJ-NPRSTV-Z]\d\b/i' => '[CODE_POSTAL]',
        '/\b(?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3}\b/' => '[IP]',
    ];

    public function mask(string $text): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }
}
