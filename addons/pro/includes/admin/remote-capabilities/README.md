# Remote Capabilities

## Purpose

Central loader that returns per-toolkit remote capability descriptions for the mesh-network remote-connections feature — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php` (called when rendering remote-connection capability lists) |
| **Optional dependencies** | none — this is a static string-map; every toolkit cap list falls back gracefully when a toolkit is disabled |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Remote_Capabilities_Loader::get_capabilities($toolkit_slug)` | `class-wp-mcp-ai-remote-capabilities-loader.php` | Remote-sites admin page, remote-connection metabox, remote-connection test harness |

The `wp_mcp_ai_{$toolkit_slug}_remote_capabilities` filter lets external code extend or override the per-toolkit capability lists.

## Inputs / Outputs / Neighbors

- **Reads from:** nothing — all capability strings are hardcoded in the `switch` block; the consumer (admin page) decides which toolkit slug to query.
- **Writes to:** nothing — pure read-only lookup.
- **Upstream callers:** [`WP_MCP_AI_Pro_Remote_Sites_Admin`](../class-wp-mcp-ai-pro-remote-sites-admin.php), [`WP_MCP_AI_Pro_Metabox_Remote_Connections`](../class-wp-mcp-ai-pro-metabox-remote-connections.php), remote-connection tests.
- **Downstream collaborators:** none — returns arrays of translated strings.
- **Events fired:** `wp_mcp_ai_{$toolkit_slug}_remote_capabilities` filter.
- **Events listened to:** none.

## Conventions

- Capability strings are translatable via `__()`.  Each toolkit's list is a self-contained `case` block in the static `get_capabilities()` method.
- Toolkits listed in the same `case` block (e.g. `media_toolkit`, `image_production`, `video_production`) share the same capability set — keep them coordinated.
- This class is stateless: every method is `public static`. Do not add instance state.

## Tests

No dedicated test file — remote-capability listing is covered indirectly by the remote-connection admin page and metabox tests:

```bash
vendor/bin/phpunit addons/pro/tests/test-remote-sites-admin.php
vendor/bin/phpunit addons/pro/tests/test-remote-site-manager.php
vendor/bin/phpunit addons/pro/tests/test-remote-connection-access-controls.php
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — escape rules for translated strings (always)
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro/Base placement rules
- [`CLAUDE.md`](../../../../../CLAUDE.md) — PHP-compat (8.1+) + canonical patterns

## See Also

- Parent folder: [`addons/pro/includes/admin/`](../) — the remote-sites admin page that consumes these capabilities
- Topic-specific sub-doc: [`../README-REMOTE-CONNECTIONS.md`](../README-REMOTE-CONNECTIONS.md) — full remote-connection developer reference
