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
        title: 'Personal data review of a pull request',
        description: 'Reviews a diff against the personal data of the audited application (Quebec Law 25, GDPR) and the rules PII-001 to PII-008.',
    )]
    public function __invoke(string $diff): array
    {
        if ('' === trim($diff)) {
            throw new PromptGetException('Paste the diff to review in the "diff" argument.');
        }

        try {
            $fields = array_values(array_filter($this->project->report()->report->classifiedFields, static fn ($field): bool => $field->personal));
            $inventory = implode("\n", array_map(static fn ($field): string => \sprintf('- %s: %s, %s sensitivity', $field->column->reference, mb_strtolower($field->category->label()), mb_strtolower($field->sensitivity->label())), $fields));
        } catch (NoReport) {
            $inventory = '(no scan yet: call the pii_scan tool first)';
        }
        $rules = [];
        foreach ($this->rules as $rule) {
            $rules[$rule->id()] = \sprintf('- %s: %s', $rule->id(), $rule->title());
        }
        ksort($rules);

        return [[
            'role' => 'user',
            'content' => <<<PROMPT
                Review this diff for personal data (Quebec Law 25, GDPR).

                Personal data known in the application:
                {$inventory}

                Rules to check:
                {$this->lines($rules)}

                For each change that touches personal data: give the file and line, the rule concerned and the fix. Before accepting a new log, call the pii_can_i_log tool; to locate a piece of data, pii_where_is. End with a verdict: fix before merging, or acceptable.

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
