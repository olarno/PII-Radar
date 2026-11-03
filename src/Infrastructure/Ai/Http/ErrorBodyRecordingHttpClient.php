<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Http;

use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Keeps the body of the last error response of the model provider.
 *
 * The OpenAI-compatible converter of Symfony AI only reads errors shaped as {"error": {"message": ...}}; when the
 * provider answers otherwise, the reason is lost and only "Bad Request" remains. This keeps it for the error message.
 */
final class ErrorBodyRecordingHttpClient implements HttpClientInterface
{
    use AsyncDecoratorTrait;

    private const int MAX_LENGTH = 2000;

    private ?string $lastError = null;

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->lastError = null;
        $body = '';

        return new AsyncResponse($this->client, $method, $url, $options, function (ChunkInterface $chunk, AsyncContext $context) use (&$body): \Generator {
            if (!$chunk->isFirst() && !$chunk->isLast() && $context->getStatusCode() >= 400) {
                $body .= $chunk->getContent();
            }
            if ($chunk->isLast() && $context->getStatusCode() >= 400) {
                $this->lastError = \sprintf('HTTP %d: %s', $context->getStatusCode(), mb_substr(trim($body), 0, self::MAX_LENGTH));
            }

            yield $chunk;
        });
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
