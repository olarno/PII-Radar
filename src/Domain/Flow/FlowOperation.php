<?php

declare(strict_types=1);

namespace App\Domain\Flow;

enum FlowOperation: string
{
    /** Received from a form or a request ($_POST, $_GET). */
    case Collect = 'collect';
    case Read = 'read';
    case Store = 'store';
    case Delete = 'delete';
    case Log = 'log';
    /** Sent outside the application: HTTP API, e-mail. */
    case Send = 'send';
    case Hash = 'hash';

    public function label(): string
    {
        return match ($this) {
            self::Collect => 'Collect',
            self::Read => 'Read',
            self::Store => 'Store',
            self::Delete => 'Delete',
            self::Log => 'Log',
            self::Send => 'Send',
            self::Hash => 'Hash',
        };
    }
}
