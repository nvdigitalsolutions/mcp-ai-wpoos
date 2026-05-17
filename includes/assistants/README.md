# Assistants — Assistant CPT & Metaboxes

## Purpose

Registers the `mcp_ai_assistant` custom post type and its admin metaboxes — the single source of truth for an assistant's provider, model, system prompt, tools, skills, datasets, credentials, and harness profile — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ (file guards against earlier PHP at the top of `class-wp-mcp-ai-assistant-cpt.php`) |
| **Loaded by** | [`includes/class-assistant-cpt.php`](../class-assistant-cpt.php) (loads `metaboxes-loader.php` then the CPT class), driven from `includes/bootstrap/loader.php` |
| **Optional dependencies** | JetEngine (optional CCT sync of assistants), WooCommerce / Elementor / Rank Math (each contributes metabox sections only when active) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Assistant_CPT` | `class-wp-mcp-ai-assistant-cpt.php` | `includes/services/`, `includes/rest/`, `includes/a2a/`, `includes/teams/`, Pro addons |
| `WP_MCP_AI_Assistant_CPT::POST_TYPE` + `META_*` constants | `class-wp-mcp-ai-assistant-cpt.php` | every caller that reads/writes assistant meta (use the constants, not bare strings) |
| `WP_MCP_AI_Metabox_Base` (abstract) | `metaboxes/class-wp-mcp-ai-metabox-base.php` | every metabox in this folder + Pro metaboxes |
| `WP_MCP_AI_Metabox_Credentials`, `..._Defaults`, `..._Primary_Roles`, `..._Base_Knowledge`, `..._Mesh_Routing`, `..._Datasets`, `..._Skills`, `..._MCP_Apps`, `..._Harness_Profile` | `metaboxes/class-wp-mcp-ai-metabox-*.php` | wired by the CPT constructor; not called externally |
| `metaboxes-loader.php` | `metaboxes-loader.php` | included by `includes/class-assistant-cpt.php` ahead of the CPT class |

## Inputs / Outputs / Neighbors

- **Reads from:** assistant post meta (all `META_*` keys declared on the CPT class), `WP_MCP_AI_Tool_Registry` (for available tools in metaboxes), profession/team CPT data when populating defaults, `WP_MCP_AI_Credentials` for hashed bearer-token storage.
- **Writes to:** assistant post meta on save (tools, provider, model, prompt, skills, datasets, credentials, harness profile, role rules), optional JetEngine CCT mirror, optional Pro mesh-routing metadata.
- **Upstream callers:** [`includes/admin/`](../admin/) (post type list table actions), [`includes/rest/`](../rest/) (assistant directory + create/update endpoints), [`includes/services/`](../services/) (chat service resolves provider/model/tools from the CPT), [`includes/a2a/`](../a2a/) (Agent Card builder reads metadata), [`includes/teams/`](../teams/) seeder.
- **Downstream collaborators:** [`includes/tools/`](../tools/) registry (read-only), [`includes/harness/`](../harness/) `Harness_Profile` (metabox writes the JSON profile), [`includes/professions/`](../professions/) for default population.
- **Events fired:** `save_post_mcp_ai_assistant` (WordPress core action, but it is *this* folder's contract). Filters: standard `manage_*_columns`, `manage_edit-mcp_ai_assistant_columns`, plus credential-related actions handled by `WP_MCP_AI_Credentials`.
- **Events listened to:** `init` (CPT registration), `add_meta_boxes_mcp_ai_assistant`, `save_post_mcp_ai_assistant`, `admin_enqueue_scripts` (per-screen), invalidation hooks fired from `includes/bootstrap/hooks.php`.

## Conventions

- **Always reference meta keys via the `WP_MCP_AI_Assistant_CPT::META_*` constants** — bare string literals like `'_wp_mcp_ai_tools'` are a code-review smell because the constant is the rename-safe contract.
- Each metabox class lives in `metaboxes/` and extends `WP_MCP_AI_Metabox_Base`. Do **not** add a metabox by hooking `add_meta_boxes` from elsewhere — instantiate it in the CPT constructor so save/nonce/capability handling stays uniform.
- The CPT is loaded twice in the path: once via `metaboxes-loader.php` (eager class loads for save handlers) and once via the CPT class itself. Preserve that order; lazy-loading metaboxes breaks `save_post` paths in REST contexts.
- Optional-dependency metaboxes (`Mesh_Routing`, `MCP_Apps`, `Harness_Profile`) must gracefully no-op when the relevant subsystem is absent — never assume JetEngine / Pro / harness flags are present.

## Tests

PHPUnit coverage for the CPT and metaboxes lives in the root `tests/` directory. Run a representative slice with:

```bash
vendor/bin/phpunit tests/test-assistant-tools.php
vendor/bin/phpunit tests/test-assistant-system-prompt-integration.php
vendor/bin/phpunit tests/test-assistant-metabox-crash-fix.php
vendor/bin/phpunit tests/test-assistant-primary-roles.php
vendor/bin/phpunit tests/test-harness-profile-metabox.php
vendor/bin/phpunit tests/test-jetengine-assistants-cct.php
vendor/bin/phpunit tests/test-default-assistants.php
```

Plus REST-side coverage in `tests/test-rest-assistant-*.php` and the directory pagination tests.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — capability + nonce rules for metabox saves (always)
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — how assistants reference tools by slug
- [`.context/chat-ui.md`](../../.context/chat-ui.md) — how the front-end consumes assistant metadata
- [`.context/pro-vs-base.md`](../../.context/pro-vs-base.md) — Pro-only metabox sections
- [`CLAUDE.md`](../../CLAUDE.md) — PHP-compat policy

## See Also

- Sibling: [`agents/`](../agents/) — assigned per-assistant via `Metabox_Primary_Roles`
- Sibling: [`teams/`](../teams/) — groups assistants for orchestration
- Sibling: [`harness/`](../harness/) — `Metabox_Harness_Profile` writes the per-assistant profile consumed there
- Default content: [`includes/class-wp-mcp-ai-default-assistants.php`](../class-wp-mcp-ai-default-assistants.php), [`includes/class-wp-mcp-ai-ai-peer-cpt.php`](../class-wp-mcp-ai-ai-peer-cpt.php)
