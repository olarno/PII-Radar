<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Domain\Rule\Rule;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Exception\PromptGetException;

final readonly class ReviewPrompt
{
    /**
     * @param iterable<Rule> $rules
     */
    public function __construct(
        private AuditedProject $project,
        private iterable $rules,
    ) {
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    #[McpPrompt(
        name: 'pii-review',
        title: 'Revue de PR sous l\'angle des données personnelles',
        description: 'Revoit un diff au regard des données personnelles de l\'application auditée (Loi 25, RGPD) et des règles PII-001 à PII-008.',
    )]
    public function __invoke(string $diff): array
    {
        if ('' === trim($diff)) {
            throw new PromptGetException('Collez le diff à revoir dans l\'argument « diff ».');
        }

        try {
            $fields = array_values(array_filter($this->project->report()->report->classifiedFields, static fn ($field): bool => $field->personal));
            $inventory = implode("\n", array_map(static fn ($field): string => \sprintf('- %s : %s, sensibilité %s', $field->column->reference, mb_strtolower($field->category->label()), mb_strtolower($field->sensitivity->label())), $fields));
        } catch (NoReport) {
            $inventory = '(aucun scan : appelez d\'abord l\'outil pii_scan)';
        }
        $rules = [];
        foreach ($this->rules as $rule) {
            $rules[$rule->id()] = \sprintf('- %s : %s', $rule->id(), $rule->title());
        }
        ksort($rules);

        return [[
            'role' => 'user',
            'content' => <<<PROMPT
                Revois ce diff sous l'angle des données personnelles (Loi 25 au Québec, RGPD).

                Données personnelles connues de l'application :
                {$inventory}

                Règles à vérifier :
                {$this->lines($rules)}

                Pour chaque ajout qui touche une donnée personnelle : cite le fichier et la ligne, la règle concernée et le correctif. Avant de valider un nouveau log, appelle l'outil pii_can_i_log ; pour situer une donnée, pii_where_is. Termine par un verdict : à corriger avant fusion, ou acceptable.

                ```diff
                {$diff}
                ```
                PROMPT,
        ]];
    }

    /**
     * @param array<string, string> $lines
     */
    private function lines(array $lines): string
    {
        return implode("\n", $lines);
    }
}
