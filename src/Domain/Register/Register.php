<?php

declare(strict_types=1);

namespace App\Domain\Register;

use App\Domain\Finding\Finding;
use App\Domain\Pii\PiiCategory;
use App\Domain\Pii\Sensitivity;

/**
 * Draft register of the personal information of one application, built from a scan.
 *
 * It states what the code shows and marks everything else as to be confirmed by the person in charge:
 * it never guesses a purpose, a retention period or a legal basis.
 */
final readonly class Register
{
    /**
     * @param list<RegisterEntry>   $entries    personal fields, by table order
     * @param list<Recipient>       $recipients
     * @param list<Retention>       $retention  one per table holding personal data
     * @param list<SecurityMeasure> $measures   measures the code shows
     * @param list<Finding>         $gaps       findings, most severe first
     */
    public function __construct(
        public string $application,
        public \DateTimeImmutable $scannedAt,
        public bool $arbitrated,
        public array $entries,
        public array $recipients,
        public array $retention,
        public array $measures,
        public array $gaps,
    ) {
    }

    /**
     * @return array<string, list<RegisterEntry>> entries by category label, categories in a stable order
     */
    public function byCategory(): array
    {
        $grouped = [];
        foreach (PiiCategory::cases() as $category) {
            $entries = array_values(array_filter($this->entries, static fn (RegisterEntry $entry): bool => $entry->field->category === $category));
            if ([] !== $entries) {
                $grouped[$category->label()] = $entries;
            }
        }

        return $grouped;
    }

    /**
     * Health data and government identifiers: special categories under the GDPR (art. 9), and the most sensitive under Loi 25.
     *
     * @return list<RegisterEntry>
     */
    public function sensitiveEntries(): array
    {
        return array_values(array_filter($this->entries, static fn (RegisterEntry $entry): bool => Sensitivity::High === $entry->field->sensitivity
            && \in_array($entry->field->category, [PiiCategory::Health, PiiCategory::GovernmentId], true)));
    }

    /**
     * @return list<Recipient>
     */
    public function thirdParties(): array
    {
        return array_values(array_filter($this->recipients, static fn (Recipient $recipient): bool => !$recipient->dataSubject));
    }

    /**
     * @return list<string> tables that hold personal data: a hint at the categories of persons concerned
     */
    public function tables(): array
    {
        return array_map(static fn (Retention $retention): string => $retention->table, $this->retention);
    }
}
