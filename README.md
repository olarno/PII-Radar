# PII Radar

[![CI](https://github.com/olarno/PII-Radar/actions/workflows/ci.yml/badge.svg)](https://github.com/olarno/PII-Radar/actions/workflows/ci.yml)

**PII Radar trouve où une application PHP legacy stocke, journalise et fait fuiter des données personnelles, puis produit un brouillon de registre Loi 25 / RGPD.** La carte des données est exposée en serveur MCP : votre assistant de code peut demander « où vit le courriel du client ? » ou « ai-je le droit de logger ce champ ? ».

Construit avec [Symfony AI](https://symfony.com/doc/current/ai/index.html) et le [SDK MCP PHP](https://github.com/modelcontextprotocol/php-sdk) pour le Symfony AI & MCP Hackathon.

> **Avertissement :** PII Radar produit un *brouillon* d'inventaire pour aider la démarche de conformité. Ce n'est pas un avis juridique.

## État

✅ MVP complet : scan déterministe, arbitrage par un agent Symfony AI, serveur MCP, brouillon de registre Loi 25 / RGPD et rapport web.

## Démarrage

```bash
git clone https://github.com/olarno/PII-Radar.git && cd PII-Radar
echo "HUGGINGFACE_API_KEY=hf_xxx" > .env.local   # token Hugging Face, requis pour un vrai scan
docker compose up -d
```

- **Rapport web : http://localhost:8080/report** (findings, données personnelles, carte des flux, parcours de chaque champ, registre à télécharger)
- Appli de démo « Clinique Bonsoir » : http://localhost:8081 (compte `reception@clinique-bonsoir.test` / `bonsoir`, rappelé sur la page de connexion)
- Serveur MCP HTTP : http://localhost:8080/mcp/pii_radar

Ports 8080 ou 8081 déjà pris sur votre machine ? Changez-les au lancement :

```bash
APP_PORT=8090 LEGACY_APP_PORT=8091 docker compose up -d
```

### Scanner l'appli de démo

Le scan affiche les findings (règle, sévérité, emplacement, correctif), puis les données personnelles (avec qui a décidé et pourquoi) et les flux dans le code.

Les colonnes ambiguës sont arbitrées par un agent Symfony AI sur Hugging Face : il faut un token (`HUGGINGFACE_API_KEY` dans `.env.local`). Sans token, `--no-ai` lance un scan déterministe, où l'heuristique décide seule.

```bash
docker compose exec app bin/console pii:scan demo/legacy-app
docker compose exec app bin/console pii:scan demo/legacy-app --format=json
```

### Générer le brouillon de registre

À partir du dernier scan, sans nouvel appel au LLM :

```bash
docker compose exec app bin/console pii:register                     # Loi 25, sur la sortie standard
docker compose exec app bin/console pii:register --format=rgpd --output=var/registre-rgpd.md --name="Clinique Bonsoir"
```

Le registre décrit ce que montrent le code et le schéma : renseignements par catégorie, tiers destinataires et communications hors Québec (EFVP), suppressions trouvées, mesures de sécurité et lacunes. Tout ce qui ne se déduit pas du code (finalités, durées de conservation, responsable) est marqué « à confirmer ».

### Brancher le serveur MCP

Le serveur MCP répond à partir du **dernier scan** enregistré (`var/pii-radar/`) : lancez `pii:scan` une fois, puis posez vos questions sans coût.

Avec Claude Code, rien à configurer : le fichier [`.mcp.json`](.mcp.json) du dépôt branche le serveur via `docker compose`. Pour un autre client, le serveur répond en STDIO (`docker compose exec -T app bin/console mcp:server`) et en HTTP (`http://localhost:8080/mcp/pii_radar`).

| Capacité | Nom | Rôle |
|---|---|---|
| Outil | `pii_where_is` | Où vit une donnée (« courriel du patient », `patients.nam`) |
| Outil | `pii_can_i_log` | Un champ peut-il être journalisé ? Réponse, règle, alternative, fuites existantes |
| Outil | `pii_findings` | Findings filtrés par sévérité, fichier ou règle |
| Outil | `pii_scan` | Relance un scan (appelle le LLM, sauf `withAgent: false`) |
| Ressource | `pii://register` | Brouillon de registre Loi 25 |
| Ressource | `pii://register/rgpd` | Brouillon de registre RGPD (art. 30) |
| Modèle de ressource | `pii://field/{table}/{column}` | Fiche complète d'un champ |
| Prompt | `pii-review` | Revue d'un diff sous l'angle des données personnelles |

Le projet interrogé est `PII_RADAR_PROJECT` (par défaut `demo/legacy-app`).

## Développement

```bash
composer qa        # CS-Fixer, PHPStan (niveau max), Deptrac, PHPUnit
```

Les tests n'ont besoin d'aucune clé : le test de bout en bout de l'agent rejoue une cassette d'un vrai appel Hugging Face. Pour la réenregistrer après une modification du prompt, des outils ou de la démo :

```bash
rm tests/Fixtures/cassettes/demo-arbitration.json
docker compose exec -e HUGGINGFACE_API_KEY=hf_... app vendor/bin/phpunit tests/E2E/DemoArbitrationTest.php
```

L'architecture suit des couches propres, vérifiées par Deptrac : `Domain` (aucune dépendance) ← `Application` ← `Infrastructure` ← `UI`. Les décisions de cadrage sont dans [`docs/decisions.md`](docs/decisions.md).

## Licence

MIT
