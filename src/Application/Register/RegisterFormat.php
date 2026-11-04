<?php

declare(strict_types=1);

namespace App\Application\Register;

enum RegisterFormat: string
{
    /** Quebec: Act respecting the protection of personal information in the private sector, as amended by Loi 25. */
    case Loi25 = 'loi25';
    /** GDPR, record of processing activities (art. 30). */
    case Gdpr = 'rgpd';
}
