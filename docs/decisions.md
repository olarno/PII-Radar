# Décisions de cadrage

Décisions validées le 29 octobre 2026 (1 à 7 au cadrage, 8 à 10 avant l'étape 1, 11 à 13 à l'étape 2c, 14 à 16 à l'étape 3a), en complément du doc de cadrage. Elles priment sur lui en cas de conflit.

| # | Sujet | Décision | Conséquence |
|---|---|---|---|
| 1 | Contrat de test | `demo/legacy-app/expected-findings.json` est écrit **avant** les scanners. | C'est la spec et le test E2E : 100 % des findings plantés retrouvés, 0 faux positif sur les pièges. |
| 2 | Place de l'IA | L'IA passe par **Symfony AI** (`symfony/ai-platform`), avec Hugging Face Inference Providers par défaut. **Pas de mode local, pas d'Ollama.** Le fournisseur reste configurable (OpenAI, Anthropic…). | Un token Hugging Face est requis pour un vrai scan. Tests et CI tournent **sans clé**, avec la plateforme mockée de Symfony AI. Le coupon HF sert aux tests de fin de parcours. |
| 3 | Suivi des flux | Heuristique, limitée à un fichier : clés `$_POST`/`$_GET`, noms de colonnes dans les chaînes SQL, variables suivies dans un même fichier. | Pas d'analyse de flux de données (taint) complète. Suffisant pour Clinique Bonsoir. |
| 4 | PII-006 (rétention) | Heuristique explicite : aucun `DELETE` ni purge sur une table classée PII, aucune tâche planifiée. | La règle documente ce qu'elle cherche et ce qu'elle ne peut pas prouver. |
| 5 | PII-008 (hors Québec) | Table déterministe des hôtes connus et de leur juridiction. | Pas de devinette : un hôte inconnu est signalé « juridiction à confirmer ». |
| 6 | Fuite vers le LLM | Les extraits de code passent par un masquage regex (courriel, téléphone, NAS, NAM, code postal) avant tout envoi au LLM, dès le MVP. | Le LLM ne voit jamais une donnée personnelle, même écrite en dur dans le code audité. |
| 7 | Pré-release | Noms de paquets vérifiés sur Packagist le jour 1, versions verrouillées dans `composer.lock`. | Voir ci-dessous. |
| 8 | Appli de démo | Clinique Bonsoir est un faux back-office : les pages s'affichent et lisent la base, sans viser une appli complète. | Crédible à l'écran pour la vidéo, sans y passer de temps. |
| 9 | API SMS de la démo | `rappel.php` appelle Twilio (`api.twilio.com`), que la table des juridictions de PII-008 reconnaît comme américain. | La démo n'appelle jamais réellement Twilio (identifiants absents). |
| 10 | Pièges à faux positifs | Ceux du doc de cadrage : `parametres_courriel.email_template` et la table `ville_reference`. | Listés dans `not_pii` de `expected-findings.json`. |
| 11 | Place des règles | Les règles PII-001 à PII-008 vivent dans `src/Domain/Rule` (et non `Infrastructure/Rule` comme dans le doc de cadrage). | Ce sont des fonctions pures sur le schéma, les flux et les fichiers exposés : testables sans framework ni fichiers. |
| 12 | Seuil de confiance | Une colonne ne déclenche une règle que si sa confiance PII atteint 0,5. | `email_template` (0,27) ne déclenche rien. `ville_reference.nom` (0,6) déclenche encore PII-006 : l'agent l'écartera à l'étape suivante. Le test de bout en bout tolère ce cas, et seulement lui. |
| 13 | Hors Québec | Pour PII-008, le reste du Canada est aussi « hors Québec » (Loi 25, art. 17). Un hôte absent de la table donne un finding de sévérité faible, « juridiction à confirmer ». | Aucune communication n'est supposée locale par défaut. |
| 14 | Ce que le LLM arbitre | Les candidats à 0,8 de confiance ou plus sont décidés par les règles ; les autres sont arbitrés par l'agent `arbiter` (Symfony AI, Hugging Face). Le modèle par défaut est `openai/gpt-oss-120b`, configurable via `PII_RADAR_MODEL`. | Peu d'appels au LLM, uniquement sur les cas ambigus. Le prompt ne contient que des métadonnées ; les extraits de code passent par `PiiMasker`. |
| 15 | Tests de l'agent | Les tests unitaires utilisent la plateforme scriptée de Symfony AI. Le test de bout en bout rejoue une cassette d'un vrai appel Hugging Face (`CassetteHttpClient`), enregistrée une fois. | La CI teste les vraies réponses du modèle, sans clé ni réseau. Changer le prompt, les outils ou la démo oblige à réenregistrer la cassette. |
| 16 | Enquête puis verdict | L'arbitrage se fait en deux agents : `arbiter` enquête avec ses outils et conclut par écrit, puis `verdicts` (sans outils) rend la sortie structurée. | Les fournisseurs Hugging Face refusent `tools` et `response_format` dans la même requête (« "tools" is incompatible with "response_format" »). Coût : un appel au modèle de plus par scan. |

## Versions verrouillées (jour 1)

| Paquet | Version |
|---|---|
| `symfony/ai-agent`, `ai-bundle`, `ai-platform`, `ai-store` | 1.0.0-BETA1 |
| `symfony/ai-hugging-face-platform` | 1.0.0-BETA1 |
| `symfony/ai-sqlite-store` | 1.0.0-BETA1 |
| `symfony/mcp-bundle` | 1.0.0-BETA1 |
| `mcp/sdk` | 0.9.0 |
| Symfony | 7.4 (LTS) |
| PHP | 8.4 |

Écarts avec les noms du doc de cadrage : le Store SQLite est le paquet `symfony/ai-sqlite-store`, et le bridge Hugging Face s'appuie sur `symfony/ai-generic-platform` (installé en dépendance). `minimum-stability` est à `beta` à cause de la pré-release.

## Choix techniques de l'étape 0

- **Variables d'environnement :** convention Symfony (`.env` versionné avec des valeurs par défaut, secrets dans `.env.local`) plutôt que le `.env.dist` du doc de cadrage.
- **Serveur web :** FrankenPHP (image `dunglas/frankenphp`), un seul conteneur pour l'appli.
- **Serveur MCP :** un serveur `pii_radar`, exposé en STDIO (`bin/console mcp:server`) et en HTTP (`/mcp/pii_radar`). Les éléments sont découverts dans `App\Infrastructure\Mcp\`.
