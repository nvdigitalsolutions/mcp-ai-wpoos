# PARA

## Purpose

Integrates Tiago Forte's PARA methodology (Projects / Areas / Resources / Archives) into the Project Management toolkit — registers the `mcp_ai_para` hierarchical taxonomy with four locked root terms, the `mcp_ai_area` CPT for ongoing responsibilities, the lifecycle hooks that drive Project→Archive transitions, and the admin-column UI that surfaces PARA classification across participating post types.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/mcp-ai-wpoos-pro.php` → `wp_mcp_ai_pro_init()` `require_once`s `para/class-wp-mcp-ai-para-init.php`, which loads the four classes and calls each one's `::init()`. **Feature-flagged**: every public method (taxonomy registration, CPT registration, lifecycle hooks, admin columns) short-circuits via `WP_MCP_AI_PARA_Taxonomy::is_enabled()`, which requires the Project Management toolkit to be enabled **and** the `enable_para_organization` setting to be on. Per the v1.1.13 CHANGELOG note, PARA is "feature-flagged and pending a separate review window" — leave it off by default. |
| **Optional dependencies** | none required, but the QMS subsystem ([`../qms/`](../qms/)) bridges into PARA via `WP_MCP_AI_QMS_PARA_Bridge` when both are enabled. |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_PARA_Taxonomy` (`TAXONOMY = mcp_ai_para`, `ROOTS = [projects, areas, resources, archives]`, `::init()`, `::is_enabled()`, `::get_object_types()`, `::save_post()`, `::register_taxonomy()`, `::seed_root_terms()`) | `class-wp-mcp-ai-para-taxonomy.php` | `para-init.php`, QMS bridge, PARA tools, tests |
| `WP_MCP_AI_PARA_Area_CPT` (`POST_TYPE = mcp_ai_area`, `::init()`, `::register()`, `::get_area()`) | `class-wp-mcp-ai-para-area-cpt.php` | PARA tools (`para-create-area`, `para-list-areas`, `para-update-area`), QMS bridge, tests |
| `WP_MCP_AI_PARA_Lifecycle` (`::init()`) | `class-wp-mcp-ai-para-lifecycle.php` | `para-init.php`; emits state-transition behaviour consumed by PARA tools and tests |
| `WP_MCP_AI_PARA_Admin_Columns` (`::init()`) | `class-wp-mcp-ai-para-admin-columns.php` | `para-init.php`; pure admin-UI surface |

The four locked root term slugs (`projects`, `areas`, `resources`, `archives`) are part of the contract — they cannot be deleted or renamed.

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (the `enable_project_management_toolkit` and `enable_para_organization` flags); the participating post types returned by `WP_MCP_AI_PARA_Taxonomy::get_object_types()`; existing posts/terms during lifecycle transitions.
- **Writes to:** the `mcp_ai_para` taxonomy terms, `mcp_ai_area` posts and their meta (including `_para_last_reviewed`), and the term relationships table for classified posts.
- **Upstream callers:** [`../mcp-ai-wpoos-pro.php`](../../mcp-ai-wpoos-pro.php) (boot), PARA tools under [`../tools/project-management/`](../tools/project-management/) (e.g. `class-wp-mcp-ai-tool-para-create-area.php`, `class-wp-mcp-ai-tool-para-list-areas.php`, `class-wp-mcp-ai-tool-para-update-area.php`), `WP_MCP_AI_QMS_PARA_Bridge`.
- **Downstream collaborators:** WordPress core taxonomy + CPT APIs. No service-layer dependencies inside this folder.
- **Events fired:** `init` (taxonomy at priority 11, root-term seeding at priority 12, Area CPT at priority 10), `save_post_mcp_ai_area`, and the standard taxonomy/CPT WordPress hooks.
- **Events listened to:** `pre_delete_term` and `pre_insert_term` (to lock the four root slugs); `save_post` (PARA classification persistence); lifecycle action(s) for Project→Archive moves.

## Conventions

- **Feature flag is mandatory.** Every entry point in this folder calls `WP_MCP_AI_PARA_Taxonomy::is_enabled()` first. Never register the taxonomy or CPT unconditionally — doing so leaks the `mcp_ai_area` post type into sites that opted out.
- **The four root terms are immutable.** Their slugs are listed in `WP_MCP_AI_PARA_Taxonomy::ROOTS` and are guarded by `protect_root_terms()` / `protect_root_term_slugs()`. Adding a fifth root requires an explicit ADR — children of an existing root are the supported extension point.
- Reference post types and taxonomies via the class constants (`POST_TYPE`, `TAXONOMY`), never the literal strings — the slugs are versioned and may be filtered in the future.
- This folder is the **registration layer only**. Tool execute() bodies, REST surfaces, and orchestration logic live under [`../tools/project-management/`](../tools/project-management/).

## Tests

```bash
vendor/bin/phpunit tests/para/
```

Existing suites: [`tests/para/test-para-taxonomy.php`](../../../../tests/para/test-para-taxonomy.php) (registration + locked-root behaviour, classification persistence), [`tests/para/test-para-lifecycle.php`](../../../../tests/para/test-para-lifecycle.php) (state transitions, archive moves). Admin-column rendering coverage is intentionally light — it is exercised through manual QA per the v1.1.13 review window.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — capability + nonce rules
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro-only feature gating
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — for the PARA tools that consume this surface
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat policy, canonical return envelope

## See Also

- Sibling integration: [`../qms/`](../qms/) (the `WP_MCP_AI_QMS_PARA_Bridge` listens to PARA Area transitions)
- PARA tools: [`../tools/project-management/class-wp-mcp-ai-tool-para-create-area.php`](../tools/project-management/class-wp-mcp-ai-tool-para-create-area.php), `class-wp-mcp-ai-tool-para-list-areas.php`, `class-wp-mcp-ai-tool-para-update-area.php`
- Toolkit bootstrap: [`../project-management-init.php`](../project-management-init.php)
- Boot wiring: [`../../mcp-ai-wpoos-pro.php`](../../mcp-ai-wpoos-pro.php) (`wp_mcp_ai_pro_init()`)
