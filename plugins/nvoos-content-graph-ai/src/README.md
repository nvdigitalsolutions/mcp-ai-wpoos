# src/

## Purpose

Contains the entire PSR-4 source tree for the NV oOS Content Graph — AI addon — the composition root (`Plugin`), the `nvoos/core` engine bridge (`CoreBridge`), adapters, embeddings, RAG, agent memory, REST endpoints, 13 AI-powered tools, and the ported engine pieces (Wave E6: OOS shadow runner, gates, suppression; markup elicitation subsystem under `Engine/Markup/`; Paper Store flat-file knowledge store under `Engine/PaperStore/`; OKF knowledge engine under `Engine/Okf/`; Crawl4AI background coordinator under `Engine/Crawler/`).

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin — requires `nvoos-content-graph` core |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `nvoos-content-graph-ai.php` via `spl_autoload_register` (PSR-4 fallback) + Composer autoload |
| **Optional dependencies** | `nvoos-content-graph` (required), `nvoos/core` + `nvoos/wordpress-adapter` (bundled via Composer) |

## Public Surface

Root-level classes form the addon's backbone:

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Plugin` | `Plugin.php` | Bootstrap (singleton composition root) |
| `NvoosContentGraphAi\CoreBridge` | `CoreBridge.php` | Plugin, REST, Tools — single source of truth for all `nvoos/core` services (providers, tools, chat, embeddings, RAG, memory) |
| `NvoosContentGraphAi\Security\CredentialStore` | `Security/CredentialStore.php` | Encrypted API key storage, save-path routing, render masking, legacy-plaintext migration |
| `NvoosContentGraphAi\Cli` | `Cli.php` | WP-CLI commands (`wp nvoos-cg-ai migrate-keys` / `key-status` / `status` / `tools list` / `providers list` / `settings list|get` / `graph stats` / `oos parity [diff]` — the parity surface is standalone-only) |
| `NvoosContentGraphAi\Engine\OosShadowRunner` | `Engine/OosShadowRunner.php` | Sampled parallel OOS shadow runs + parity store (Wave E6, standalone-only) |
| `NvoosContentGraphAi\Engine\Markup\MarkupBootstrap` | `Engine/Markup/MarkupBootstrap.php` | Markup elicitation bootstrap — interrupt-and-resume canvas flow (Wave E6, standalone-only); full surface in `Engine/Markup/README.md` |
| `NvoosContentGraphAi\Engine\PaperStore\PaperStoreBootstrap` | `Engine/PaperStore/PaperStoreBootstrap.php` | Paper Store bootstrap — flat-file knowledge store (Wave E6, standalone-only); full surface in `Engine/PaperStore/README.md` |
| `NvoosContentGraphAi\Engine\Okf\OkfBootstrap` | `Engine/Okf/OkfBootstrap.php` | OKF bootstrap — Open Knowledge Format engine (Wave E6, standalone-only); full surface in `Engine/Okf/README.md` |
| `NvoosContentGraphAi\Engine\Crawler\Crawler` | `Engine/Crawler/Crawler.php` | Crawl4AI background coordinator (Wave E6, standalone-only); full surface in `Engine/Crawler/README.md` |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_content_graph_ai_credentials` option (encrypted provider keys), `nvoos_content_graph_settings` option (non-secret AI config merged via the `nvoos_content_graph/default_settings` filter), core `NvoosContentGraph\ToolRegistry`
- **Writes to:** `nvoos_content_graph_ai_credentials` option (autoload=false), AI provider APIs, REST responses, SSE streams, `nvoos_content_graph_embeddings` table
- **Upstream callers:** `nvoos-content-graph-ai.php` (bootstrap)
- **Downstream collaborators:** All subdirectories — `Adapter/`, `Admin/`, `Embeddings/`, `Memory/`, `Rest/`, `Tools/`, `Engine/` (Wave E6); also `nvoos-content-graph` core
- **Events fired:** `nvoos_content_graph_ai/continue_chat` (Action Scheduler)
- **Events listened to:** `nvoos_content_graph/register_tools`, `nvoos_content_graph/default_settings`, `nvoos_content_graph/after_build`, `rest_api_init`, `pre_update_option_nvoos_content_graph_settings`, `nvoos_content_graph/section_field_value`

## Conventions

- One class per file; filename matches FQCN under `src/` (PSR-4).
- `Plugin.php` is the bootstrap — it delegates provider routing, tool execution, and chat orchestration to `CoreBridge`, which owns the framework-agnostic `nvoos/core` engine.
- `CoreBridge.php` is the **single source of truth** for all `nvoos/core` service instances — no duplicate wiring.
- AI settings are merged into the core's grouped `nvoos_content_graph_settings` option via the `nvoos_content_graph/default_settings` filter — **except API keys**, which `Security\CredentialStore` keeps encrypted in `nvoos_content_graph_ai_credentials` (never in the settings option, never re-rendered).
- WordPress adapters (`ErrorFactory`, `SettingsStore`, PSR-18 HTTP client) live in `Adapter/`.

## Tests

```bash
vendor/bin/phpunit tests/
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style
- [`../../../.context/security-checklist.md`](../../../.context/security-checklist.md) — security

## See Also

- Parent: [`../`](../) — plugin root
- Core dependency: [`../../nvoos-content-graph/src/`](../../nvoos-content-graph/src/)
