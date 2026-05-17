# Pro Elementor

## Purpose

Elementor page-builder widgets for every Pro toolkit — e-commerce, social media, calendar booking, DJ management, financial planning, multilingual, AI tool builder, media toolkit, and the vehicle-cleaning estimator — providing the same toolkit surfaces that [`../blocks/`](../blocks/) exposes for Gutenberg.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ (see [`CLAUDE.md`](../../../../CLAUDE.md)) |
| **Loaded by** | `WP_MCP_AI_Pro_Toolkit_Integration::register_elementor_widgets()` in [`../class-wp-mcp-ai-pro-toolkit-integration.php`](../class-wp-mcp-ai-pro-toolkit-integration.php) — `require_once`s each widget file and calls `$widgets_manager->register()` on the `elementor/widgets/register` hook |
| **Optional dependencies** | **Elementor is hard-gated.** The parent integration registers nothing here unless `did_action( 'elementor/loaded' )` returns truthy at boot; each widget extends `\Elementor\Widget_Base`, so files in this folder MUST NOT be required when Elementor is inactive. The underlying Pro toolkit also has to be enabled in `wp_mcp_ai_settings` for the widget to produce content. |

## Public Surface

Widgets are internal to Elementor's widget manager — other PHP code MUST NOT instantiate them directly. The contract is the **registered widget name** that Elementor exposes in the editor panel.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Ecommerce_Products_Widget`, `…_Search_Widget`, `…_Orders_Widget` | `class-wp-mcp-ai-ecommerce-*-widget.php` | Elementor widget manager (E-commerce toolkit) |
| `WP_MCP_AI_Social_Calendar_Widget`, `WP_MCP_AI_Social_Templates_Widget` | `class-wp-mcp-ai-social-*-widget.php` | Social Media toolkit |
| `WP_MCP_AI_Calendar_Booking_Widget`, `…_Services_Widget`, `…_Staff_Widget` | `class-wp-mcp-ai-calendar-*-widget.php` | Calendar Booking toolkit |
| `WP_MCP_AI_DJ_Equipment_Widget`, `WP_MCP_AI_DJ_Packages_Widget` | `class-wp-mcp-ai-dj-*-widget.php` | DJ Management toolkit |
| `WP_MCP_AI_Financial_Budget_Widget`, `WP_MCP_AI_Financial_Goals_Widget` | `class-wp-mcp-ai-financial-*-widget.php` | Financial Planner toolkit |
| `WP_MCP_AI_Multilingual_Translation_Memory_Widget`, `…_Glossaries_Widget` | `class-wp-mcp-ai-multilingual-*-widget.php` | Multilingual toolkit |
| `WP_MCP_AI_AI_Tool_Builder_Templates_Widget`, `…_Schemas_Widget` | `class-wp-mcp-ai-ai-tool-builder-*-widget.php` | AI Tool Builder toolkit |
| `WP_MCP_AI_Media_Templates_Widget`, `WP_MCP_AI_Media_Collections_Widget` | `class-wp-mcp-ai-media-*-widget.php` | Media toolkit |
| `WP_MCP_AI_Vehicle_Cleaning_Estimator_Widget` | `class-wp-mcp-ai-vehicle-cleaning-estimator-widget.php` | Vehicle Cleaning Estimator |

The authoritative widget catalogue is the `$widget_files` array in `WP_MCP_AI_Pro_Toolkit_Integration::register_elementor_widgets()` — new widgets MUST be added there to load.

## Inputs / Outputs / Neighbors

- **Reads from:** Elementor `$settings` controls, per-toolkit options (`wp_mcp_ai_settings`, `wp_mcp_ai_calendar_booking_settings`, …), Pro CPT data (services, staff, products, packages, budgets, …), REST endpoints exposed by [`../rest/`](../rest/).
- **Writes to:** rendered HTML in the page body; widgets enqueue their own scripts and styles through the Elementor enqueue lifecycle.
- **Upstream callers:** `WP_MCP_AI_Pro_Toolkit_Integration::register_elementor_widgets()` (the only loader) on the `elementor/widgets/register` hook, plus `elementor/elements/categories_registered` for the shared category.
- **Downstream collaborators:** `WP_MCP_AI_Pro_Toolkit_Shortcodes` (most widgets delegate to the matching shortcode for the actual render), [`../services/`](../services/), the per-toolkit CPT folders under [`../`](../), Base [`includes/elementor/`](../../../../includes/elementor/) for shared trait/asset conventions.
- **Events fired:** Elementor control hooks only; no NV oOS-specific actions/filters are emitted from this folder.
- **Events listened to:** `elementor/loaded` (via the parent integration), `elementor/widgets/register`, `elementor/elements/categories_registered`, `wp_enqueue_scripts`.

## Conventions

- Every widget file MUST begin with an Elementor guard so it is safe to `require_once` even when Elementor was uninstalled between hook fires — i.e. `if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) { return; }` at the top of the file.
- Widget class names follow `WP_MCP_AI_<Toolkit>_<Component>_Widget`; the filename is the kebab-case form. The auto-mapper in `WP_MCP_AI_Pro_Toolkit_Integration::get_class_name_from_file()` relies on this exact convention — diverge and the widget will silently fail to register.
- Widgets MUST be registered under the shared `mcp-ai-toolkits` Elementor category (set in `WP_MCP_AI_Pro_Toolkit_Integration::register_elementor_category()`).
- Each widget MUST gate its render on its toolkit flag in `wp_mcp_ai_settings` — the bootstrap registers the widget regardless of flag state, so the widget is responsible for showing a friendly placeholder when its toolkit is disabled.
- Output escaping is the widget's responsibility. Re-escape every `$settings[…]` value used in HTML, even if Elementor sanitised it on save. See [`.context/security-checklist.md`](../../../../.context/security-checklist.md).
- Do not duplicate Base widget logic — if a chat/assistant surface already exists in [`includes/elementor/`](../../../../includes/elementor/), reuse the shortcode it wraps.

## Tests

These Pro widgets do not yet have dedicated PHPUnit suites. The shared Elementor guards in the Base suite cover the registration path:

```bash
vendor/bin/phpunit tests/test-elementor-widget-loading.php
vendor/bin/phpunit tests/test-elementor-widget-rendering.php
vendor/bin/phpunit tests/test-elementor-widget-registration-error-handling.php
vendor/bin/phpunit tests/test-elementor-output-buffering.php
```

Toolkit-level integration tests under [`addons/pro/tests/`](../../tests/) (e.g. `test-media-toolkit-integration.php`, `test-cross-toolkit-mounts.php`) exercise the shortcodes that each widget delegates to.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping inside `render()` callbacks (always)
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — toolkit-flag and `did_action( 'elementor/loaded' )` patterns
- [`.context/chat-ui.md`](../../../../.context/chat-ui.md) — shared front-end conventions
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP compat, two-gate sanitisation

## See Also

- Loader / coordinator: [`../class-wp-mcp-ai-pro-toolkit-integration.php`](../class-wp-mcp-ai-pro-toolkit-integration.php)
- Shortcode bridge: [`../class-wp-mcp-ai-pro-toolkit-shortcodes.php`](../class-wp-mcp-ai-pro-toolkit-shortcodes.php)
- Sibling Gutenberg surface: [`../blocks/`](../blocks/)
- Base counterpart: [`includes/elementor/`](../../../../includes/elementor/) — chat, assistant, dashboard, performance widgets
