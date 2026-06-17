# Pro Blocks

## Purpose

Server-side Gutenberg block definitions (`block.json` + `render.php`) for every Pro toolkit surface — e-commerce, social media, calendar booking, DJ management, financial planning, multilingual, AI tool builder, media toolkit, and the vehicle-cleaning estimator — so end users can drop a toolkit UI into any block-editor page without touching shortcodes.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ (see [`CLAUDE.md`](../../../../CLAUDE.md)) |
| **Loaded by** | `WP_MCP_AI_Pro_Toolkit_Blocks` in [`../class-wp-mcp-ai-pro-toolkit-blocks.php`](../class-wp-mcp-ai-pro-toolkit-blocks.php) — instantiated by `WP_MCP_AI_Pro_Toolkit_Integration::init()` and self-gated on `function_exists( 'register_block_type' )` before walking each subfolder on `init` |
| **Optional dependencies** | The WordPress block editor (5.8+ for `block.json` discovery). Each per-block `render.php` is the bridge into a Pro toolkit shortcode (`[mcp_calendar_booking_form]`, `[mcp_ecommerce_products]`, …) — the underlying toolkit must be enabled in `wp_mcp_ai_settings` for the rendered shortcode to return content. |

## Public Surface

The contract is the **registered block name** (`mcp-ai-toolkits/<folder-slug>`) — callers reference blocks by name in saved post content, never by PHP symbol. Per-block subfolders are the unit of registration.

| Subfolder | Block name | Toolkit |
|---|---|---|
| `ecommerce-products/`, `ecommerce-search/`, `ecommerce-orders/` | `mcp-ai-toolkits/ecommerce-*` | E-commerce |
| `social-calendar/`, `social-templates/` | `mcp-ai-toolkits/social-*` | Social Media |
| `calendar-booking/`, `calendar-services/`, `calendar-staff/` | `mcp-ai-toolkits/calendar-*` | Calendar Booking |
| `dj-equipment/`, `dj-packages/` | `mcp-ai-toolkits/dj-*` | DJ Management |
| `financial-budget/`, `financial-goals/` | `mcp-ai-toolkits/financial-*` | Financial Planner |
| `multilingual-translation-memory/`, `multilingual-glossaries/` | `mcp-ai-toolkits/multilingual-*` | Multilingual |
| `ai-tool-builder-templates/`, `ai-tool-builder-schemas/` | `mcp-ai-toolkits/ai-tool-builder-*` | AI Tool Builder |
| `media-templates/`, `media-collections/` | `mcp-ai-toolkits/media-*` | Media Toolkit |
| `vehicle-cleaning-estimator/` | `mcp-ai-toolkits/vehicle-cleaning-estimator` | Vehicle services |

Each subfolder owns exactly two files: `block.json` (single source of metadata, referenced via `render: file:./render.php`) and `render.php` (server-side renderer). Editor-side JS for these blocks is bundled elsewhere; this folder is the PHP contract only.

## Inputs / Outputs / Neighbors

- **Reads from:** block attributes (saved in post content), per-toolkit settings options (`wp_mcp_ai_settings`, `wp_mcp_ai_calendar_booking_settings`, etc.), the Pro CPTs each toolkit owns (services, staff, products, packages, …).
- **Writes to:** server-rendered HTML in the front-end page body; no persistent writes happen from `render.php`.
- **Upstream callers:** the `init` hook (priority 10) via `WP_MCP_AI_Pro_Toolkit_Blocks::register_blocks()`, and the `block_categories_all` filter for the shared `mcp-ai-toolkits` category.
- **Downstream collaborators:** `WP_MCP_AI_Pro_Toolkit_Shortcodes` (each `render.php` delegates to the toolkit shortcode), the relevant per-toolkit service and CPT folders under [`../`](../), and Base [`includes/blocks/`](../../../../includes/blocks/) (parallel Base block surface).
- **Events fired:** standard WordPress `render_block_mcp-ai-toolkits/<name>` filters; no custom NV oOS hooks.
- **Events listened to:** `init` (block registration + category filter); no other hooks attached from this folder.

## Conventions

- One block per subfolder, named `mcp-ai-toolkits/<folder-slug>` — the folder slug, the `name` field in `block.json`, and the resulting shortcode prefix MUST match.
- Registration is centralised: add the new folder name to the `$block_dirs` array in [`../class-wp-mcp-ai-pro-toolkit-blocks.php`](../class-wp-mcp-ai-pro-toolkit-blocks.php). Do not call `register_block_type()` from inside `render.php`.
- `block.json` is the single source of metadata (attributes, supports, textdomain `mcp-ai-wpoos-pro`); never duplicate those values in PHP.
- `render.php` is the **output gate** — escape every attribute that ends up in HTML and wrap output with `get_block_wrapper_attributes()`. Re-escape attribute data even though the editor stored it; see [`.context/security-checklist.md`](../../../../.context/security-checklist.md).
- Render callbacks must short-circuit cleanly when the underlying Pro toolkit is disabled (the shortcode they delegate to returns an empty string in that case — do not duplicate the toolkit-flag check here).
- No editor JS lives in this folder. Bundled editor scripts are registered through the parent `WP_MCP_AI_Pro_Toolkit_Integration` and loaded via `wp_enqueue_scripts` / `enqueue_block_editor_assets`.

## Tests

These blocks are exercised indirectly through their underlying shortcodes and toolkit integration tests:

```bash
vendor/bin/phpunit addons/pro/tests/test-media-toolkit-integration.php
vendor/bin/phpunit addons/pro/tests/test-media-template-presets.php
vendor/bin/phpunit addons/pro/tests/test-eca-tools-integration.php
vendor/bin/phpunit addons/pro/tests/test-vehicle-estimation-tools.php
vendor/bin/phpunit addons/pro/tests/test-cross-toolkit-mounts.php
```

There is no per-block PHPUnit suite yet — coverage flows through the shortcode that each `render.php` delegates to. The Base block-render-context guard in [`tests/test-block-render-non-block-context.php`](../../../../tests/test-block-render-non-block-context.php) applies to Pro blocks too.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping inside `render.php` (always)
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — toolkit-flag patterns for the underlying shortcodes
- [`.context/chat-ui.md`](../../../../.context/chat-ui.md) — front-end conventions shared with Base block surfaces
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP compat, escaping rules

## See Also

- Block registrar: [`../class-wp-mcp-ai-pro-toolkit-blocks.php`](../class-wp-mcp-ai-pro-toolkit-blocks.php)
- Shortcode bridge: [`../class-wp-mcp-ai-pro-toolkit-shortcodes.php`](../class-wp-mcp-ai-pro-toolkit-shortcodes.php) — every `render.php` ultimately delegates here
- Sibling page-builder surface: [`../elementor/`](../elementor/) — same toolkits, Elementor widget flavour
- Base counterpart: [`includes/blocks/`](../../../../includes/blocks/) — chat, assistant builder, performance, scheduled-result blocks
- Integration coordinator: [`../class-wp-mcp-ai-pro-toolkit-integration.php`](../class-wp-mcp-ai-pro-toolkit-integration.php)
