<?php

declare(strict_types=1);

/*
 * Generates the Clinique Bonsoir demo data: db/02-seed.sql and public/export_patients.csv.
 *
 * Every person is fake (Faker, fr_CA locale, fixed seed): no real patient, ever.
 * Run from the repository root: php demo/legacy-app/bin/generate-seed.php
 */

require dirname(__DIR__, 3).'/vendor/autoload.php';

const SEED = 20141029;
const PATIENT_COUNT = 40;
const APPOINTMENT_COUNT = 80;
const PAYMENT_COUNT = 30;

$faker = Faker\Factory::create('fr_CA');
$faker->seed(SEED);

$quote = static fn (string $value): string => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";

/** Quebec health insurance number: 3 letters of last name, 1 of first name, YYMMDD (+50 for women), 2 digits. */
$nam = static function (string $firstName, string $lastName, DateTimeInterface $birthDate, bool $female) use ($faker): string {
    $letters = static function (string $text, int $length): string {
        $ascii = strtoupper((string) iconv('UTF-8', 'ASCII//TRANSLIT', $text));

        return str_pad(substr((string) preg_replace('/[^A-Z]/', '', $ascii), 0, $length), $length, 'X');
    };
    $month = (int) $birthDate->format('m') + ($female ? 50 : 0);

    return $letters($lastName, 3).$letters($firstName, 1).$birthDate->format('y').sprintf('%02d', $month).$birthDate->format('d').$faker->numerify('##');
};

$sql = ["-- Clinique Bonsoir - donnees de demonstration generees par bin/generate-seed.php", '-- Toutes les personnes sont fictives (Faker fr_CA, graine '.SEED.').', '', 'SET NAMES utf8mb4;', ''];

// Staff accounts. Passwords are hashed with MD5, as the original developer did in 2014.
$users = [
    ['admin', 'admin@clinique-bonsoir.test', 'admin2014', 'admin'],
    ['dre.tremblay', 'dre.tremblay@clinique-bonsoir.test', 'soleil123', 'medecin'],
    ['dr.gagnon', 'dr.gagnon@clinique-bonsoir.test', 'hockey99', 'medecin'],
    ['reception', 'reception@clinique-bonsoir.test', 'bonsoir', 'reception'],
];
$rows = [];
foreach ($users as [$login, $email, $password, $role]) {
    $rows[] = sprintf('(%s, %s, %s, %s, %s)', $quote($login), $quote($email), $quote(md5($password)), $quote($role), $quote($faker->ipv4()));
}
$sql[] = "INSERT INTO users (nom_utilisateur, courriel, mot_de_passe, role, derniere_ip) VALUES\n".implode(",\n", $rows).';';
$sql[] = '';

$patients = [];
$rows = [];
for ($i = 1; $i <= PATIENT_COUNT; ++$i) {
    $female = $faker->boolean();
    $firstName = $female ? $faker->firstNameFemale() : $faker->firstNameMale();
    $lastName = $faker->lastName();
    $birthDate = $faker->dateTimeBetween('-90 years', '-1 year');
    $patient = [
        'prenom' => $firstName,
        'nom' => $lastName,
        'date_naissance' => $birthDate->format('Y-m-d'),
        'nam' => $nam($firstName, $lastName, $birthDate, $female),
        'courriel' => $faker->safeEmail(),
        'telephone' => $faker->numerify('514-###-####'),
        'adresse' => $faker->streetAddress(),
        'ville' => $faker->randomElement(['Montréal', 'Laval', 'Longueuil', 'Brossard', 'Terrebonne']),
        'code_postal' => strtoupper($faker->bothify('H#? #?#')),
    ];
    $patients[] = $patient;
    $rows[] = '('.implode(', ', array_map($quote, $patient)).')';
}
$sql[] = "INSERT INTO patients (prenom, nom, date_naissance, nam, courriel, telephone, adresse, ville, code_postal) VALUES\n".implode(",\n", $rows).';';
$sql[] = '';

