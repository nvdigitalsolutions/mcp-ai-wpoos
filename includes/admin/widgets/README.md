# Admin Dashboard Widgets

## Purpose

Houses 8 dashboard widget PHP partials rendered by the Pro admin dashboard — providing analytics, cost tracking, token usage, queue health, and usage forecasting visualisations.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 7.4+ |
| **Loaded by** | `addons/pro/includes/admin/` dashboard rendering code via `require` / `include` |
| **Optional dependencies** | `WP_MCP_AI_Analytics_Engine`, `WP_MCP_AI_Cost_Tracking_Service`, `WP_MCP_AI_Dead_Letter_Queue`, `WP_MCP_AI_SLA_Manager` (gracefully degrade when absent) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `analytics-anomalies` (template) | `analytics-anomalies.php` | Pro dashboard analytics tab |
| `analytics-patterns` (template) | `analytics-patterns.php` | Pro dashboard analytics tab |
| `analytics-trends` (template) | `analytics-trends.php` | Pro dashboard analytics tab |
| `WP_MCP_AI_Dashboard_Widget_Queue_Health` | `class-wp-mcp-ai-dashboard-widget-queue-health.php` | WordPress admin dashboard (`wp_dashboard_setup`) |
| `cost-breakdown` (template) | `cost-breakdown.php` | Pro dashboard cost tab |
| `token-performance-stats` (template) | `token-performance-stats.php` | Pro dashboard performance tab |
| `token-usage-overview` (template) | `token-usage-overview.php` | Pro dashboard token-manager tab |
| `usage-forecast` (template) | `usage-forecast.php` | Pro dashboard analytics tab |

Seven files are `require`-style PHP template partials that receive a `$data` array. `class-wp-mcp-ai-dashboard-widget-queue-health.php` is a self-registering WordPress dashboard widget class.

## Inputs / Outputs / Neighbors

- **Reads from:** `WP_MCP_AI_Analytics_Engine`, `WP_MCP_AI_Cost_Tracking_Service`, `WP_MCP_AI_Cost_Calculator`, `WP_MCP_AI_Token_Limits`, `WP_MCP_AI_Dead_Letter_Queue`, `WP_MCP_AI_SLA_Manager`, WordPress user meta
- **Writes to:** inline scripts/styles for Chart.js visualisations
- **Upstream callers:** `addons/pro/includes/admin/` dashboard controller
- **Downstream collaborators:** `assets/js/admin/widgets/` (Chart.js scripts), `assets/css/admin/widgets/` (stylesheets)
- **Events fired:** WordPress dashboard widget registration (`wp_dashboard_setup`)
- **Events listened to:** none

## Conventions

- Template partials (non-class files) guard with `if ( ! defined( 'ABSPATH' ) ) { exit; }` and expect a `$data` array to be in scope.
- Widgets degrade gracefully when optional services are unavailable (showing "no data" states instead of errors).
- All output is escaped with `esc_html()`, `esc_attr()`, `esc_url()`, and `wp_kses_post()`.
- Chart.js scripts are enqueued with `wp_enqueue_script()` and localised with `wp_localize_script()` for i18n support.

## Tests

No dedicated PHPUnit tests. Widget rendering and data-fetching logic is tested indirectly through dashboard integration tests in `addons/pro/tests/`.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security
- [`.context/chat-ui.md`](../../.context/chat-ui.md) — admin UI patterns
