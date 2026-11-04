<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Register\RegisterFormat;
use App\Domain\Register\Register;

interface RegisterRenderer
{
    /**
     * @return string the register as Markdown
     */
    public function render(Register $register, RegisterFormat $format): string;
}
