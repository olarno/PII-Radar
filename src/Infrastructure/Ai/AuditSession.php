<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Domain\Flow\FlowPoint;
use App\Domain\Pii\PiiMasker;
use App\Domain\Schema\Schema;

/**
 * The audit the agent is currently working on, shared with its tools for the duration of one arbitration.
 */
final class AuditSession
{
    private ?string $projectPath = null;
    private ?Schema $schema = null;
    /** @var list<FlowPoint> */
    private array $flows = [];

    public function __construct(
        private readonly PiiMasker $masker,
    ) {
    }

    /**
     * @param list<FlowPoint> $flows
     */
    public function start(string $projectPath, Schema $schema, array $flows): void
    {
        $this->projectPath = rtrim($projectPath, '/');
        $this->schema = $schema;
        $this->flows = $flows;
    }

    public function end(): void
    {
        $this->projectPath = null;
        $this->schema = null;
        $this->flows = [];
    }

    public function projectPath(): string
    {
        return $this->projectPath ?? throw new \LogicException('No audit in progress.');
    }

    public function schema(): Schema
    {
        return $this->schema ?? throw new \LogicException('No audit in progress.');
    }

    /**
     * @return list<FlowPoint>
     */
    public function flows(): array
    {
        return $this->flows;
    }

    public function masker(): PiiMasker
    {
        return $this->masker;
    }
}