$reasons = [
    'Suivi diabète de type 2, ajuster la metformine',
    'Douleur thoracique depuis 3 jours',
    'Renouvellement antidépresseurs (sertraline 50 mg)',
    'Test de grossesse positif, premier suivi',
    'Dépistage ITS demandé par la patiente',
    'Hypertension, tension à 160/100 au dernier rendez-vous',
    'Anxiété et insomnie, arrêt de travail à discuter',
    'Suivi post-opératoire genou droit',
    'Toux persistante, fumeur 20 cigarettes par jour',
    'Résultats de biopsie à annoncer',
    'Vaccin grippe saisonnière',
    'Examen annuel',
];
$rows = [];
for ($i = 1; $i <= APPOINTMENT_COUNT; ++$i) {
    $rows[] = sprintf(
        '(%d, %d, %s, %s, %s, %d)',
        $faker->numberBetween(1, PATIENT_COUNT),
        $faker->randomElement([2, 3]),
        $quote($faker->dateTimeBetween('-2 years', '+1 month')->format('Y-m-d H:00:00')),
        $quote($faker->randomElement($reasons)),
        $quote($faker->randomElement(['prevu', 'termine', 'annule'])),
        $faker->boolean() ? 1 : 0,
    );
}
$sql[] = "INSERT INTO rendezvous (patient_id, medecin_id, date_heure, motif, statut, rappel_envoye) VALUES\n".implode(",\n", $rows).';';
$sql[] = '';

$rows = [];
for ($i = 1; $i <= PAYMENT_COUNT; ++$i) {
    $patientId = $faker->numberBetween(1, PATIENT_COUNT);
    $patient = $patients[$patientId - 1];
    $rows[] = sprintf(
        '(%d, %s, %s, %s)',
        $patientId,
        number_format($faker->randomFloat(2, 25, 250), 2, '.', ''),
        $quote($faker->numerify('####')),
        $quote(strtoupper($patient['prenom'].' '.$patient['nom'])),
    );
}
$sql[] = "INSERT INTO paiements (patient_id, montant, carte_4_derniers, titulaire_carte) VALUES\n".implode(",\n", $rows).';';
$sql[] = '';

// Not personal data: message templates and a reference list of cities (false-positive traps).
$sql[] = "INSERT INTO parametres_courriel (code, sujet, email_template) VALUES\n"
    ."('confirmation_rdv', 'Confirmation de votre rendez-vous', 'Bonjour {prenom},\\n\\nVotre rendez-vous est confirmé le {date}.\\n\\nClinique Bonsoir'),\n"
    ."('annulation_rdv', 'Annulation de votre rendez-vous', 'Bonjour {prenom},\\n\\nVotre rendez-vous du {date} est annulé.\\n\\nClinique Bonsoir');";
$sql[] = '';

$cities = [
    ['Montréal', 'Montréal', '06'], ['Laval', 'Laval', '13'], ['Longueuil', 'Montérégie', '16'],
    ['Brossard', 'Montérégie', '16'], ['Terrebonne', 'Lanaudière', '14'], ['Québec', 'Capitale-Nationale', '03'],
    ['Gatineau', 'Outaouais', '07'], ['Sherbrooke', 'Estrie', '05'], ['Trois-Rivières', 'Mauricie', '04'],
    ['Saguenay', 'Saguenay–Lac-Saint-Jean', '02'],
];
$rows = array_map(static fn (array $city): string => '('.implode(', ', array_map($quote, $city)).')', $cities);
$sql[] = "INSERT INTO ville_reference (nom, region, code_region) VALUES\n".implode(",\n", $rows).';';
$sql[] = '';

$root = dirname(__DIR__);
file_put_contents($root.'/db/02-seed.sql', implode("\n", $sql));

// The export someone ran in 2019 "just once" and forgot in the webroot.
$csv = fopen($root.'/public/export_patients.csv', 'w');
fputcsv($csv, array_keys($patients[0]), ';');
foreach (array_slice($patients, 0, 25) as $patient) {
    fputcsv($csv, $patient, ';');
}
fclose($csv);

echo "db/02-seed.sql and public/export_patients.csv generated.\n";
