<?php

declare(strict_types=1);

namespace App\Infrastructure\Report;

use App\Application\Port\RegisterRenderer;
use App\Application\Register\RegisterFormat;
use App\Domain\Register\Register;
use Twig\Environment;

/**
 * Renders the register with the Markdown templates of templates/register: one template per legal framework.
 */
final readonly class TwigRegisterRenderer implements RegisterRenderer
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    public function render(Register $register, RegisterFormat $format): string
    {
        return $this->twig->render(\sprintf('register/%s.md.twig', $format->value), ['register' => $register]);
    }
}
