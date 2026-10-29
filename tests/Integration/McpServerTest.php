<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class McpServerTest extends WebTestCase
{
    public function testStdioServerCommandIsAvailable(): void
    {
        $application = new Application(self::bootKernel());

        self::assertTrue($application->has('mcp:server'));
    }

    public function testHttpServerServesTheRegisterResource(): void
    {
        $client = static::createClient();

        $initialize = $this->call($client, null, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1.0']],
        ]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('PII Radar', $initialize);

        $sessionId = $client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotNull($sessionId);

        $this->call($client, $sessionId, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $read = $this->call($client, $sessionId, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'pii://register']]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Registre Loi 25', $read);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function call(KernelBrowser $client, ?string $sessionId, array $message): string
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        if (null !== $sessionId) {
            $headers['HTTP_MCP_SESSION_ID'] = $sessionId;
        }

        $client->request('POST', '/mcp/pii_radar', server: $headers, content: json_encode($message, \JSON_THROW_ON_ERROR));

        return (string) $client->getResponse()->getContent();
    }
}
