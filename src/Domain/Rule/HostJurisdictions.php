<?php

declare(strict_types=1);

namespace App\Domain\Rule;

/**
 * Where well-known API hosts process data (decision 5): a fixed table, never a guess.
 *
 * Matching is by domain suffix, so "api.twilio.com" matches "twilio.com".
 */
final class HostJurisdictions
{
    private const array HOSTS = [
        // Quebec
        'gouv.qc.ca' => Jurisdiction::Quebec,
        'desjardins.com' => Jurisdiction::Quebec,
        // Canada, outside Quebec
        'canadapost.ca' => Jurisdiction::CanadaOutsideQuebec,
        'canadapost-postescanada.ca' => Jurisdiction::CanadaOutsideQuebec,
        'shopify.com' => Jurisdiction::CanadaOutsideQuebec,
        // United States
        'twilio.com' => Jurisdiction::UnitedStates,
        'sendgrid.com' => Jurisdiction::UnitedStates,
        'sendgrid.net' => Jurisdiction::UnitedStates,
        'mailgun.net' => Jurisdiction::UnitedStates,
        'mailchimp.com' => Jurisdiction::UnitedStates,
        'mandrillapp.com' => Jurisdiction::UnitedStates,
        'postmarkapp.com' => Jurisdiction::UnitedStates,
        'stripe.com' => Jurisdiction::UnitedStates,
        'paypal.com' => Jurisdiction::UnitedStates,
        'squareup.com' => Jurisdiction::UnitedStates,
        'hubapi.com' => Jurisdiction::UnitedStates,
        'hubspot.com' => Jurisdiction::UnitedStates,
        'salesforce.com' => Jurisdiction::UnitedStates,
        'slack.com' => Jurisdiction::UnitedStates,
        'facebook.com' => Jurisdiction::UnitedStates,
        'googleapis.com' => Jurisdiction::UnitedStates,
        'openai.com' => Jurisdiction::UnitedStates,
        'anthropic.com' => Jurisdiction::UnitedStates,
        'segment.io' => Jurisdiction::UnitedStates,
        'intercom.io' => Jurisdiction::UnitedStates,
        'zendesk.com' => Jurisdiction::UnitedStates,
        // European Union
        'brevo.com' => Jurisdiction::EuropeanUnion,
        'sendinblue.com' => Jurisdiction::EuropeanUnion,
        'mailjet.com' => Jurisdiction::EuropeanUnion,
        'mollie.com' => Jurisdiction::EuropeanUnion,
    ];

    public function of(string $host): ?Jurisdiction
    {
        $host = strtolower(rtrim($host, '.'));
        foreach (self::HOSTS as $domain => $jurisdiction) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $jurisdiction;
            }
        }

        return null;
    }
}
