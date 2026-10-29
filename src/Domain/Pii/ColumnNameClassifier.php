<?php

declare(strict_types=1);

namespace App\Domain\Pii;

use App\Domain\Schema\Column;

/**
 * Flags columns whose name suggests personal data, in French and English.
 *
 * Cheap and deterministic: it finds candidates, it does not decide. Ambiguous names
 * ("nom", "motif") get a lower confidence and are left to the agent to arbitrate.
 */
final class ColumnNameClassifier
{
    /** Words that describe a setting or a template rather than a person's data. */
    private const array QUALIFIERS = ['template', 'modele', 'gabarit', 'format', 'type', 'sujet', 'subject', 'valide', 'verified', 'verifie', 'actif', 'enabled', 'count', 'nombre', 'nb'];

    private const float QUALIFIER_DAMPING = 0.3;

    /** @var list<NameSignal> */
    private readonly array $signals;

    public function __construct()
    {
        $this->signals = self::defaultSignals();
    }

    public function classify(Column $column): ?PiiCandidate
    {
        $tokens = self::tokenize($column->name());

        foreach ($this->signals as $signal) {
            if (!$signal->matches($tokens)) {
                continue;
            }

            $reason = \sprintf('Le nom de colonne contient « %s ».', $signal->label());
            $confidence = $signal->confidence;

            $qualifiers = array_values(array_intersect($tokens, self::QUALIFIERS));
            if ([] !== $qualifiers) {
                $confidence = round($confidence * self::QUALIFIER_DAMPING, 2);
                $reason .= \sprintf(' Confiance réduite : « %s » évoque un réglage ou un gabarit plutôt qu\'une donnée.', $qualifiers[0]);
            }

            return new PiiCandidate($column, $signal->category, $signal->sensitivity, $confidence, $reason);
        }

        return null;
    }

    /**
     * Splits "dateNaissance", "date_naissance" or "Date-Naissance" into ["date", "naissance"], accents removed.
     *
     * @return list<string>
     */
    public static function tokenize(string $name): array
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        $spaced = (string) preg_replace('/(?<=[a-z])(?=[A-Z])|[^A-Za-z0-9]+/', ' ', false === $ascii ? $name : $ascii);

        return array_values(array_filter(explode(' ', strtolower($spaced)), static fn (string $token): bool => '' !== $token));
    }

    /**
     * Ordered from most to least specific: the first matching signal wins.
     *
     * @return list<NameSignal>
     */
    private static function defaultSignals(): array
    {
        $signals = [];
        $add = static function (PiiCategory $category, Sensitivity $sensitivity, float $confidence, string ...$names) use (&$signals): void {
            foreach ($names as $name) {
                $signals[] = new NameSignal(explode('_', $name), $category, $sensitivity, $confidence);
            }
        };

        // Credentials and technical identifiers come first: "adresse_ip" is an IP, not a postal address.
        $add(PiiCategory::Technical, Sensitivity::High, 0.95, 'mot_de_passe', 'password', 'passwd', 'pwd', 'mdp');
        $add(PiiCategory::Technical, Sensitivity::Low, 0.9, 'adresse_ip', 'ip_address', 'ip');
        $add(PiiCategory::Technical, Sensitivity::Low, 0.7, 'user_agent');

        $add(PiiCategory::GovernmentId, Sensitivity::High, 0.95, 'assurance_maladie', 'assurance_sociale', 'permis_conduire', 'driver_license', 'drivers_license');
        $add(PiiCategory::GovernmentId, Sensitivity::High, 0.9, 'nam', 'ramq', 'nas', 'ssn', 'passeport', 'passport');
        $add(PiiCategory::GovernmentId, Sensitivity::High, 0.8, 'sin');

        $add(PiiCategory::Health, Sensitivity::High, 0.85, 'diagnostic', 'diagnosis', 'symptome', 'symptomes', 'symptom', 'symptoms', 'allergie', 'allergies', 'allergy', 'medical', 'medicale', 'medicales', 'medicaux', 'medication', 'medicament', 'traitement', 'treatment', 'maladie', 'prescription', 'ordonnance');
        // "motif" is the reason for a visit: health data in a clinic, harmless elsewhere.
        $add(PiiCategory::Health, Sensitivity::High, 0.55, 'motif');

        $add(PiiCategory::Financial, Sensitivity::Low, 0.85, 'carte_4', '4_derniers', 'last4', 'last_4');
        $add(PiiCategory::Financial, Sensitivity::Medium, 0.85, 'titulaire', 'cardholder', 'card_holder');
        $add(PiiCategory::Financial, Sensitivity::High, 0.9, 'numero_carte', 'card_number', 'credit_card', 'iban', 'compte_bancaire', 'bank_account', 'account_number');
        $add(PiiCategory::Financial, Sensitivity::High, 0.6, 'carte', 'card');

        $add(PiiCategory::Identity, Sensitivity::Low, 0.8, 'nom_utilisateur', 'username', 'user_name', 'login');
        $add(PiiCategory::Identity, Sensitivity::Medium, 0.9, 'date_naissance', 'date_de_naissance', 'naissance', 'dob', 'birth_date', 'birthdate', 'birthday', 'date_of_birth');
        $add(PiiCategory::Identity, Sensitivity::Medium, 0.9, 'prenom', 'first_name', 'firstname', 'given_name', 'nom_famille', 'last_name', 'lastname', 'surname', 'family_name', 'nom_complet', 'full_name', 'fullname');
        $add(PiiCategory::Identity, Sensitivity::Medium, 0.8, 'sexe', 'genre', 'gender');
        // "nom" alone names a person as often as a city or a product.
        $add(PiiCategory::Identity, Sensitivity::Medium, 0.6, 'nom', 'name');

        $add(PiiCategory::Contact, Sensitivity::Medium, 0.9, 'courriel', 'email', 'e_mail', 'telephone', 'phone', 'cellulaire', 'cell', 'mobile', 'fax');
        $add(PiiCategory::Contact, Sensitivity::Medium, 0.8, 'tel');
        $add(PiiCategory::Contact, Sensitivity::Medium, 0.6, 'mail');

        $add(PiiCategory::Location, Sensitivity::Medium, 0.9, 'code_postal', 'postal_code', 'zip_code', 'zip', 'postcode');
        $add(PiiCategory::Location, Sensitivity::Medium, 0.85, 'adresse', 'address', 'rue', 'street', 'latitude', 'longitude', 'geolocalisation', 'gps');
        $add(PiiCategory::Location, Sensitivity::Low, 0.8, 'ville', 'city');

        return $signals;
    }
}
