# NV oOS Content Graph

## Purpose

Visual knowledge graph for WordPress — maps your content into an interactive, navigable graph using Cytoscape.js, without requiring any API keys.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin — no NV oOS dependency |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | `nvoos-content-graph.php` → `plugins_loaded` priority 10 |
| **Optional dependencies** | None (core graph; AI features require `nvoos-content-graph-ai`) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `nvoos_content_graph_get_tool_registry()` | `nvoos-content-graph.php` | Addon plugins |
| `nvoos_content_graph_get_setting()` | `nvoos-content-graph.php` | Addon plugins |
| `nvoos_content_graph_is_enabled()` | `nvoos-content-graph.php` | Addon plugins |
| `NvoosContentGraph\Plugin` | `src/Plugin.php` | Bootstrap (only) |
| `NvoosContentGraph\Schema` | `src/Schema.php` | All subsystems |
| `NvoosContentGraph\Settings` | `src/Settings.php` | All subsystems |
| `NvoosContentGraph\ToolRegistry` | `src/ToolRegistry.php` | Addon plugins, REST, Chat |
| `NvoosContentGraph\Contracts\Tool` | `src/Contracts/Tool.php` | All tool implementations |
| `NvoosContentGraph\Contracts\RemoteSource` | `src/Contracts/RemoteSource.php` | Remote driver implementations |

## Inputs / Outputs / Neighbors

- **Reads from:** WordPress posts/terms/users/media, the `nvoos_content_graph_settings` option, custom tables (`nvoos_content_graph_nodes`, `_edges`, `_meta`, `_remote_sources`, `_embeddings`)
- **Writes to:** Custom DB tables (graph data), the `nvoos_content_graph_settings` option, transients
- **Upstream callers:** WordPress core (activation, cron, save_post, shortcodes, REST)
- **Downstream collaborators:** `src/Graph/` (db, builder, analyzer), `src/Tools/` (tool layer), `src/Remote/` (external sources)
- **Events fired:** `nvoos_content_graph/register_tools`, `nvoos_content_graph/register_remote_sources`, `nvoos_content_graph/before_build`, `nvoos_content_graph/after_build`, `nvoos_content_graph/after_settings_saved`, `nvoos_content_graph/memory_stored`
- **Events listened to:** `plugins_loaded`, `rest_api_init`, `save_post`, `nvoos_content_graph/cron_build`, `nvoos_content_graph/cron_enrich`, `nvoos_content_graph/initial_build`

## Conventions

- Namespace: `NvoosContentGraph\` — PSR-4 mapped to `src/`.
- Hook names use `nvoos_content_graph/` prefix (actions) and `nvoos_content_graph/` prefix (filters).
- All constants live in `NvoosContentGraph\Schema` — no magic strings in other classes.
- Tools implement `NvoosContentGraph\Contracts\Tool`; remote drivers implement `NvoosContentGraph\Contracts\RemoteSource`.
- The `nvoos_content_graph_settings` option is a single grouped option — no per-setting rows in wp_options.

## Tests

```bash
vendor/bin/phpunit tests/
```

## Also Load

- [`readme.txt`](readme.txt) — WordPress.org plugin readme

## See Also

- [`nvoos-content-graph-ai/`](../nvoos-content-graph-ai/) — AI addon (chat, providers, AI tools)
- [`src/`](src/) — source code root
