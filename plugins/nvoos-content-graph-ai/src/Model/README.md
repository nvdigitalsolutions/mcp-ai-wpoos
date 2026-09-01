# Model

## Purpose

Model management for the content-graph AI addon (Wave D3): the one-time catalog migration that rewrites retired model ids to documented successors, and the supply-chain integrity verifier (blocked models, endpoint TLS, known vulnerabilities). The pricing checker and model rate-limits CCT land next (D3b).

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `ModelCatalogMigration::run_from_catalog()` via `Plugin::register()` (standalone-only `init` hook) |
| **Optional dependencies** | `nvoos-content-graph` (required — shares settings store) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Model\ModelCatalogMigration` | `ModelCatalogMigration.php` | `Plugin::register()` (standalone-only) |
| `NvoosContentGraphAi\Model\ModelIntegrityVerifier` | `ModelIntegrityVerifier.php` | Static utility (model verification call sites land with D3b+) |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_model_configs` (fallback) / `wp_mcp_ai_settings` / `nvoos_content_graph_settings`, assistant post meta `_wp_mcp_ai_model`, bundled `model-catalog.json` (version field)
- **Writes to:** `wp_mcp_ai_model_catalog_migration_version`, `wp_mcp_ai_blocked_models`, `wp_mcp_ai_integrity_log`, rewritten settings/meta
- **Upstream callers:** WordPress `init` (migration), model-consumption call sites (verifier)
- **Downstream collaborators:** base plugin settings/`WP_MCP_AI_Model_Config` (monolith), `CoreBridge` settings store (standalone)
- **Events fired:** `model_catalog_migration_completed` (via base logger in monolith only)
- **Events listened to:** `init`
