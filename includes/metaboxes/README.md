# metaboxes/

## Purpose

Houses WordPress admin metaboxes that embed NV oOS surfaces (currently the AI Content Assistant chat panel) into the edit screens of arbitrary post types.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ (see [`CLAUDE.md`](../../CLAUDE.md)) |
| **Loaded by** | `includes/content-assistant-init.php` — `wp_mcp_ai_init_content_assistant()` `require_once`s `class-wp-mcp-ai-content-assistant-metabox.php` after the feature toggle in `wp_mcp_ai_settings` is checked |
| **Optional dependencies** | None for the base content metabox. Per-CPT metaboxes for Assistants, AI Peers, workflows, and content-management CPTs live with their owning subsystem (`includes/assistants/metaboxes/`, `addons/pro/includes/metaboxes/`) rather than here. |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Content_Assistant_Metabox` | `class-wp-mcp-ai-content-assistant-metabox.php` | `content-assistant-init.php`, admin edit screens for post types selected in `wp_mcp_ai_settings['content_assistant_post_types']` |
| `WP_MCP_AI_Content_Assistant_Metabox::METABOX_ID` (`wp_mcp_ai_content_assistant`) | same file | enqueue/filter keying |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` option (enabled post types, default assistant), the current `$post` / `$post_type`, the assistant CPT.
- **Writes to:** Rendered HTML inside the admin meta-box container; enqueues chat JS/CSS into the edit screen via `admin_enqueue_scripts`.
- **Upstream callers:** `add_meta_boxes` (registration), `admin_enqueue_scripts` (asset wiring), `content-assistant-init.php` (bootstrap).
- **Downstream collaborators:** the chat shortcode/JS (the metabox embeds the same UI), assistant CPT for selecting the active assistant, REST chat endpoints, [`includes/admin/`](../admin/) for shared admin asset handles.
- **Events fired:** `wp_mcp_ai_content_assistant_post_types` filter (allows third-party code to add post types).
- **Events listened to:** `add_meta_boxes`, `admin_enqueue_scripts`.

## Conventions

- One metabox class per file; constructor wires its own `add_action` calls so the class is self-bootstrapping once required.
- Per-CPT metaboxes live with their owning CPT (e.g. assistant metaboxes are under [`includes/assistants/metaboxes/`](../assistants/), and Pro CPTs ship their own `includes/metaboxes/` folder under `addons/pro/includes/`). New base-tier metaboxes that bridge a *generic* WordPress surface (taxonomies, comments, media) belong here; CPT-specific metaboxes do not.
- Front-end-facing chat JS is reused from the shortcode/Elementor path — do not fork it into the admin asset bundle. See [`.context/chat-ui.md`](../../.context/chat-ui.md).

## Tests

```bash
vendor/bin/phpunit tests/test-pm-ai-assistant-metabox.php
vendor/bin/phpunit tests/test-assistant-metabox-crash-fix.php
vendor/bin/phpunit tests/test-disabled-tools-metabox.php
vendor/bin/phpunit tests/test-harness-profile-metabox.php
```

Coverage for the content-assistant metabox itself is exercised indirectly by `tests/test-shortcodes.php` and `tests/test-chat-template-selector.php`, since the metabox embeds the same chat surface.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — capability checks on `render()`, nonce on any state-changing form (always)
- [`.context/chat-ui.md`](../../.context/chat-ui.md) — shared chat panel contract
- [`.context/rest-api.md`](../../.context/rest-api.md) — REST endpoints consumed by enqueued chat JS

## See Also

- Bootstrap: [`includes/content-assistant-init.php`](../content-assistant-init.php)
- Assistant CPT metaboxes: [`includes/assistants/`](../assistants/)
- Pro metabox catalogue: `addons/pro/includes/metaboxes/`
