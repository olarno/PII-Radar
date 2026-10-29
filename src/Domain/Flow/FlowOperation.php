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
            self::Collect => 'Collecte',
            self::Read => 'Lecture',
            self::Store => 'Stockage',
            self::Delete => 'Suppression',
            self::Log => 'Journalisation',
            self::Send => 'Envoi',
            self::Hash => 'Hachage',
        };
    }
}
