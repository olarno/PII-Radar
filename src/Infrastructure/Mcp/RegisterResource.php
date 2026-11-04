<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Domain\Pii\ClassifiedField;
use Mcp\Capability\Attribute\McpResource;

/**
 * Exposes the personal data inventory of the last scan. The full Loi 25 register draft replaces it in step 4b.
 */
final readonly class RegisterResource
{
    public function __construct(
        private AuditedProject $project,
        private MarkdownFormatter $format,
    ) {
    }

    #[McpResource(
        uri: 'pii://register',
        name: 'register',
        title: 'Registre Loi 25 (brouillon)',
        description: 'Inventaire des renseignements personnels de l\'application auditée, par catégorie.',
        mimeType: 'text/markdown',
    )]
    public function __invoke(): string
    {
        try {
            $stored = $this->project->report();
        } catch (NoReport $exception) {
            return "# Registre Loi 25 (brouillon)\n\n".$exception->getMessage()."\n";
        }

        $byCategory = [];
        foreach ($stored->report->classifiedFields as $field) {
            if ($field->personal) {
                $byCategory[$field->category->label()][] = $field;
            }
        }
        ksort($byCategory);

        $sections = [];
        foreach ($byCategory as $category => $fields) {
            $sections[] = "## {$category}\n\n".implode("\n", array_map(
                static fn (ClassifiedField $field): string => \sprintf('- `%s` · sensibilité %s · %s', $field->column->reference, mb_strtolower($field->sensitivity->label()), $field->column->location),
                $fields,
            ));
        }

        return \sprintf(
            "# Registre Loi 25 (brouillon)\n\n> Brouillon d'inventaire pour aider la démarche, pas un avis juridique. Finalités, durées de conservation et responsables sont à confirmer.\n\n%s\n\n%s\n",
            implode("\n\n", $sections),
            $this->format->provenance($stored),
        );
    }
}
