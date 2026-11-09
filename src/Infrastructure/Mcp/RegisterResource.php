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
        title: 'Law 25 register (draft)',
        description: 'Draft inventory of personal information under Quebec Law 25: information held, third parties, retention, security measures, points to confirm.',
        mimeType: 'text/markdown',
    )]
    public function law25(): string
    {
        return $this->render(RegisterFormat::Law25);
    }

    #[McpResource(
        uri: 'pii://register/gdpr',
        name: 'register_gdpr',
        title: 'GDPR register (draft)',
        description: 'Draft record of processing activities (GDPR article 30).',
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
            return "# Register (draft)\n\n".$exception->getMessage()."\n";
        }

        return $this->renderer->render($this->builder->build($stored), $format);
    }
}
