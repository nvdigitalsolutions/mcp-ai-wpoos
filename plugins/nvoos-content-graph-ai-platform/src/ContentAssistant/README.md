# ContentAssistant

## Purpose

Wave E4 port surface. The AI Content Assistant metabox from the base
plugin's `includes/metaboxes/` + `includes/content-assistant-init.php`:
the settings-gated `admin_init` bootstrap and the post-edit-screen
metabox (assistant selector, quick actions, chat modal) — ported
byte-identically so the platform addon carries the feature in
standalone installs without the base plugin.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::registerContentAssistant()` — standalone-only (`! defined('WP_MCP_AI_PATH')`); the base loader's `content-assistant-init.php` owns the same `admin_init` wiring in monolith installs |
| **Optional dependencies** | The base plugin's chat bundle (`wp-mcp-ai-chat`) — monolith-only; standalone degrades to the metabox UI without the embedded chat |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\ContentAssistant\ContentAssistantBootstrap` | `ContentAssistantBootstrap.php` | `Plugin::registerContentAssistant()` — the init.php hook wiring (`admin_init` gate → metabox instantiation) |
| `NvoosContentGraphAiPlatform\ContentAssistant\ContentAssistantMetabox` | `ContentAssistantMetabox.php` | Instantiated by the bootstrap; renders the metabox on post edit screens |

## Inputs / Outputs / Neighbors

- **Reads from:** the `wp_mcp_ai_settings` option
  (`enable_content_assistant_metabox`, `content_assistant_post_types`),
  the `wp_mcp_ai_content_assistant_enabled` /
  `wp_mcp_ai_content_assistant_post_types` /
  `wp_mcp_ai_content_assistant_quick_actions` filters, published
  `mcp_ai_assistant` posts, and the current post being edited.
- **Writes to:** the post-edit metabox UI (side, high), the
  `wp-mcp-ai-content-assistant` style/script enqueues with the
  `wpMcpAiContentAssistant` localization envelope, and (monolith) the
  base `wp-mcp-ai-chat` bundle + `wpMcpAiChat` localization.
- **Upstream callers:** `Plugin::registerContentAssistant()`; the
  WordPress `add_meta_boxes` / `admin_enqueue_scripts` hooks.
- **Downstream collaborators:** WordPress options/scripts/styles APIs;
  the base `WP_MCP_AI_Shortcode` / `WP_MCP_AI_Admin_Settings` /
  `WP_MCP_AI_REST` / `WP_MCP_AI_Request_Context` classes (monolith-only,
  `defined( 'WP_MCP_AI_PATH' ) &&`-gated).
- **Events fired:** none public.
- **Events listened to:** `admin_init` (bootstrap, via
  `Plugin::registerContentAssistant()`), `add_meta_boxes`,
  `admin_enqueue_scripts`.

## Conventions

- **Fail closed on render.** The permission, enabled, and
  no-assistants gates each render a documented message instead of the
  UI.
- **Per-mode asset seam.** The metabox's own assets
  (admin-content-assistant.css/.js — byte-identical copies in the
  platform assets tree) resolve via `asset_url()` / `asset_version()`:
  base `WP_MCP_AI_URL` / `WP_MCP_AI_VERSION` monolith, platform
  constants standalone.
- **Chat bundle is monolith-only.** The `wp-mcp-ai-chat` registration /
  enqueue / `wpMcpAiChat` localization block is gated on
  `defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Shortcode' )`
  — never bare `class_exists()` (the monorepo classmap resolves the
  base shortcode standalone, but its `WP_MCP_AI_URL`-based asset
  registration would fatal without the base plugin).
- Byte-identical constants/hooks/filter names/shapes with the base;
  deviations documented in the class docblocks (text domain, PSR-4
  class names, global init function → static methods on
  `ContentAssistantBootstrap`, `\WP_Query`/`\WP_Post` qualification).
- Standalone-only bootstrap registration — the base loader owns the
  same `admin_init` wiring in monolith installs.
- Note: the base plugin ships no server-side handler for the
  quick-action AJAX endpoint (`wp_mcp_ai_content_assistant_action`) —
  byte-identical port scope, no handler to port.

## Tests

- `tests/test-content-assistant.php` — the feature gate, registration
  wiring, metabox ID, post-type set + filters, metabox registration,
  the three render gates, the full render, the quick-action registry +
  filter, assistant enumeration, context/title/placeholder helpers, and
  the per-mode asset enqueue (both matrices).

```bash
vendor/bin/phpunit plugins/nvoos-content-graph-ai-platform/tests/test-content-assistant.php
```

## Also Load

- [`../Google/README.md`](../Google/README.md) — the E4-3 sub-cluster
  (shared per-mode seam + README conventions)
- [`../Integrations/README.md`](../Integrations/README.md) — the E4-2
  OAuth manager
- [`../README.md`](../README.md) — composition root + subsystem index
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (capability + nonce gates)

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E4 row status
- [`includes/metaboxes/class-wp-mcp-ai-content-assistant-metabox.php`](../../../../includes/metaboxes/class-wp-mcp-ai-content-assistant-metabox.php) — the base subsystem (the port's origin)
