# elementor/

## Purpose

Elementor page-builder widgets that surface NV oOS chat, dashboard tiles, performance monitors, professional/assistant pickers, and quick-action panels inside Elementor-rendered pages.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ (see [`CLAUDE.md`](../../CLAUDE.md)) |
| **Loaded by** | `includes/class-wp-mcp-ai-elementor-integration.php` — `maybe_init()` defers to the `elementor/loaded` action, then `register_widget()` `require_once`s each widget file from this folder |
| **Optional dependencies** | Elementor (`\Elementor\Plugin`, `\Elementor\Widget_Base`). Hard-gated; nothing in this folder loads when Elementor is inactive. |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Elementor_Text_Formatting` (trait) | `trait-wp-mcp-ai-elementor-text-formatting.php` | every widget in this folder |
| `WP_MCP_AI_Elementor_Widget` | `class-wp-mcp-ai-elementor-widget.php` | main chat widget, registered as `wp_mcp_ai_chat` |
| `WP_MCP_AI_Elementor_Chat_Bubble_Widget` | `class-wp-mcp-ai-elementor-chat-bubble-widget.php` | floating chat bubble |
| `WP_MCP_AI_Elementor_Chat_Intro_Widget`, `..._Chat_Faq_Widget`, `..._Chat_Usage_Timer_Widget` | `class-wp-mcp-ai-elementor-chat-*.php` | chat-page composition |
| `WP_MCP_AI_Elementor_Assistant_*_Widget` | `class-wp-mcp-ai-elementor-assistant-*.php` | assistant builder UIs (base knowledge, defaults, prompt shortcuts, tools) |
| `WP_MCP_AI_Elementor_Dashboard_*_Widget` | `class-wp-mcp-ai-elementor-dashboard-*.php` | dashboard tiles (activity feed, provider links, theme preview, tool matrix, user capability/chats/files) |
| `WP_MCP_AI_Elementor_Performance_*_Widget` | `class-wp-mcp-ai-elementor-performance-*.php` | performance metrics, recommendations, test runner, trends |
| `WP_MCP_AI_Elementor_Professional_Selector_Widget`, `..._Quick_Actions_Widget`, `..._Scheduled_Result_Widget`, `..._System_Health_Status_Widget`, `..._Telegram_Login_Widget`, `..._Test_Results_Table_Widget` | corresponding `class-*.php` | misc front-end + admin tiles |

All widgets are internal to Elementor's widget manager — other PHP code should not instantiate them directly. The trait is intentionally shared and may be reused by new widgets in this folder.

## Inputs / Outputs / Neighbors

- **Reads from:** Elementor `$settings` controls, NV oOS options (`wp_mcp_ai_settings`), assistant CPT data, performance/health services, user capability info.
- **Writes to:** Rendered HTML in the page body; some widgets enqueue scripts/styles from `assets/js/` and `assets/css/`.
- **Upstream callers:** `WP_MCP_AI_Elementor_Integration::register_widget()` (the only loader), Elementor widget manager.
- **Downstream collaborators:** `includes/class-wp-mcp-ai-shortcode.php` (the chat widget renders the same shortcode), `includes/services/` (performance + tool data), `includes/professions/` (professional selector), assistant CPT, REST endpoints under `/wp-json/mcp-ai/v1/` consumed by enqueued JS.
- **Events fired:** Widget-level Elementor controls only — no NV oOS hooks are emitted from this folder.
- **Events listened to:** `elementor/loaded`, `elementor/widgets/register` (via the parent integration), `wp_enqueue_scripts`.

## Conventions

- Every widget file begins with `if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) { return; }` so the file is safe to `require_once` even if Elementor is uninstalled between hook fires.
- Widget slug = lower-case underscore form of the class name (e.g. `wp_mcp_ai_chat`).
- Output buffering is controlled by `WP_MCP_AI_Elementor_Integration::is_elementor_editor_page_load()` — widgets must not start their own buffers (see `tests/test-elementor-output-buffering.php`).
- Shared rendering logic for prose/markdown belongs in `WP_MCP_AI_Elementor_Text_Formatting`, not in individual widgets.

## Tests

```bash
vendor/bin/phpunit tests/test-elementor-widget-loading.php
vendor/bin/phpunit tests/test-elementor-widget-rendering.php
vendor/bin/phpunit tests/test-elementor-widget-registration-error-handling.php
vendor/bin/phpunit tests/test-elementor-widget-script-dependencies.php
vendor/bin/phpunit tests/test-elementor-output-buffering.php
vendor/bin/phpunit tests/test-elementor-performance-test-runner-error-handling.php
vendor/bin/phpunit tests/test-elementor-performance-trends-chart.php
vendor/bin/phpunit tests/test-elementor-chat-usage-timer-widget.php
```

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — escaping inside `render()` callbacks (always)
- [`.context/chat-ui.md`](../../.context/chat-ui.md) — shared front-end chat contract
- [`.context/rest-api.md`](../../.context/rest-api.md) — endpoints called by widget JS

## See Also

- Coordinator: `includes/class-wp-mcp-ai-elementor-integration.php`
- Sibling renderers: [`includes/blocks/`](../blocks/) (Gutenberg equivalents), [`includes/renderers/`](../renderers/)
- Pro widget catalogue: `addons/pro/includes/elementor/`
