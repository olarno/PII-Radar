# PII Radar

[![CI](https://github.com/olarno/PII-Radar/actions/workflows/ci.yml/badge.svg)](https://github.com/olarno/PII-Radar/actions/workflows/ci.yml)

**PII Radar trouve où une application PHP legacy stocke, journalise et fait fuiter des données personnelles, puis produit un brouillon de registre Loi 25 / RGPD.** La carte des données est exposée en serveur MCP : votre assistant de code peut demander « où vit le courriel du client ? » ou « ai-je le droit de logger ce champ ? ».

Construit avec [Symfony AI](https://symfony.com/doc/current/ai/index.html) et le [SDK MCP PHP](https://github.com/modelcontextprotocol/php-sdk) pour le Symfony AI & MCP Hackathon.

> **Avertissement :** PII Radar produit un *brouillon* d'inventaire pour aider la démarche de conformité. Ce n'est pas un avis juridique.

## État

🚧 L'agent Symfony AI arbitre les colonnes ambiguës (données personnelles ou non) avec ses outils, sans jamais voir de valeur. Prochaine étape : serveur MCP, registre Loi 25 et rapport web.

## Démarrage

```bash
git clone https://github.com/olarno/PII-Radar.git && cd PII-Radar
echo "HUGGINGFACE_API_KEY=hf_xxx" > .env.local   # token Hugging Face, requis pour un vrai scan
docker compose up -d
```

- Appli PII Radar : http://localhost:8080/health
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

### Brancher le serveur MCP (STDIO)

```json
{
  "mcpServers": {
    "pii-radar": {
      "command": "docker",
      "args": ["compose", "exec", "-T", "app", "bin/console", "mcp:server"]
    }
  }
}
```

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
