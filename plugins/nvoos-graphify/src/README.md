# src/

## Purpose

Contains the entire PSR-4 source tree for the NV oOS Graphify plugin — composition root, contracts, and every subsystem (admin, graph engine, tools, REST, frontend, remote sources, memory).

## Tier

| | |
|---|---|
| **Distribution** | Core plugin |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | `nvoos-graphify.php` via `spl_autoload_register` (PSR-4 fallback) + Composer autoload |
| **Optional dependencies** | None |

## Public Surface

Root-level classes form the plugin's backbone:

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphify\Plugin` | `Plugin.php` | Bootstrap (singleton composition root) |
| `NvoosGraphify\Schema` | `Schema.php` | Every subsystem (constants registry) |
| `NvoosGraphify\Settings` | `Settings.php` | All subsystems (grouped option accessor) |
| `NvoosGraphify\ToolRegistry` | `ToolRegistry.php` | Addon plugins, REST controller, ChatService |

## Inputs / Outputs / Neighbors

- **Reads from:** WordPress options, custom DB tables (via `src/Graph/Db`)
- **Writes to:** WordPress options (settings), custom DB tables
- **Upstream callers:** `nvoos-graphify.php` (bootstrap)
- **Downstream collaborators:** All subdirectories — `Admin/`, `Contracts/`, `Frontend/`, `Graph/`, `Memory/`, `Remote/`, `Rest/`, `Tools/`
- **Events fired:** `nvoos_graphify/register_tools`, `nvoos_graphify/register_remote_sources`, `nvoos_graphify/after_settings_saved`
- **Events listened to:** `plugins_loaded`, `rest_api_init`, `save_post`, cron hooks

## Conventions

- One class per file; filename matches FQCN under `src/` (PSR-4).
- `Plugin.php` is the composition root — wires services and registers hooks.
- `Schema.php` centralizes all option keys, table names, hook names, nonces, and capabilities.
- `Settings.php` manages the single grouped `nvoos_graphify_settings` option.

## Tests

```bash
vendor/bin/phpunit tests/
```

## Also Load

- [`../../.context/conventions.md`](../../.context/conventions.md) — naming + style
- [`../../.context/security-checklist.md`](../../.context/security-checklist.md) — security

## See Also

- Parent: [`../`](../) — plugin root
- Sibling addon: [`../../nvoos-graphify-ai/src/`](../../nvoos-graphify-ai/src/)
