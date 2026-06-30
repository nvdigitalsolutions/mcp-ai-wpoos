# NV oOS Graphify

## Purpose

Visual knowledge graph for WordPress — maps your content into an interactive, navigable graph using Cytoscape.js, without requiring any API keys.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin — no NV oOS dependency |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | `nvoos-graphify.php` → `plugins_loaded` priority 10 |
| **Optional dependencies** | None (core graph; AI features require `nvoos-graphify-ai`) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `nvoos_graphify_get_tool_registry()` | `nvoos-graphify.php` | Addon plugins |
| `nvoos_graphify_get_setting()` | `nvoos-graphify.php` | Addon plugins |
| `nvoos_graphify_is_enabled()` | `nvoos-graphify.php` | Addon plugins |
| `NvoosGraphify\Plugin` | `src/Plugin.php` | Bootstrap (only) |
| `NvoosGraphify\Schema` | `src/Schema.php` | All subsystems |
| `NvoosGraphify\Settings` | `src/Settings.php` | All subsystems |
| `NvoosGraphify\ToolRegistry` | `src/ToolRegistry.php` | Addon plugins, REST, Chat |
| `NvoosGraphify\Contracts\Tool` | `src/Contracts/Tool.php` | All tool implementations |
| `NvoosGraphify\Contracts\RemoteSource` | `src/Contracts/RemoteSource.php` | Remote driver implementations |

## Inputs / Outputs / Neighbors

- **Reads from:** WordPress posts/terms/users/media, the `nvoos_graphify_settings` option, custom tables (`nvoos_graphify_nodes`, `_edges`, `_meta`, `_remote_sources`, `_embeddings`)
- **Writes to:** Custom DB tables (graph data), the `nvoos_graphify_settings` option, transients
- **Upstream callers:** WordPress core (activation, cron, save_post, shortcodes, REST)
- **Downstream collaborators:** `src/Graph/` (db, builder, analyzer), `src/Tools/` (tool layer), `src/Remote/` (external sources)
- **Events fired:** `nvoos_graphify/register_tools`, `nvoos_graphify/register_remote_sources`, `nvoos_graphify/before_build`, `nvoos_graphify/after_build`, `nvoos_graphify/after_settings_saved`, `nvoos_graphify/memory_stored`
- **Events listened to:** `plugins_loaded`, `rest_api_init`, `save_post`, `nvoos_graphify/cron_build`, `nvoos_graphify/cron_enrich`, `nvoos_graphify/initial_build`

## Conventions

- Namespace: `NvoosGraphify\` — PSR-4 mapped to `src/`.
- Hook names use `nvoos_graphify/` prefix (actions) and `nvoos_graphify/` prefix (filters).
- All constants live in `NvoosGraphify\Schema` — no magic strings in other classes.
- Tools implement `NvoosGraphify\Contracts\Tool`; remote drivers implement `NvoosGraphify\Contracts\RemoteSource`.
- The `nvoos_graphify_settings` option is a single grouped option — no per-setting rows in wp_options.

## Tests

```bash
vendor/bin/phpunit tests/
```

## Also Load

- [`readme.txt`](readme.txt) — WordPress.org plugin readme

## See Also

- [`nvoos-graphify-ai/`](../nvoos-graphify-ai/) — AI addon (chat, providers, AI tools)
- [`src/`](src/) — source code root
