# Helpers

## Purpose

Small, stateless utility classes that several subsystems need but that don't belong inside a service or repository — pure helpers with no lifecycle of their own.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` (eagerly required before tool registration so admin UI and tools can call helpers during boot) |
| **Optional dependencies** | none — helpers must work on a vanilla WordPress install |

## Public Surface

Every class in this folder is part of the public surface; there are no internal-only helpers here.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Profession_Search_Helper` | `class-wp-mcp-ai-profession-search-helper.php` | Profession admin pages, Pro dashboard, `includes/professions/` |
| `WP_MCP_AI_Tool_Presets_Helper` | `class-wp-mcp-ai-tool-presets-helper.php` | Assistant builder, profession selector, tool toggle UI, tests |
| `WP_MCP_AI_User_Context_Helper` | `class-wp-mcp-ai-user-context-helper.php` | REST controllers, AJAX handlers, CLI commands — anywhere `wp_set_current_user()` would otherwise be called |
| `WP_MCP_AI_Shortcut_Recommendations` | `class-wp-mcp-ai-shortcut-recommendations.php` | Tool registry presentation, slash-commands dashboard, prompt-shortcut UI |

## Inputs / Outputs / Neighbors

- **Reads from:** `get_userdata()`, blog membership on multisite, tool slug/name from `WP_MCP_AI_Tool_Interface`, profession CPT metadata via callers (helpers don't query directly).
- **Writes to:** nothing persistent. `User_Context_Helper` mutates the global current-user state for the remainder of the request; everything else is pure.
- **Upstream callers:** REST controllers (`includes/rest/`), admin screens (`includes/admin/`), CLI (`includes/class-wp-mcp-ai-cli-command.php`), the tool registry, and Pro addon code.
- **Downstream collaborators:** WordPress core (`get_userdata`, `is_user_member_of_blog`, `wp_set_current_user`); the tool / profession data structures defined elsewhere.
- **Events fired:** none — helpers are deliberately silent. (`User_Context_Helper` triggers WP core's `set_current_user` action transitively.)
- **Events listened to:** none — no hooks are registered from this folder.

## Conventions

- All classes here are **static** utility classes — no instance state, no constructors with side effects.
- No new database tables, options, transients, or scheduled events. If you need persistence, the code belongs in `includes/services/` or `includes/repositories/`.
- Helpers must remain dependency-free at load time. They may be `require_once`-d before the rest of the plugin is wired, so they cannot rely on the container, repositories, or registries being available.
- Each helper does one thing — if a method needs another helper's data, the caller composes them, not the helper itself.
- Keep functions side-effect-free where possible; the one current exception (`User_Context_Helper::switch_to_user_id()`) is named to make the mutation explicit and validated before it fires.

## Tests

```bash
vendor/bin/phpunit tests/test-user-context-helper.php tests/test-cache-helper.php tests/test-shortcut-recommendations.php
```

Coverage is per-helper rather than per-folder; relevant suites include `test-user-context-helper.php`, `test-assistant-tool-presets.php` (exercises `Tool_Presets_Helper`), and the recommendation paths covered by `test-tool-registry*.php`.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — capability + user-switching rules that `User_Context_Helper` enforces
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — context for `Tool_Presets_Helper` and `Shortcut_Recommendations`
- [`CLAUDE.md`](../../CLAUDE.md) — PHP compat for shared utilities

## See Also

- Sibling folders that wrap helpers in lifecycle: [`../services/`](../services/), [`../repositories/`](../repositories/)
- Helpers callers: [`../admin/`](../admin/), [`../rest/`](../rest/), [`../tools/`](../tools/), [`../professions/`](../professions/)
