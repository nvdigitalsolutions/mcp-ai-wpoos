<?php
/**
 * Pro Toolkit MCP Server Settings Enhancement — Remaining TODO Items
 *
 * This file documents the remaining work for the "enhance all pro toolkit
 * MCP server / settings pages like the CRM enhancements" initiative.
 *
 * ✅ COMPLETED (Phases A.1, A.4, A.5, C.1, C.2)
 * ─────────────────────────────────────────────────────────────
 * A.1 — Architect Agent: Converted standalone → Toolkit_Settings_Base.
 *       MCP Server tab auto-appears.  4 tools.  toolkit_slug='architect_agent'.
 *
 * A.4 — ECA Management: Switched CPT_Settings_Page_Base → Toolkit_Settings_Base.
 *       MCP Server tab auto-appears.  35 tools.  toolkit_slug='eca'.
 *
 * A.5 — Law Firm: Switched CPT_Settings_Page_Base → Toolkit_Settings_Base.
 *       MCP Server tab auto-appears.  62 tools.  toolkit_slug='law_firm'.
 *
 * C.1 — Cloudways: Created WP_MCP_AI_Cloudways_MCP_Server (56 tools).
 *       Registered in mcp-servers-init.php Phase 6.  Settings already on base.
 *
 * C.2 — Comic Creation: Created WP_MCP_AI_Comic_Creation_MCP_Server (12 tools).
 *       Upgraded settings CPT_Settings_Page_Base → Toolkit_Settings_Base.
 *       Registered in mcp-servers-init.php Phase 6.
 *
 * 📋 TODO — REMAINING (Phases A.2, A.3, A.6)
 * ─────────────────────────────────────────────────────────────
 */

// ═══════════════════════════════════════════════════════════════════════════
// A.2 — Site Creator Toolkit
// ═══════════════════════════════════════════════════════════════════════════
//
// STATUS: MCP server exists (site-creator, 27 tools), but settings page is
// standalone with 5 submenu pages — no MCP Server tab accessible.
//
// FILE: addons/pro/includes/admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php
// MCP:   addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-site-creator-mcp-server.php
//
// CHALLENGE:
// The Site Creator has a top-level admin menu ('nvoos-site-creator') with 5
// separate submenu pages, each with its own render method:
//
//   • Overview          → render_settings_page()    [L113]
//   • Tools             → render_tools_page()       [L391]
//   • Templates         → render_templates_page()   [L465]
//   • Research & Add    → render_research_page()    [L534]
//   • Consolidate & Add → render_consolidate_page() [L626]
//
// The Consolidate & Add page includes a form with blueprint import (file upload)
// and chat UI integration via do_shortcode('[nvoos-ai-chat ...]').
//
// APPROACH OPTIONS:
//
// Option 1 — Add a 6th submenu page for MCP Server (SAFEST, low effort):
//   Add 'add_submenu_page()' for an MCP Server page under nvoos-site-creator.
//   On that page, render the MCP server config form (copy the pattern from
//   WP_MCP_AI_Toolkit_Settings_Base::render_mcp_server_tab()).
//   Post to admin-post.php action 'wp_mcp_ai_save_toolkit_mcp_server'.
//   NONCE: 'wp_mcp_ai_save_toolkit_mcp_server_site-creator'.
//
// Option 2 — Collapse 5 submenus into Toolkit_Settings_Base tabs (HIGH effort):
//   Convert class to extend Toolkit_Settings_Base.
//   Map existing pages to tabs:
//     Overview         → render_overview_tab()
//     Configuration    → render_permissions_form() (8 checkboxes)
//     Tools            → get_tools_list() (27 tools)
//     Research & Add   → render_research_tab() / custom tab
//     Consolidate & Add → custom tab with import form + chat shortcode
//     MCP Server       → auto from base class
//   toolkit_slug = 'site_creator' (auto-resolves to 'site-creator' MCP server).
//   menu placement: override parent_slug or keep top-level.
//
// RECOMMENDED: Option 1 first (low risk, delivers MCP Server tab quickly),
// then Option 2 as a follow-up refactor.

