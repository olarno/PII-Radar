# Clinique Bonsoir

Faux back-office d'une clinique, écrit « comme en 2014 » en PHP 7.4 procédural. C'est l'application que PII Radar audite dans la démo.

**Toutes les personnes sont fictives** : les données sont générées par Faker (locale `fr_CA`, graine fixe) avec `bin/generate-seed.php`.

## Lancer

Avec la stack du projet (`docker compose up -d` à la racine), l'appli répond sur http://localhost:8081.

Comptes (mots de passe volontairement faibles, hachés en MD5) :

| Courriel | Mot de passe | Rôle |
|---|---|---|
| `reception@clinique-bonsoir.test` | `bonsoir` | Réception |
| `admin@clinique-bonsoir.test` | `admin2014` | Admin |

## Ce qui est planté

Chaque règle PII-001 à PII-008 est plantée au moins une fois. La liste exacte, avec fichier et ligne, est dans [`expected-findings.json`](expected-findings.json) : c'est le contrat du test de bout en bout.

| Règle | Où |
|---|---|
| PII-001 Donnée sensible en clair | `patients.nam`, `rendezvous.motif` |
| PII-002 PII dans un journal | `public/patient.php` (`error_log` du dossier), `public/paiement.php` (journal texte) |
| PII-003 Tiers non déclaré | `public/rappel.php` (Twilio) |
| PII-004 SQL concaténé avec une PII | `public/inscription.php`, `public/login.php` |
| PII-005 Mot de passe faible | `public/login.php` (MD5) |
| PII-006 Aucune rétention | Tables `patients`, `users`, `paiements` |
| PII-007 Export dans le webroot | `public/export_patients.csv` |
| PII-008 Envoi hors Québec | `public/rappel.php` (Twilio, États-Unis) |

Pièges à faux positifs : `parametres_courriel.email_template` et la table `ville_reference` ne contiennent aucune donnée personnelle. À l'inverse, `rendezvous` est purgé par `cron/purge_rendezvous.php` et ne doit pas déclencher PII-006.

## Régénérer les données

```bash
php demo/legacy-app/bin/generate-seed.php
```

Après une modification du code de la démo, mettre à jour les lignes de `expected-findings.json` : le test `ExpectedFindingsContractTest` vérifie qu'elles pointent toujours sur la bonne preuve.
