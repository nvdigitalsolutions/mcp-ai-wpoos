# blocks/

## Purpose

Server-side registration and PHP render callbacks for the NV oOS Gutenberg block library — chat, chat-bubble, professional/assistant selectors, tools-grid, knowledge-base, assistant-builder, scheduled-result, and performance dashboard blocks.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ (see [`CLAUDE.md`](../../CLAUDE.md)) |
| **Loaded by** | `includes/bootstrap/loader.php` (loads `class-wp-mcp-ai-assistant-builder-blocks.php`, `class-wp-mcp-ai-performance-blocks.php`, `class-wp-mcp-ai-scheduled-result-block.php`) — each class self-gates on `function_exists( 'register_block_type' )` |
| **Optional dependencies** | WordPress block editor (5.8+ for `block.json` discovery). No third-party plugin dependency. |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Assistant_Builder_Blocks` | `class-wp-mcp-ai-assistant-builder-blocks.php` | bootstrap — registers `chat`, `chat-bubble`, `professional-selector`, `assistant-selector`, `tools-grid`, `knowledge-base`, `assistant-builder` from per-block subfolders |
| `WP_MCP_AI_Performance_Blocks` | `class-wp-mcp-ai-performance-blocks.php` | Pro Dashboard, performance widgets |
| `WP_MCP_AI_Scheduled_Result_Block` | `class-wp-mcp-ai-scheduled-result-block.php` | scheduled-result rendering surface |
| `block.json` + `render.php` | `chat/`, `chat-bubble/`, `assistant-builder/`, `assistant-selector/`, `professional-selector/`, `tools-grid/`, `knowledge-base/`, `scheduled-result/` | the Gutenberg block registry |

Per-block subfolders are the canonical contract — JS edit components live under `assets/`, but the PHP render side is here.

## Inputs / Outputs / Neighbors

- **Reads from:** Block attributes (saved in post content), `wp_mcp_ai_settings` option, assistant CPT, professional CPT, tool registry, scheduled-result store.
- **Writes to:** Server-rendered HTML in front-end pages, block-editor REST responses, enqueued script/style handles (`wp-mcp-ai-assistant-builder-blocks`, etc.).
- **Upstream callers:** `init` hook (priority 20), `enqueue_block_editor_assets`, `wp_enqueue_scripts`, `WP_MCP_AI_Plugin::bootstrap()`.
- **Downstream collaborators:** [`includes/class-wp-mcp-ai-shortcode.php`](../class-wp-mcp-ai-shortcode.php) (chat block delegates to the shortcode), [`includes/renderers/`](../renderers/) (scheduled-result renderer), assistant CPT, REST endpoints consumed by block-editor JS.
- **Events fired:** Each block emits standard WordPress `render_block_<namespace>/<name>` filters.
- **Events listened to:** `init` (block registration), `enqueue_block_editor_assets`, `wp_enqueue_scripts`.

## Conventions

- Block registration goes through `register_block_type( $block_dir )` so `block.json` is the single source of metadata. Add new blocks by creating a new per-block subfolder with a `block.json` and a `render.php`.
- Render callbacks are **server-only** — never trust attribute data without re-escaping at the output gate (see [`.context/security-checklist.md`](../../.context/security-checklist.md)).
- Editor JS is bundled under `assets/js/` and registered through the same `*-Blocks` class; do not enqueue editor scripts from `render.php`.
- Block render callbacks must short-circuit cleanly when called outside a real block context (covered by `tests/test-block-render-non-block-context.php`).

## Tests

```bash
vendor/bin/phpunit tests/test-assistant-builder-blocks.php
vendor/bin/phpunit tests/test-block-render-non-block-context.php
vendor/bin/phpunit tests/test-pro-dashboard-charts.php   # exercises Performance_Blocks
```

The scheduled-result block is exercised indirectly by `tests/test-elementor-scheduled-result-widget.php` (the Elementor widget delegates to the same renderer) and the Pro schedule-presets suite.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — escaping inside `render.php` (always)
- [`.context/chat-ui.md`](../../.context/chat-ui.md) — front-end chat surface contract
- [`.context/rest-api.md`](../../.context/rest-api.md) — endpoints called from block-editor JS

## See Also

- Sibling renderer surfaces: [`includes/elementor/`](../elementor/), [`includes/renderers/`](../renderers/), `includes/class-wp-mcp-ai-shortcode.php`, `includes/class-wp-mcp-ai-shortcodes.php`
- Per-block JS sources: `assets/js/blocks/` (outside this folder)
