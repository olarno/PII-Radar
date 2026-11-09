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
            throw new \InvalidArgumentException(\sprintf('Replay only works on the demo application (%s): for another project, use a Hugging Face token or --no-ai.', $this->demoProject));
        }
        if (!is_file($this->cassette)) {
            throw new \InvalidArgumentException(\sprintf('Recording not found: %s.', $this->cassette));
        }

        $this->httpClient->replay($this->cassette);
    }

    public function recording(): string
    {
        return $this->cassette;
    }
}
