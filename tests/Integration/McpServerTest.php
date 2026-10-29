<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class McpServerTest extends KernelTestCase
{
    public function testStdioServerCommandIsAvailable(): void
    {
        $application = new Application(self::bootKernel());

        self::assertTrue($application->has('mcp:server'));
    }
}
