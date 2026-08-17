# src/

## Purpose

Contains the entire PSR-4 source tree for the NV oOS Content Graph — AI addon — the composition root (`Plugin`), the `nvoos/core` engine bridge (`CoreBridge`), adapters, embeddings, RAG, agent memory, REST endpoints, and 13 AI-powered tools.

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

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_content_graph_settings` option (AI config merged via the `nvoos_content_graph/default_settings` filter), core `NvoosContentGraph\ToolRegistry`
- **Writes to:** AI provider APIs, REST responses, SSE streams, `nvoos_content_graph_embeddings` table
- **Upstream callers:** `nvoos-content-graph-ai.php` (bootstrap)
- **Downstream collaborators:** All subdirectories — `Adapter/`, `Admin/`, `Embeddings/`, `Memory/`, `Rest/`, `Tools/`; also `nvoos-content-graph` core
- **Events fired:** `nvoos_content_graph_ai/continue_chat` (Action Scheduler)
- **Events listened to:** `nvoos_content_graph/register_tools`, `nvoos_content_graph/default_settings`, `nvoos_content_graph/after_build`, `rest_api_init`

## Conventions

- One class per file; filename matches FQCN under `src/` (PSR-4).
- `Plugin.php` is the bootstrap — it delegates provider routing, tool execution, and chat orchestration to `CoreBridge`, which owns the framework-agnostic `nvoos/core` engine.
- `CoreBridge.php` is the **single source of truth** for all `nvoos/core` service instances — no duplicate wiring.
- AI settings are merged into the core's grouped `nvoos_content_graph_settings` option via the `nvoos_content_graph/default_settings` filter.
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
