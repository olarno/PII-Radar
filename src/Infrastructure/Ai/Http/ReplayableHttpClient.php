<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Http;

use Symfony\AI\Platform\Test\Replay\BodyRedactor;
use Symfony\AI\Platform\Test\Replay\CassetteHttpClient;
use Symfony\AI\Platform\Test\Replay\HttpCassette;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Lets the model provider's traffic come from a recording instead of the network.
 *
 * The same agents, prompts and tools build the same requests; only the answers are replayed. When the recording
 * does not exist yet, the real client is called and its exchanges are recorded (credentials and PII redacted).
 */
final class ReplayableHttpClient implements HttpClientInterface
{
    private ?CassetteHttpClient $replay = null;

    public function __construct(
        private HttpClientInterface $client,
    ) {
    }

    public function replay(string $cassettePath): void
    {
        $this->replay = new CassetteHttpClient(new HttpCassette($cassettePath, [], new BodyRedactor()), $this->client);
    }

    public function isReplaying(): bool
    {
        return null !== $this->replay;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return ($this->replay ?? $this->client)->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return ($this->replay ?? $this->client)->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }
}
