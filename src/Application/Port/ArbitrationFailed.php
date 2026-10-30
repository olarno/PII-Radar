<?php

declare(strict_types=1);

namespace App\Application\Port;

final class ArbitrationFailed extends \RuntimeException
{
    /**
     * @param bool $authentication true when the provider rejected the credentials
     */
    public function __construct(string $message, public readonly bool $authentication = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
