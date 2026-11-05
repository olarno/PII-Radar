<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Infrastructure\Ai\Http\ReplayableHttpClient;

/**
 * Replays the recorded run of the arbiter agent on the demo, so the project can be tried without a Hugging Face token.
 */
final readonly class AgentReplay
{
    public function __construct(
        private ReplayableHttpClient $httpClient,
        private string $cassette,
        private string $demoProject,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the project is not the demo or the recording is missing
     */
    public function start(string $projectPath): void
    {
        if (realpath($projectPath) !== realpath($this->demoProject)) {
            throw new \InvalidArgumentException(\sprintf('Le rejeu ne fonctionne que sur l\'appli de démo (%s) : pour un autre projet, utilisez un token Hugging Face ou --no-ai.', $this->demoProject));
        }
        if (!is_file($this->cassette)) {
            throw new \InvalidArgumentException(\sprintf('Enregistrement introuvable : %s.', $this->cassette));
        }

        $this->httpClient->replay($this->cassette);
    }

    public function recording(): string
    {
        return $this->cassette;
    }
}
