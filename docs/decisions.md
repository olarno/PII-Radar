# Design decisions

Decisions approved from October 29, 2026 (1 to 7 at scoping, 8 to 10 before step 1, 11 to 13 at step 2c, 14 to 16 at step 3a, 17 at step 4a, 18 with the README, 19 at step 6), on top of the scoping document. They take precedence over it in case of conflict.

| # | Topic | Decision | Consequence |
|---|---|---|---|
| 1 | Test contract | `demo/legacy-app/expected-findings.json` is written **before** the scanners. | It is the spec and the E2E test: 100% of the planted findings found, 0 false positive on the traps. |
| 2 | Role of AI | AI goes through **Symfony AI** (`symfony/ai-platform`), with Hugging Face Inference Providers by default. **No local mode, no Ollama.** The provider stays configurable (OpenAI, Anthropic…). | A Hugging Face token is required for a real scan. Tests and CI run **without a key**, with the Symfony AI mocked platform. The HF coupon is for the final end-to-end tests. |
| 3 | Flow tracking | Heuristic, limited to one file: `$_POST`/`$_GET` keys, column names in SQL strings, variables tracked within the same file. | No full data flow (taint) analysis. Enough for Clinique Bonsoir. |
| 4 | PII-006 (retention) | Explicit heuristic: no `DELETE` or purge on a table classified as PII, no scheduled task. | The rule documents what it looks for and what it cannot prove. |
| 5 | PII-008 (outside Quebec) | Deterministic table of known hosts and their jurisdiction. | No guessing: an unknown host is reported as "jurisdiction to be confirmed". |
| 6 | Leak to the LLM | Code excerpts go through regex masking (e-mail, phone, SIN, NAM, postal code) before anything is sent to the LLM, from the MVP on. | The LLM never sees personal data, even when hard-coded in the audited code. |
| 7 | Pre-release | Package names checked on Packagist on day 1, versions locked in `composer.lock`. | See below. |
| 8 | Demo app | Clinique Bonsoir is a fake back office: pages render and read the database, without aiming at a complete app. | Credible on screen for the video, without spending time on it. |
| 9 | SMS API of the demo | `rappel.php` calls Twilio (`api.twilio.com`), which the PII-008 jurisdiction table knows as American. | The demo never really calls Twilio (no credentials). |
| 10 | False-positive traps | Those of the scoping document: `parametres_courriel.email_template` and the `ville_reference` table. | Listed in `not_pii` of `expected-findings.json`. |
| 11 | Where rules live | Rules PII-001 to PII-008 live in `src/Domain/Rule` (not `Infrastructure/Rule` as in the scoping document). | They are pure functions over the schema, the flows and the exposed files: testable without framework or files. |
| 12 | Confidence threshold | A column only triggers a rule when its PII confidence reaches 0.5. | `email_template` (0.27) triggers nothing. `ville_reference.nom` (0.6) still triggers PII-006: the agent rejects it at the next step. The end-to-end test tolerates this case, and only this one. |
| 13 | Outside Quebec | For PII-008, the rest of Canada is also "outside Quebec" (Law 25, s. 17). A host missing from the table gives a low-severity finding, "jurisdiction to be confirmed". | No communication is assumed local by default. |
| 14 | What the LLM arbitrates | Candidates with a confidence of 0.8 or more are decided by the rules; the others are arbitrated by the `arbiter` agent (Symfony AI, Hugging Face). The default model is `openai/gpt-oss-120b`, configurable with `PII_RADAR_MODEL`. | Few LLM calls, only on ambiguous cases. The prompt only holds metadata; code excerpts go through `PiiMasker`. |
| 15 | Agent tests | Unit tests use the Symfony AI scripted platform. The end-to-end test replays a recording of a real Hugging Face call (`CassetteHttpClient`), recorded once. | CI tests the real model answers, without key or network. Changing the prompt, the tools or the demo means recording again. |
| 16 | Investigation, then verdict | Arbitration takes two agents: `arbiter` investigates with its tools and writes its conclusions, then `verdicts` (no tools) returns the structured output. | Hugging Face providers reject `tools` and `response_format` in the same request ("\"tools\" is incompatible with \"response_format\""). Cost: one more model call per scan. If `verdicts` forgets a column, the investigator's written conclusion line serves as the verdict; `pii:scan -v` shows both. |
| 17 | Last scan stored | Each `pii:scan` stores its report as versioned JSON in `var/pii-radar/` (same format as `--format=json`). The MCP server, the register and the web report read it back; only the `pii_scan` tool runs a new scan. | Asking the assistant costs nothing and answers instantly. An answer always gives the date of the scan it relies on. |
| 18 | Demo without a token | `pii:scan demo/legacy-app --replay` replays the real recorded agent call (`demo/replay/agent-arbitration.json`), the same one as CI. Agents, prompts and tools are the real ones: only the HTTP client of the Hugging Face bridge answers from the recording. | The jury sees the agent and the rejected traps in 5 minutes, without key or cost. The output, the report and the register say the answers are replayed. Replay limited to the demo. |
| 19 | One language | Everything PII Radar produces or ships is in English: CLI, web report, registers, MCP answers, agent prompts and justifications, code comments, docs, video. Only the audited demo app, Clinique Bonsoir, stays in French, as a Quebec clinic's would. | The schema of the demo keeps its French names (`courriel`, `nam`), which is what the bilingual name classifier is for. Law 25 terms keep their Quebec acronym next to the English one (PIA, "EFVP"). |

## Locked versions (day 1)

| Package | Version |
|---|---|
| `symfony/ai-agent`, `ai-bundle`, `ai-platform`, `ai-store` | 1.0.0-BETA1 |
| `symfony/ai-hugging-face-platform` | 1.0.0-BETA1 |
| `symfony/ai-sqlite-store` | 1.0.0-BETA1 |
| `symfony/mcp-bundle` | 1.0.0-BETA1 |
| `mcp/sdk` | 0.9.0 |
| Symfony | 7.4 (LTS) |
| PHP | 8.4 |

Differences with the names of the scoping document: the SQLite store is the `symfony/ai-sqlite-store` package, and the Hugging Face bridge relies on `symfony/ai-generic-platform` (installed as a dependency). `minimum-stability` is `beta` because of the pre-release.

## Technical choices of step 0

- **Environment variables:** Symfony convention (versioned `.env` with defaults, secrets in `.env.local`) rather than the `.env.dist` of the scoping document.
- **Web server:** FrankenPHP (`dunglas/frankenphp` image), a single container for the app.
- **MCP server:** a `pii_radar` server, exposed over STDIO (`bin/console mcp:server`) and HTTP (`/mcp/pii_radar`). Elements are discovered in `App\Infrastructure\Mcp\`.
