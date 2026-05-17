# Professions

## Purpose

Defines the `mcp_ai_profession` Custom Post Type and the seeding pipeline that ships per-industry knowledge bases, tool presets, playbooks, and orchestration recipes used by the professional-selector UI and per-profession assistants.

## Tier

| | |
|---|---|
| **Distribution** | Base (Pro adds extra professions through the same seeders) |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` via `includes/professions/professions-init.php` (registers the CPT on `init` priority 5) |
| **Optional dependencies** | JetEngine (mirrors the CPT into a CCT when present); WP-CLI (the orchestration CLI is registered only when `WP_CLI` is defined) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Profession_CPT` (`POST_TYPE` constant) | `class-wp-mcp-ai-profession-cpt.php` | REST teams controller, admin AJAX handlers, tests |
| `WP_MCP_AI_Profession_Seeder::init()` / `::seed_professions()` | `class-wp-mcp-ai-profession-seeder.php` | `professions-init.php`, admin "reseed" AJAX |
| `WP_MCP_AI_Profession_Base_Knowledge_Seeder::init()` | `class-wp-mcp-ai-profession-base-knowledge-seeder.php` | `professions-init.php`, reseed AJAX |
| `WP_MCP_AI_Profession_Playbook_Seeder` | `class-wp-mcp-ai-profession-playbook-seeder.php` | `professions-init.php`, advanced settings playbook statistics, regenerate/sync AJAX |
| `WP_MCP_AI_Profession_Orchestration_Seeder` | `class-wp-mcp-ai-profession-orchestration-seeder.php` | Orchestration dashboard AJAX, CLI |
| `WP_MCP_AI_Profession_Orchestration_CLI` | `class-wp-mcp-ai-profession-orchestration-cli.php` | `WP_CLI` (registered from `class-wp-mcp-ai-cli-command.php`) |
| `wp_mcp_ai_get_profession_service()` | `professions-init.php` | Anywhere a `WP_MCP_AI_Profession_Service` is needed without touching the container |
| `profession-dataset-mappings.php` (data file) | `profession-dataset-mappings.php` | Seeders only — `require_once`-d on demand |
| `metaboxes/` (subfolder) | `metaboxes/class-wp-mcp-ai-profession-metabox-*.php` | `WP_MCP_AI_Profession_CPT::init_metaboxes()` |

## Inputs / Outputs / Neighbors

- **Reads from:** `WP_MCP_AI_Profession_Repository` (cache + posts table), bundled knowledge-base markdown under `knowledge-base/`, playbook fixtures, dataset mappings from `profession-dataset-mappings.php`.
- **Writes to:** `mcp_ai_profession` CPT posts and their post meta, the `wp_mcp_ai_professions_seeded` / `wp_mcp_ai_professions_datasets_synced` options (one-shot flags), and the repository cache.
- **Upstream callers:** professional-selector shortcode, REST teams controller (`includes/rest/class-wp-mcp-ai-rest-teams-controller.php`), profession admin pages (`includes/admin/class-wp-mcp-ai-admin-profession-*.php`), orchestration dashboard.
- **Downstream collaborators:** [`includes/repositories/class-wp-mcp-ai-profession-repository.php`](../repositories/class-wp-mcp-ai-profession-repository.php), [`includes/services/class-wp-mcp-ai-profession-service.php`](../services/class-wp-mcp-ai-profession-service.php), [`includes/services/class-wp-mcp-ai-profession-knowledge-base-loader.php`](../services/class-wp-mcp-ai-profession-knowledge-base-loader.php).
- **Events fired:** `save_post_{POST_TYPE}` (via WP core), the seeders trigger `wp_mcp_ai_profession_seeded`, `wp_mcp_ai_profession_playbook_seeded`, and `wp_mcp_ai_profession_orchestration_seeded` actions on completion.
- **Events listened to:** `init` (priority 5), `save_post_mcp_ai_profession`, `delete_post` (for cache invalidation), `add_meta_boxes_mcp_ai_profession`.

## Conventions

- The CPT post type slug is held by `WP_MCP_AI_Profession_CPT::POST_TYPE` — always reference the constant, never the string literal.
- Seeders MUST be idempotent: each one short-circuits on its own `_synced` / `_seeded` option flag so that activation, upgrade, and reseed AJAX paths are safe to run repeatedly.
- Seeders run lazy — `require_once` their fixtures inside the method, never at file load — so that callers who only need the CPT don't pull in the full dataset.
- Metabox classes live in `metaboxes/` and extend `WP_MCP_AI_Profession_Metabox_Base`; new metaboxes must be registered through `metaboxes-loader.php`, not directly from the CPT.
- The data tables (mappings, playbook fixtures, knowledge-base markdown) belong outside this folder; this folder is the orchestration layer over them.

## Tests

```bash
vendor/bin/phpunit --filter '/^Test_Profession_/' tests/
```

Key suites: `test-profession-integration.php`, `test-profession-base-knowledge-seeder.php`, `test-profession-playbook-seeder.php`, `test-profession-dataset-mappings.php`, `test-profession-reseeding.php`, `test-profession-knowledge-base-loader.php`, `test-profession-model-merge.php`, `test-profession-tools-registration.php`.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — capability + nonce rules used by reseed/regenerate AJAX endpoints
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — how profession presets resolve to tool slugs
- [`.context/pro-vs-base.md`](../../.context/pro-vs-base.md) — Pro adds extra professions through the same seeders
- [`CLAUDE.md`](../../CLAUDE.md) — PHP compat + tool patterns

## See Also

- Sibling folders: [`../repositories/`](../repositories/), [`../services/`](../services/), [`../teams/`](../teams/), [`../assistants/`](../assistants/)
- Admin surfaces: [`../admin/class-wp-mcp-ai-admin-profession-research-page.php`](../admin/class-wp-mcp-ai-admin-profession-research-page.php), [`../admin/class-wp-mcp-ai-admin-profession-settings.php`](../admin/class-wp-mcp-ai-admin-profession-settings.php)
- Data: `knowledge-base/` (markdown sources used by `Profession_Base_Knowledge_Seeder`)
