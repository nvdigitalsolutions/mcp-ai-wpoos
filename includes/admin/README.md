# Admin

## Purpose

Hosts every `wp-admin` surface for NV oOS — settings pages, AJAX handlers, dashboard widgets, custom-column UI, and diagnostic screens — so that the front-end / REST tier stays free of WordPress admin concerns.

## Tier

| | |
|---|---|
| **Distribution** | Both (Base classes live here; Pro adds extra screens through the same registry) |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` (most classes are required under an `is_admin()` guard) |
| **Optional dependencies** | Elementor, JetEngine, WooCommerce, Auth0 — each integration screen no-ops when its dependency is missing |

## Public Surface

The classes below are referenced from outside `includes/admin/`. Everything else is internal.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Admin_Settings` | `class-wp-mcp-ai-admin-settings.php` | `includes/bootstrap/`, container `admin.settings` service |
| `WP_MCP_AI_Settings_Dashboard` | `class-wp-mcp-ai-settings-dashboard.php` | `settings-dashboard-init.php`, replaces legacy `Admin_Settings` when the dashboard flag is on |
| `WP_MCP_AI_Settings_Registry` | `class-wp-mcp-ai-settings-registry.php` | All section classes, Pro addon, tests |
| `WP_MCP_AI_Settings_Validator` | `class-wp-mcp-ai-settings-validator.php` | Sections, AJAX handlers |
| `WP_MCP_AI_Settings_Section` (abstract) | `sections/abstract-wp-mcp-ai-settings-section.php` | Every concrete section in `sections/` plus Pro sections |
| `WP_MCP_AI_Section_Security` | `sections/class-wp-mcp-ai-section-security.php` | **Security Center** — five sub-tabs (overview, access, network, ai\_safety, audit) including posture score, IP dry-run, header preview, AI safety controls, snapshot/restore |
| `WP_MCP_AI_Admin_AJAX_Handlers` | `class-wp-mcp-ai-admin-ajax-handlers.php` | `Admin_Settings`, `Settings_Dashboard`, Pro addon |
| `WP_MCP_AI_Admin_Scripts` | `class-wp-mcp-ai-admin-scripts.php` | Bootstrap (admin-only) |
| `WP_MCP_AI_Pro_Dashboard` / `_REST` / `_Helper` | `class-wp-mcp-ai-pro-dashboard*.php` | Bootstrap, Pro addon |
| `WP_MCP_AI_Settings_Section_*` (~22 sections) | `sections/class-wp-mcp-ai-section-*.php` | Registered via `settings-dashboard-init.php` |

Subfolders own their own internal surfaces:

- `sections/` — one class per settings tab, all extending `WP_MCP_AI_Settings_Section`.
- `widgets/` — `wp_add_dashboard_widget()` render callbacks (analytics, costs, queue health).
- `measurement/` — admin views over the `includes/measurement/` event store.

## Inputs / Outputs / Neighbors

- **Reads from:** the `wp_mcp_ai_settings` option, transients, post meta on `mcp_ai_assistant` / profession / team CPTs, the measurement event store, the compliance data in `includes/data/`.
- **Writes to:** the `wp_mcp_ai_settings` option (via `Settings_Registry`), CPT post meta, key-rotation transients, log options (`wp_mcp_ai_recent_errors`, `wp_mcp_ai_recent_activity`).
- **Upstream callers:** WordPress core (`admin_menu`, `admin_init`, `wp_ajax_*`), the Pro addon bootstrap.
- **Downstream collaborators:** `includes/services/`, `includes/repositories/`, `includes/tools/`, `includes/measurement/`, `includes/data/` (compliance), `includes/professions/`, `includes/integrations/`.
- **Events fired:** `wp_mcp_ai_settings_saved`, `wp_mcp_ai_admin_menu_registered`, `wp_mcp_ai_dashboard_widgets_registered`, AJAX-specific `wp_mcp_ai_ajax_*` actions per handler.
- **Events listened to:** `admin_menu`, `admin_init`, `admin_enqueue_scripts`, `admin_notices`, `wp_dashboard_setup`, every `wp_ajax_wp_mcp_ai_*` hook, and the `wp_mcp_ai_register_settings_section` filter so Pro can inject its own tabs.

## Conventions

- New settings tabs MUST extend `WP_MCP_AI_Settings_Section` and register through `settings-dashboard-init.php` — never call `add_settings_section()` directly here.
- AJAX endpoints belong in `class-wp-mcp-ai-admin-ajax-handlers.php` (or a dedicated `*-ajax.php` class) and must use the `wp_mcp_ai_*_nonce` family declared by `Admin_Scripts`.
- Screens that depend on optional plugins (Elementor / JetEngine / WooCommerce / Auth0) MUST short-circuit when the dependency is missing — see the existing `*-integration.php` classes for the pattern.
- The legacy `class-wp-mcp-ai-admin-settings.php` stays untouched for backwards compatibility; new work goes through the dashboard registry. Toggle order is documented in `README-SETTINGS-DASHBOARD.md`.
- Asset enqueueing flows through `WP_MCP_AI_Admin_Scripts` — do not call `wp_enqueue_*` directly from screen classes.

## Tests

Admin-side PHPUnit coverage lives at the `tests/` root, prefixed `test-admin-*` and `test-settings-*`:

```bash
vendor/bin/phpunit --filter '/^Test_Admin_|^Test_Settings_/'
```

Notable suites: `test-admin-settings.php`, `test-settings-dashboard.php`, `test-admin-ajax-handlers-registered.php`, `test-admin-cron-manager.php`, `test-pro-dashboard-*.php`, `test-onboarding-wizard.php`. AJAX coverage rules are documented in [`tests/AJAX_TESTS_README.md`](../../tests/AJAX_TESTS_README.md).

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — nonces, capability gates, escaping (always)
- [`.context/pro-vs-base.md`](../../.context/pro-vs-base.md) — which admin screens are Pro-only
- [`.context/testing.md`](../../.context/testing.md) — admin / AJAX test patterns
- [`CLAUDE.md`](../../CLAUDE.md) — PHP compat + tool patterns referenced from settings UI

## See Also

- [`README-SETTINGS-DASHBOARD.md`](README-SETTINGS-DASHBOARD.md) — deep-dive on the modular dashboard subsystem (registry, section base class, tab list)
- Sibling folders: [`../assistants/`](../assistants/), [`../tools/`](../tools/), [`../measurement/`](../measurement/), [`../integrations/`](../integrations/)
- Upstream: [`includes/bootstrap/loader.php`](../bootstrap/loader.php)
