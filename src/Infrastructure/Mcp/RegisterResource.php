<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Application\Port\RegisterRenderer;
use App\Application\Register\RegisterBuilder;
use App\Application\Register\RegisterFormat;
use Mcp\Capability\Attribute\McpResource;

/**
 * Serves the draft register of the audited project, built from its last scan.
 */
final readonly class RegisterResource
{
    public function __construct(
        private AuditedProject $project,
        private RegisterBuilder $builder,
        private RegisterRenderer $renderer,
    ) {
    }

    #[McpResource(
        uri: 'pii://register',
        name: 'register',
        title: 'Registre Loi 25 (brouillon)',
        description: 'Brouillon d\'inventaire des renseignements personnels au sens de la Loi 25 : renseignements, tiers, conservation, mesures de sécurité, points à confirmer.',
        mimeType: 'text/markdown',
    )]
    public function loi25(): string
    {
        return $this->render(RegisterFormat::Loi25);
    }

    #[McpResource(
        uri: 'pii://register/rgpd',
        name: 'register_gdpr',
        title: 'Registre RGPD (brouillon)',
        description: 'Brouillon de registre des activités de traitement (article 30 du RGPD).',
        mimeType: 'text/markdown',
    )]
    public function gdpr(): string
    {
        return $this->render(RegisterFormat::Gdpr);
    }

    private function render(RegisterFormat $format): string
    {
        try {
            $stored = $this->project->report();
        } catch (NoReport $exception) {
            return "# Registre (brouillon)\n\n".$exception->getMessage()."\n";
        }

        return $this->renderer->render($this->builder->build($stored), $format);
    }
}
