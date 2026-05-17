# Pro Admin

## Purpose

Hosts every `wp-admin` surface that the Pro addon adds on top of Base — Pro Dashboard companions, toolkit settings pages, per-CPT settings/research/consolidate pages, skill manager, schedule manager, chat-channels menu, remote-connections UI, password vault, packages, webhook status, and the orchestration / agent command-center screens — so that Pro REST, tool, and service code stays free of `wp-admin` concerns.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ (see [`CLAUDE.md`](../../../../CLAUDE.md)) |
| **Loaded by** | `addons/pro/mcp-ai-wpoos-pro.php` and the per-toolkit `addons/pro/includes/*-toolkit-init.php` bootstraps — most page classes are `require_once`'d under an `is_admin()` and toolkit-flag guard, and instantiated on `admin_menu` |
| **Optional dependencies** | Each integration page short-circuits when its dependency is missing — Elementor (`did_action( 'elementor/loaded' )`), JetEngine (`function_exists( 'jet_engine' )`), WooCommerce (`class_exists( 'WooCommerce' )`), Rank Math, WPCode, Auth0, plus per-toolkit feature flags (`enable_*_toolkit`) read from the `wp_mcp_ai_settings` option |

## Public Surface

The classes below are referenced from outside `addons/pro/includes/admin/` (Pro bootstrap, Base dashboard registry, or tests). Everything else is internal to a single page or metabox.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Toolkit_Settings_Base` (abstract) | `class-wp-mcp-ai-toolkit-settings-base.php` | Every `*-toolkit-settings-page.php` and most `*-settings-page.php` classes in this folder |
| `WP_MCP_AI_CPT_Settings_Page_Base` (abstract) | `class-wp-mcp-ai-cpt-settings-page-base.php` | Per-CPT settings pages (architectural, ECA, event, financial-planner, image-production, regulatory-product, document-generation, …) |
| `WP_MCP_AI_Research_Add_Base` (abstract) | `class-wp-mcp-ai-research-add-base.php` | Every `*-research-page.php` ("Research & Add" screen) plus `trait-wp-mcp-ai-research-page-enhancements.php` and `trait-wp-mcp-ai-research-page-featured-image.php` |
| `WP_MCP_AI_Consolidate_Add_Base` (abstract) | `class-wp-mcp-ai-consolidate-add-base.php` | `*-consolidate-page.php` deduper screens (event, product, media, health-records) |
| `WP_MCP_AI_Pro_Remote_Sites_Admin` / `WP_MCP_AI_Pro_Metabox_Remote_Connections` | `class-wp-mcp-ai-pro-remote-sites-admin.php`, `class-wp-mcp-ai-pro-metabox-remote-connections.php` | Pro bootstrap, assistant edit screen, remote-connection AJAX/tests |
| `WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers` | `class-wp-mcp-ai-pro-metabox-toolkit-mcp-servers.php` | Pro bootstrap (assistant edit screen), `test-phase5-toolkit-mcp-servers.php` |
| `WP_MCP_AI_Pro_Schedule_Manager_Page` / `_Research_Page` / `_Toolkit_Settings_Page` | `class-wp-mcp-ai-pro-schedule-*.php` | Schedule toolkit init, schedule REST tests |
| `WP_MCP_AI_Chat_Channels_Menu` | `class-wp-mcp-ai-chat-channels-menu.php` | `chat-channels-toolkit-init.php`, `test-chat-channels-admin-pages.php` |
| `WP_MCP_AI_Pro_Packages_Settings_Page`, `WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page`, `WP_MCP_AI_Pro_Webhook_Status_Page`, `WP_MCP_AI_Pro_Workflow_Builder_Page`, `WP_MCP_AI_Pro_Agent_Command_Center`, `WP_MCP_AI_Orchestration_Dashboard` | matching `class-*.php` | Pro bootstrap (`admin_menu`) |
| `WP_MCP_AI_Pro_CPT_AI_Integration` | `class-wp-mcp-ai-pro-cpt-ai-integration.php` | Generic AI-Assistant metabox injection across Pro CPTs |
| `WP_MCP_AI_Skill_Manager_Admin_Page` / `_Research_Admin_Page` / `_Settings_Admin_Page` | `class-wp-mcp-ai-skill-*-page.php` | Skill toolkit init |
| Pro Settings sections — `WP_MCP_AI_Section_Performance`, `…_Pro_Integrations`, `…_Pro_Providers`, `…_Schedule_Manager` | `sections/class-wp-mcp-ai-section-*.php` | Base `WP_MCP_AI_Settings_Registry` (Pro injects through `wp_mcp_ai_register_settings_section`) |
| `WP_MCP_AI_Remote_Capabilities_Loader` | `remote-capabilities/class-wp-mcp-ai-remote-capabilities-loader.php` | Remote-connection capability registry |

Edit-screen metaboxes that live alongside their owning CPT (`class-wp-mcp-ai-architectural-*-metabox.php`, `class-wp-mcp-ai-event-metabox.php`, `class-wp-mcp-ai-project-metabox.php`, `class-wp-mcp-ai-task-metabox.php`) are internal to the page they ship with — generic Pro-CPT metaboxes live in [`../metaboxes/`](../metaboxes/) instead.

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings`, per-toolkit settings options (`wp_mcp_ai_healthcare_settings`, `wp_mcp_ai_pro_schedule_settings`, `wp_mcp_ai_ehr_connections`, `wp_mcp_ai_remote_sites`, …), Pro CPT post meta, the credential vault, the measurement event store, third-party service status feeds.
- **Writes to:** the matching options/post-meta keys, audit log entries through Pro service classes, transients for connection-test results, AJAX response envelopes.
- **Upstream callers:** WordPress core (`admin_menu`, `admin_init`, `admin_enqueue_scripts`, `add_meta_boxes`, `wp_ajax_*`), Pro bootstrap, per-toolkit init files.
- **Downstream collaborators:** [`../services/`](../services/), [`../data-stores/`](../data-stores/), [`../rest/`](../rest/), [`../vault/`](../vault/), [`../mcp-servers/`](../mcp-servers/), Base [`includes/admin/`](../../../../includes/admin/) (settings registry + Settings_Section base + AJAX handlers).
- **Events fired:** per-page `wp_mcp_ai_pro_*` hooks (e.g. `wp_mcp_ai_pro_schedule_settings_saved`, `wp_mcp_ai_remote_site_saved`), plus AJAX `wp_mcp_ai_pro_*_ajax_*` actions.
- **Events listened to:** `admin_menu`, `admin_init`, `admin_enqueue_scripts`, `add_meta_boxes`, `save_post_*`, `wp_ajax_wp_mcp_ai_pro_*`, and the Base `wp_mcp_ai_register_settings_section` filter (for the four entries under `sections/`).

