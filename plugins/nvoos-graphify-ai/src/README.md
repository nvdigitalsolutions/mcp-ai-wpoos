# src/

## Purpose

Contains the entire PSR-4 source tree for the NV oOS Graphify — AI addon — composition root, provider contracts, chat orchestration, 13 AI provider clients, REST endpoints, and AI-powered tools.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin — requires `nvoos-graphify` core |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `nvoos-graphify-ai.php` via `spl_autoload_register` (PSR-4 fallback) + Composer autoload |
| **Optional dependencies** | `nvoos-graphify` (required) |

## Public Surface

Root-level classes form the addon's backbone:

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphifyAi\Plugin` | `Plugin.php` | Bootstrap (singleton composition root) |
| `NvoosGraphifyAi\ProviderRegistry` | `ProviderRegistry.php` | Chat, REST, Tools |
| `NvoosGraphifyAi\Settings` | `Settings.php` | All subsystems (settings accessor) |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_graphify_settings` option (AI config), core `NvoosGraphify\ToolRegistry`
- **Writes to:** AI provider APIs, REST responses, SSE streams
- **Upstream callers:** `nvoos-graphify-ai.php` (bootstrap)
- **Downstream collaborators:** All subdirectories — `Chat/`, `Contracts/`, `Providers/`, `Rest/`, `Tools/`; also `nvoos-graphify` core
- **Events fired:** None (channels through REST and core hooks)
- **Events listened to:** `nvoos_graphify/register_tools`, `nvoos_graphify/default_settings`, `rest_api_init`

## Conventions

- One class per file; filename matches FQCN under `src/` (PSR-4).
- `Plugin.php` is the composition root — wires providers, tools, and REST routes.
- `ProviderRegistry.php` mirrors the core's `ToolRegistry` pattern.
- `Settings.php` integrates with the core's grouped settings via the `nvoos_graphify/default_settings` filter.

## Tests

```bash
vendor/bin/phpunit tests/
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style
- [`../../../.context/security-checklist.md`](../../../.context/security-checklist.md) — security

## See Also

- Parent: [`../`](../) — plugin root
- Core dependency: [`../../nvoos-graphify/src/`](../../nvoos-graphify/src/)
