<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Rule;

use App\Domain\Rule\HostJurisdictions;
use App\Domain\Rule\Jurisdiction;
use PHPUnit\Framework\TestCase;

final class HostJurisdictionsTest extends TestCase
{
    public function testMatchesByDomainSuffix(): void
    {
        $jurisdictions = new HostJurisdictions();

        self::assertSame(Jurisdiction::UnitedStates, $jurisdictions->of('api.twilio.com'));
        self::assertSame(Jurisdiction::UnitedStates, $jurisdictions->of('TWILIO.COM.'));
        self::assertSame(Jurisdiction::Quebec, $jurisdictions->of('services.ramq.gouv.qc.ca'));
        self::assertSame(Jurisdiction::EuropeanUnion, $jurisdictions->of('api.brevo.com'));
        self::assertNull($jurisdictions->of('nottwilio.com'), 'A suffix only matches on a domain boundary.');
        self::assertNull($jurisdictions->of('api.example-crm.com'));
    }
}
