# Pro Helpers

## Purpose

Small, stateless Pro-only utility classes that several Pro toolkits need but that don't belong inside a service or repository — the Pro analog to [`includes/helpers/`](../../../../includes/helpers/).

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ (see [`CLAUDE.md`](../../../../CLAUDE.md)) |
| **Loaded by** | Lazy `require_once` from each caller (e.g. `WP_MCP_AI_Pro_Tool_Site_Creator::process_theme_enhanced()`, `WP_MCP_AI_Tool_Scaffold_Theme_Structure::execute()`, `WP_MCP_AI_Tool_Extract_Site_Design_From_Mockups::build_theme_json_partial()`) guarded by `class_exists()` — no eager bootstrap, so Base-mode sites pay nothing |
| **Optional dependencies** | None. Helpers must work on a vanilla WordPress install. The classes here generate data structures (e.g. `theme.json`) — they do not call into Site Editor / theme-installation APIs themselves. |

## Public Surface

Every class in this folder is part of the public surface; there are no internal-only helpers.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Theme_JSON_Generator` | `class-wp-mcp-ai-theme-json-generator.php` | Site-Creator toolkit tools (`WP_MCP_AI_Pro_Tool_Site_Creator`, `WP_MCP_AI_Tool_Scaffold_Theme_Structure`, `WP_MCP_AI_Tool_Extract_Site_Design_From_Mockups`), Base test suite |

`WP_MCP_AI_Theme_JSON_Generator` exposes the canonical static API: `generate()`, `get_default_color_palette()`, `get_industry_color_palette( $industry )`, `validate()`, and `to_json()`. The schema version constant (`SCHEMA_VERSION = 2`) and schema URL constant pin the WordPress 2025 theme.json target.

## Inputs / Outputs / Neighbors

- **Reads from:** caller-provided arguments only (`$args` arrays describing theme name, type, palette, typography, spacing, custom templates, template parts). No WP options, post meta, or DB reads.
- **Writes to:** nothing persistent. Helpers return PHP arrays / JSON strings; callers (the Site-Creator tools) are responsible for writing the resulting `theme.json` to disk.
- **Upstream callers:** Pro tools in [`../tools/site-creator-toolkit/`](../tools/site-creator-toolkit/) and [`../src/Tools/`](../src/Tools/) (Shopify / Site-Creator family).
- **Downstream collaborators:** none — helpers are deliberately leaf nodes. They reuse no other Pro service, no WP API, and call no remote service.
- **Events fired:** none.
- **Events listened to:** none — no hooks are registered from this folder.

## Conventions

- All classes here are **static** utility classes — no instance state, no constructors with side effects. Match the Base pattern in [`includes/helpers/`](../../../../includes/helpers/).
- No persistence (no options, post meta, transients, scheduled events) and no remote calls. If you need either, the code belongs in [`../services/`](../services/) or [`../data-stores/`](../data-stores/).
- Helpers MUST stay dependency-free at load time. They may be `require_once`'d from a tool's `execute()` mid-request, so they cannot rely on the container, registries, or any Pro service being booted.
- Each helper does one thing. The current resident generates `theme.json` structures only — do not bolt unrelated utilities onto it; add a new class instead.
- Helpers MUST validate their own input and return `WP_Error` (or throw, where the caller is a tool) rather than emitting partial output. See `WP_MCP_AI_Theme_JSON_Generator::validate()` for the pattern.

## Tests

```bash
vendor/bin/phpunit tests/test-theme-json-generator.php
vendor/bin/phpunit addons/pro/tests/test-tool-extract-site-design-from-mockups.php
vendor/bin/phpunit addons/pro/tests/test-seed-template-library-tool.php
```

The primary suite lives in the Base `tests/` directory (`test-theme-json-generator.php`) because the helper is dependency-free and can be exercised without the full Pro bootstrap; the Pro tool integration tests cover the Site-Creator call sites that consume it.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — input validation rules for helpers consumed by tools (always)
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — canonical return envelope used by the Site-Creator callers
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — when something should be a helper here vs in [`includes/helpers/`](../../../../includes/helpers/)
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP compat, two-gate sanitisation

## See Also

- Base counterpart: [`includes/helpers/`](../../../../includes/helpers/) — the Base utility helpers (`Profession_Search_Helper`, `Tool_Presets_Helper`, `User_Context_Helper`, `Shortcut_Recommendations`)
- Primary callers: [`../tools/site-creator-toolkit/`](../tools/site-creator-toolkit/), [`../src/Tools/`](../src/Tools/) — Site-Creator family of tools
- Sibling Pro folders that wrap helpers in lifecycle: [`../services/`](../services/), [`../data-stores/`](../data-stores/)