// ═══════════════════════════════════════════════════════════════════════════
// A.3 — Extended Cognition Toolkit
// ═══════════════════════════════════════════════════════════════════════════
//
// STATUS: MCP server exists (extended-cognition, 7 tools), but settings page
// is standalone under Settings→Ext. Cognition — no MCP Server tab.
//
// FILE: addons/pro/includes/admin/class-wp-mcp-ai-ext-cog-settings.php
// MCP:   addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-extended-cognition-mcp-server.php
//
// CHALLENGE:
// 1. Settings stored in global 'wp_mcp_ai_settings' option with 'ext_cog_' prefix,
//    not a dedicated option row.  The sanitize callback merges into the global
//    option (preserving other non-ext-cog keys).
//
// 2. Class uses static methods (::init(), ::add_menu(), ::register_settings(),
//    ::render_page(), ::render_checkbox(), ::render_number(), ::render_select()).
//
// 3. Menu is under Settings menu (add_options_page), not under nvoos-pro-dashboard.
//
// 4. 9 settings fields across 5 sections:
//    General:   enable_toolkit, guest_access, gdpr_consent
//    Sensors:   camera, microphone, screen, motion (4 checkboxes)
//    Storage:   store_captures, retention_days (number), max_capture_size_kb (number)
//    Limits:    rate_limit (number)
//    Model:     vision_model (select)
//
// Also: ext_cog_allowed_roles (checkbox group, not rendered but sanitized).
//
// APPROACH:
//
// 1. Create new class extending WP_MCP_AI_Toolkit_Settings_Base:
//    - toolkit_slug = 'extended_cognition' (auto-resolves to 'extended-cognition')
//    - option_name  = 'wp_mcp_ai_settings' (reuse global option)
//    - page_slug    = 'nvoos-extended-cognition-toolkit'
//    - icon         = 'dashicons-visibility'
//
// 2. Override register_settings() to:
//    - Call the existing ext_cog register_settings() logic (register sections/fields)
//    - Point settings_fields/do_settings_sections at the global option
//    - NOTE: The base class registers '$this->option_name . '_group''.
//      Since option_name = 'wp_mcp_ai_settings', the group would be
//      'wp_mcp_ai_settings_group'.  The existing code uses
//      'wp_mcp_ai_ext_cog_settings_group'.  Must reconcile.
//
//    Option A: Change SETTINGS_GROUP to 'wp_mcp_ai_settings_group'.
//    Option B: Override render_configuration_form() to use ext_cog's group.
//
// 3. Override sanitize_settings() to keep the existing merge-into-global logic.
//
// 4. Override render_configuration_tab() to render the 9 fields inline
//    (or keep using do_settings_sections).
//
// 5. Move menu from add_options_page → nvoos-pro-dashboard.
//
// 6. Deprecate/remove the old WP_MCP_AI_Ext_Cog_Settings class.
//    Update extended-cognition-toolkit-init.php to instantiate the new class.
//
// RISKS: The global option merge is fragile.  Any change to the sanitize
// callback could corrupt wp_mcp_ai_settings.  Thorough testing required.

// ═══════════════════════════════════════════════════════════════════════════
// A.6 — Healthcare Suite (3 sub-toolkits) — DEFERRED
// ═══════════════════════════════════════════════════════════════════════════
//
// STATUS: 3 MCP servers exist but no centralized toolkit settings page.
//
// FILES:
//   MCP: servers/class-wp-mcp-ai-healthcare-mcp-server.php
//   MCP: servers/class-wp-mcp-ai-healthcare-imaging-mcp-server.php
//   MCP: servers/class-wp-mcp-ai-healthcare-wellness-mcp-server.php
//   Admin: (multiple CPT/dashboard pages, no unified settings)
//
// CHALLENGE:
// Healthcare has the most complex architecture — 6+ CPTs, 3 separate MCP
// servers, dashboards, per-CPT pages.  Creating a unified settings page
// would require consolidating configuration from multiple sources.
//
// RECOMMENDED: Defer until A.2 and A.3 are complete; the patterns established
// there will inform the Healthcare approach.

// ═══════════════════════════════════════════════════════════════════════════
// Phase B — MCP Server Tab Enhancements (quick wins for all 28 toolkits)
// ═══════════════════════════════════════════════════════════════════════════
//
// These enhance the existing MCP Server tab in WP_MCP_AI_Toolkit_Settings_Base:
//
// B.1 — Server Status Indicator
//   Add enable/disable badge + "Test Connection" button + last activity timestamp.
//   Location: render_mcp_server_tab() in class-wp-mcp-ai-toolkit-settings-base.php (L775+).
//
// B.2 — Token/Credential Management (Inline)
//   Show active token count, "Generate Token" form, token list with revoke.
//   Reuse: WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page::render_credentials_panel().
//
// B.3 — Tools Allowlist UX
//   Add "Select All / Deselect All", search filter, tool count badge.
//
// B.4 — Rate Limit Visual Indicators
//   Show global default values.  Add human-readable labels.
//
// B.5 — MCP Endpoint Card
//   Add "Copy URL" button, curl snippet, well-known link.
//
// B.6 — Health Check Summary
//   Liveness, tool count, rate limit status, recent audit entries.

// ═══════════════════════════════════════════════════════════════════════════
// Phase D — Global MCP Dashboard Enhancements
// ═══════════════════════════════════════════════════════════════════════════
//
// D.1 — Dashboard Widget: server count, health summary, recent errors.
// D.2 — Bulk Actions: Enable/Disable all, Clear all audit logs.
// D.3 — Export/Import: Server config as JSON.
// D.4 — Discovery Tab: Pretty-print /.well-known/mcp with syntax highlighting.

// ═══════════════════════════════════════════════════════════════════════════
// Phase E — Shared Infrastructure Improvements
// ═══════════════════════════════════════════════════════════════════════════
//
// E.1 — Externalize CSS/JS: Move inline styles to shared CSS file.
// E.2 — Phase/Roadmap Display: get_toolkit_phase(), get_toolkit_status().
// E.3 — Menu Organization: Group toolkits by phase/tier.
// E.4 — Tab Hooks: wp_mcp_ai_toolkit_settings_tabs filter.

// ═══════════════════════════════════════════════════════════════════════════
// Phase F — Security & Compliance
// ═══════════════════════════════════════════════════════════════════════════
//
// F.1 — Audit nonce/authorization patterns.
// F.2 — Token scoping (read-only, tools-only, specific-tool-allowlist).
// F.3 — Configuration change audit trail.
