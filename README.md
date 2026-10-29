# PII Radar

[![CI](https://github.com/olarno/PII-Radar/actions/workflows/ci.yml/badge.svg)](https://github.com/olarno/PII-Radar/actions/workflows/ci.yml)

**PII Radar trouve où une application PHP legacy stocke, journalise et fait fuiter des données personnelles, puis produit un brouillon de registre Loi 25 / RGPD.** La carte des données est exposée en serveur MCP : votre assistant de code peut demander « où vit le courriel du client ? » ou « ai-je le droit de logger ce champ ? ».

Construit avec [Symfony AI](https://symfony.com/doc/current/ai/index.html) et le [SDK MCP PHP](https://github.com/modelcontextprotocol/php-sdk) pour le Symfony AI & MCP Hackathon.

> **Avertissement :** PII Radar produit un *brouillon* d'inventaire pour aider la démarche de conformité. Ce n'est pas un avis juridique.

## État

🚧 Étape 1 : socle en place et appli de démo [Clinique Bonsoir](demo/legacy-app/README.md) avec son contrat de test `expected-findings.json`. Les scanners arrivent à l'étape 2.

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

L'architecture suit des couches propres, vérifiées par Deptrac : `Domain` (aucune dépendance) ← `Application` ← `Infrastructure` ← `UI`. Les décisions de cadrage sont dans [`docs/decisions.md`](docs/decisions.md).

## Licence

MIT