## Conventions

- Toolkit settings pages MUST extend `WP_MCP_AI_Toolkit_Settings_Base`; per-CPT settings pages MUST extend `WP_MCP_AI_CPT_Settings_Page_Base`; "Research & Add" pages MUST extend `WP_MCP_AI_Research_Add_Base` (optionally composing the two `trait-wp-mcp-ai-research-page-*.php` traits) — do not subclass core `WP_List_Table` directly.
- Every page class MUST gate its registration on its toolkit flag in `wp_mcp_ai_settings` (e.g. `enable_healthcare_imaging`, `enable_crm_toolkit`) so Base-mode sites never see the menu entries.
- Optional-dependency screens MUST short-circuit when the dependency is missing: `did_action( 'elementor/loaded' )` for Elementor surfaces, `function_exists( 'jet_engine' )` for JetEngine ones, `class_exists( 'WooCommerce' )` for Woo ones. The page class — not the bootstrap — is responsible for the guard.
- Pro Settings tabs go through the Base `WP_MCP_AI_Settings_Registry` via the four classes in `sections/`. Do not register new tabs by calling `add_settings_section()` from here.
- AJAX endpoints follow the Base `WP_MCP_AI_Admin_AJAX_Handlers` envelope and use the `wp_mcp_ai_pro_*_nonce` family; never echo HTML from an AJAX handler.
- Topic-specific deep-dives belong in a sibling Markdown file (see [`README-REMOTE-CONNECTIONS.md`](README-REMOTE-CONNECTIONS.md) for the canonical example) — keep this README an index.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-remote-sites-admin.php
vendor/bin/phpunit addons/pro/tests/test-remote-site-manager.php
vendor/bin/phpunit addons/pro/tests/test-remote-connection-access-controls.php
vendor/bin/phpunit addons/pro/tests/test-pro-schedule-research-page-action-ajax.php
vendor/bin/phpunit addons/pro/tests/test-pro-toolkit-mcp-servers-page.php
vendor/bin/phpunit addons/pro/tests/test-phase5-toolkit-mcp-servers.php
vendor/bin/phpunit addons/pro/tests/test-pro-workflow-builder-ajax.php
vendor/bin/phpunit addons/pro/tests/test-password-vault-ajax.php
vendor/bin/phpunit addons/pro/tests/test-performance-section-ajax.php
vendor/bin/phpunit addons/pro/tests/test-cpt-settings-assistant-integration.php
vendor/bin/phpunit tests/test-chat-channels-admin-pages.php
vendor/bin/phpunit tests/test-architectural-design-submenu-registration.php
```

Cross-cutting admin coverage (Pro Dashboard, settings registry interop) lives in the Base suite under [`tests/`](../../../../tests/) — see `test-pro-dashboard-*.php`, `test-settings-dashboard.php`, and `tests/AJAX_TESTS_README.md`.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — nonces, capability gates, escaping (always)
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — toolkit-flag and dependency-guard patterns
- [`.context/testing.md`](../../../../.context/testing.md) — admin / AJAX test patterns
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat, two-gate sanitisation

## See Also

- Topic-specific sub-doc: [`README-REMOTE-CONNECTIONS.md`](README-REMOTE-CONNECTIONS.md) — full developer reference for the 24 remote-connection types
- Base counterpart: [`includes/admin/`](../../../../includes/admin/) — the settings registry, Settings_Section base class, and shared AJAX dispatcher
- Sibling Pro folders: [`../metaboxes/`](../metaboxes/), [`../rest/`](../rest/), [`../services/`](../services/), [`../data-stores/`](../data-stores/), [`../vault/`](../vault/), [`../mcp-servers/`](../mcp-servers/)
- Upstream loader: [`addons/pro/mcp-ai-wpoos-pro.php`](../../mcp-ai-wpoos-pro.php)
