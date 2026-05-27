# Pro Settings Sections

## Purpose

Four WordPress settings section classes that inject Pro-only tabs — Performance Monitoring, Pro Integrations, Pro Providers, and Schedule Manager — into the Base `WP_MCP_AI_Settings_Registry` dashboard.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/mcp-ai-wpoos-pro.php` — each section is instantiated inside `wp_mcp_ai_pro_init()` and registered via `wp_mcp_ai_register_settings_section` |
| **Optional dependencies** | Each section gates itself: Performance requires `WP_MCP_AI_Performance_Reporter`; Pro Integrations references Mailjet/Brevo/Mailgun/Google Analytics config keys that may be empty; Pro Providers requires `WP_MCP_AI_Pro_Provider_Manager`; Schedule Manager requires `WP_MCP_AI_Pro_Schedule_Manager` |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Section_Performance` | `class-wp-mcp-ai-section-performance.php` | Base settings registry → Advanced tab |
| `WP_MCP_AI_Section_Pro_Integrations` | `class-wp-mcp-ai-section-pro-integrations.php` | Base settings registry → Tools tab, Connections subtab |
| `WP_MCP_AI_Section_Pro_Providers` | `class-wp-mcp-ai-section-pro-providers.php` | Base settings registry → Providers tab |
| `WP_MCP_AI_Section_Schedule_Manager` | `class-wp-mcp-ai-section-schedule-manager.php` | Base settings registry → Orchestration tab; also rendered standalone on `nvoos-pro-schedule-manager` page |

All four extend `WP_MCP_AI_Settings_Section` from Base [`includes/admin/`](../../../../../includes/admin/).  The settings registry calls `get_id()`, `get_title()`, `get_tab()`, `get_fields()`, `render()`, and `get_priority()` — this is the only contract external code depends on.

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings`, per-integration API key options, the Pro credential vault, the measurement event store (Performance), saved workflows and schedules (Schedule Manager), assistant CPTs, the Pro provider registry.
- **Writes to:** `wp_mcp_ai_settings` (via the Base settings save pipeline), transient cache for connection-test results, AJAX response envelopes.
- **Upstream callers:** Base `WP_MCP_AI_Settings_Registry` (admin menu and rendering), `admin-post.php` for settings save, WordPress `wp_ajax_*` hooks registered by each section.
- **Downstream collaborators:** [`../class-wp-mcp-ai-performance-reporter.php`](../class-wp-mcp-ai-performance-reporter.php), [`../../services/`](../../services/), [`../../providers/`](../../providers/), [`../../class-wp-mcp-ai-pro-schedule-manager.php`](../../class-wp-mcp-ai-pro-schedule-manager.php), Base [`includes/admin/`](../../../../../includes/admin/).
- **Events fired:** `wp_mcp_ai_performance_section_after_components` (Performance); per-section AJAX actions (`wp_ajax_wp_mcp_ai_run_performance_test`, `wp_ajax_wp_mcp_ai_sm_*`, etc.).
- **Events listened to:** `admin_enqueue_scripts`, `wp_ajax_*`.

## Conventions

- Every section MUST extend `WP_MCP_AI_Settings_Section` and register via `wp_mcp_ai_register_settings_section` — never call `add_settings_section()` directly.
- Sections that embed JavaScript (`Performance`, `Schedule_Manager`) MUST guard enqueue on their specific tab/page hook and pass a WordPress nonce via `wp_localize_script`.
- The Schedule Manager section doubles as a standalone page — it checks `$_GET['page']` for both the settings dashboard and the dedicated `nvoos-pro-schedule-manager` slug. Keep the dual rendering path in sync.
- AJAX handlers MUST verify the section-specific nonce and `manage_options` capability before touching data.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-performance-section-ajax.php
vendor/bin/phpunit addons/pro/tests/test-performance-section-health-status.php
vendor/bin/phpunit addons/pro/tests/test-performance-security-check.php
vendor/bin/phpunit addons/pro/tests/test-performance-security-fix.php
vendor/bin/phpunit addons/pro/tests/test-pro-schedule-toolkit-settings-registration.php
vendor/bin/phpunit addons/pro/tests/test-pro-schedule-research-page-action-ajax.php
vendor/bin/phpunit addons/pro/tests/test-pro-schedule-research-page-dry-run-ajax.php
```

Cross-cutting settings-registry coverage lives in the Base suite under [`tests/test-settings-dashboard.php`](../../../../../tests/test-settings-dashboard.php) and `tests/test-pro-dashboard-*.php`.

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — nonces, capability gates, escaping (always)
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro section registration vs Base sections
- [`.context/testing.md`](../../../../../.context/testing.md) — admin / AJAX test patterns
- [`CLAUDE.md`](../../../../../CLAUDE.md) — PHP-compat (8.1+) + two-gate sanitisation

## See Also

- Parent folder: [`addons/pro/includes/admin/`](../) — the Pro admin surface that owns the settings registry integration
- Base counterpart: [`includes/admin/sections/`](../../../../../includes/admin/sections/) — Base settings sections
- Pro bootstrap that registers these: [`addons/pro/mcp-ai-wpoos-pro.php`](../../../mcp-ai-wpoos-pro.php)
