<?php

declare(strict_types=1);

namespace App\Application\Query;

enum LogVerdict: string
{
    case Allowed = 'allowed';
    case Avoid = 'avoid';
    case Forbidden = 'forbidden';
}
