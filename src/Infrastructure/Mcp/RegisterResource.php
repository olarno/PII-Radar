<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use Mcp\Capability\Attribute\McpResource;

/**
 * Exposes the current Loi 25 register draft to MCP clients.
 *
 * Until the scan pipeline exists (steps 3 to 5), it only explains how to get one.
 */
final class RegisterResource
{
    #[McpResource(
        uri: 'pii://register',
        name: 'register',
        title: 'Registre Loi 25 (brouillon)',
        description: 'Brouillon du registre des renseignements personnels de l\'application auditée.',
        mimeType: 'text/markdown',
    )]
    public function __invoke(): string
    {
        return "# Registre Loi 25 (brouillon)\n\nAucun scan disponible. Lancez `bin/console pii:scan <chemin>` pour générer le registre.\n";
    }
}
