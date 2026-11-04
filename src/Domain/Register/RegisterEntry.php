<?php

declare(strict_types=1);

namespace App\Domain\Register;

use App\Domain\Pii\ClassifiedField;
use App\Domain\SourceLocation;

/**
 * One personal field of the inventory: what it is, where it is collected, who receives it.
 */
final readonly class RegisterEntry
{
    /**
     * @param list<SourceLocation> $collectedIn forms or requests that receive it
     * @param list<string>         $sharedWith  recipients outside the application
     * @param list<SourceLocation> $loggedIn    logs that write it
     */
    public function __construct(
        public ClassifiedField $field,
        public array $collectedIn,
        public array $sharedWith,
        public array $loggedIn,
    ) {
    }
}
