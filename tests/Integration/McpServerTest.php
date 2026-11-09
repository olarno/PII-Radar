<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\ScanProject;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Talks to the MCP server over HTTP like Claude Code would, about the arbitrated scan of Clinique Bonsoir
 * (replayed cassette: no key, no network).
 */
final class McpServerTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?string $session = null;
    private int $id = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->call('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        $this->session = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $this->call('notifications/initialized');
    }

    public function testStdioServerCommandIsAvailable(): void
    {
        self::assertTrue(new Application(self::$kernel ?? self::bootKernel())->has('mcp:server'));
    }

    public function testEveryCapabilityIsAdvertised(): void
    {
        self::assertSame(['pii_can_i_log', 'pii_findings', 'pii_scan', 'pii_where_is'], $this->names('tools/list', 'tools', 'name'));
        self::assertSame(['pii://register', 'pii://register/gdpr'], $this->names('resources/list', 'resources', 'uri'));
        self::assertSame(['pii://field/{table}/{column}'], $this->names('resources/templates/list', 'resourceTemplates', 'uriTemplate'));
        self::assertSame(['pii-review'], $this->names('prompts/list', 'prompts', 'name'));
    }

    public function testCanILogTheHealthInsuranceNumber(): void
    {
        $this->scanTheDemo();
        $answer = $this->tool('pii_can_i_log', ['field' => 'patients.nam']);

        self::assertStringStartsWith('**No.** patients.nam is personal data (government identifier, high sensitivity)', $answer);
        self::assertStringContainsString('Log · public/patient.php:11 → error_log', $answer, 'The existing leak is pointed out.');
    }

    public function testATrapRejectedByTheAgentMayBeLogged(): void
    {
        $this->scanTheDemo();

        self::assertStringStartsWith('**Yes.** ville_reference.nom is not personal data', $this->tool('pii_can_i_log', ['field' => 'ville_reference.nom']));
    }

    public function testWhereDoesThePatientEmailLive(): void
    {
        $this->scanTheDemo();
        $answer = $this->tool('pii_where_is', ['data' => 'where does the patient email live?']);

        self::assertStringStartsWith('2 place(s)', $answer, 'patients.courriel and users.courriel, nothing else.');
        self::assertStringContainsString('### patients.courriel', $answer);
        self::assertStringContainsString('- Collect: public/inscription.php:8', $answer);
        self::assertStringContainsString('pii://field/patients/courriel', $answer);
        self::assertStringContainsString('  - PII-007 Export or dump in the webroot (critical) · public/export_patients.csv', $answer, 'Rules are named, not just numbered.');
        self::assertStringNotContainsString('email_template', $answer);
    }

    public function testFindingsAreFiltered(): void
    {
        $this->scanTheDemo();

        self::assertStringStartsWith('2 finding(s)', $this->tool('pii_findings', ['severity' => 'critical']));
    }

    public function testTheFieldSheetAndTheRegister(): void
    {
        $this->scanTheDemo();

        self::assertStringContainsString('# patients.nam', $this->read('pii://field/patients/nam'));
        self::assertStringContainsString('Government identifier', $this->read('pii://field/patients/nam'));
        self::assertStringContainsString('# Personal information inventory: legacy-app (draft)', $this->read('pii://register'));
        self::assertStringContainsString('| `rendezvous.motif` | High |', $this->read('pii://register'));
        self::assertStringNotContainsString('ville_reference', $this->read('pii://register'));
        self::assertStringContainsString('Special categories (art. 9)', $this->read('pii://register/gdpr'));
    }

    public function testTheReviewPromptCarriesTheInventoryAndTheDiff(): void
    {
        $this->scanTheDemo();
        $result = $this->call('prompts/get', ['name' => 'pii-review', 'arguments' => ['diff' => "+ error_log(\$patient['nam']);"]]);

        $text = (string) json_encode($result, \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('- patients.nam: government identifier, high sensitivity', $text);
        self::assertStringContainsString('- PII-002: Personal data written to a log', $text);
        self::assertStringContainsString("error_log(\$patient['nam'])", $text);
    }

    public function testWithoutAScanTheToolsSaySo(): void
    {
        new Filesystem()->remove(self::getContainer()->getParameter('kernel.cache_dir').'/pii-radar');
        $result = $this->call('tools/call', ['name' => 'pii_where_is', 'arguments' => ['data' => 'courriel']]);

        self::assertTrue($result['isError'] ?? false);
        self::assertStringContainsString('No scan', (string) json_encode($result, \JSON_UNESCAPED_UNICODE));
    }

    private function scanTheDemo(): void
    {
        self::getContainer()->get(ScanProject::class)(self::getContainer()->getParameter('kernel.project_dir').'/demo/legacy-app');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function tool(string $name, array $arguments): string
    {
        $result = $this->call('tools/call', ['name' => $name, 'arguments' => $arguments]);
        self::assertFalse($result['isError'] ?? false, (string) json_encode($result, \JSON_UNESCAPED_UNICODE));
        self::assertIsArray($result['content'] ?? null);
        self::assertIsArray($result['content'][0] ?? null);
        self::assertIsString($result['content'][0]['text'] ?? null);

        return $result['content'][0]['text'];
    }

    private function read(string $uri): string
    {
        $result = $this->call('resources/read', ['uri' => $uri]);
        self::assertIsArray($result['contents'] ?? null, (string) json_encode($result, \JSON_UNESCAPED_UNICODE));
        self::assertIsArray($result['contents'][0] ?? null);
        self::assertIsString($result['contents'][0]['text'] ?? null);

        return $result['contents'][0]['text'];
    }

    /**
     * @return list<string>
     */
    private function names(string $method, string $key, string $property): array
    {
        $items = $this->call($method)[$key] ?? null;
        self::assertIsArray($items);
        $names = array_map(static fn (mixed $item): string => \is_array($item) && \is_string($item[$property] ?? null) ? $item[$property] : '', $items);
        sort($names);

        return $names;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed> the JSON-RPC result
     */
    private function call(string $method, array $params = []): array
    {
        $message = str_starts_with($method, 'notifications/') ? ['jsonrpc' => '2.0', 'method' => $method] : ['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method, 'params' => (object) $params];
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        if (null !== $this->session) {
            $headers['HTTP_MCP_SESSION_ID'] = $this->session;
        }

        $this->client->request('POST', '/mcp/pii_radar', server: $headers, content: json_encode($message, \JSON_THROW_ON_ERROR));
        $body = (string) $this->client->getResponse()->getContent();
        if ('' === $body) {
            return [];
        }
        $response = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertArrayNotHasKey('error', $response, $body);

        return \is_array($response['result'] ?? null) ? $response['result'] : [];
    }
}
