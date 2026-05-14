# Phase 7 — Dedicated Pro Admin UI for Toolkit MCP Servers

**ADR reference:** `docs/ADR_002_toolkit_mcp_servers.md`
**Status:** Planning
**Date:** 2026-05-13

---

## Background & Rationale

All 7 backend phases of Toolkit MCP Servers are complete (26 servers, Phase 3d credentials,
Phase 4 audit trail, Phase 5 metabox + observability card, Phase 6 discovery endpoint).
Administration is currently split across:

- `/mcp-server` Pro slash command (enable/disable/list/tools)
- WP-CLI `mcp-server` command (token-generate/list/revoke)
- REST `/mcp-ai-pro/v1/mcp/{slug}/token` and `/mcp-ai-pro/v1/mcp-audit`
- A placeholder `tab=mcp_servers` link in the Orchestration dashboard that currently points nowhere

A dedicated admin page consolidates this into a single discoverable surface — exactly what
`WP_MCP_AI_Pro_Schedule_Manager_Page` and the Workflow Builder do for their features.

---

## Industry-Standard UI Patterns

Surveyed from Claude Desktop, Cline, and Continue:

| Area | Best-practice pattern |
|---|---|
| Server list | Table/card per server with health pill (Enabled/Disabled), tier badge, tool count, last-activity timestamp, inline action buttons |
| Enable/Disable | Toggle in list row + confirmation dialog; action logged in audit ring |
| Credentials | Tokens masked by default; generate → one-time secret reveal modal (copy + "I've saved it" gate); rotate and revoke behind confirmation |
| Per-server tool toggles | Checkbox list inside per-server detail; "Select all / none" affordance; prerequisites noted |
| Audit log | Timeline/table filtered by server, consumer, action, date range; CSV and JSON export (client-side) |
| Observability | Inline sparklines + counters for request volume, error rate, last call latency; link-out to OTel dashboard |
| Discovery | Pretty-printed `/.well-known/mcp` document with raw/copy link |
| Confirmation dialogs | Required for destructive actions (revoke token, disable server, clear audit); optional reason captured for audit |
| Documentation | Info icons, hover tooltips, link-out to `docs/mcp-servers.md` and `docs/features/toolkit-mcp-servers.md` |

---

## How This Fits the Existing Codebase

| Existing surface | Keep? | How the new page relates |
|---|---|---|
| `WP_MCP_AI_Pro_Metabox_Toolkit_MCP_Servers` (assistant edit screen) | ✅ | New page links to it; not replaced |
| `WP_MCP_AI_Pro_Toolkit_MCP_Observability_Card` (Orchestration tab) | ✅ | Card gets a "Manage →" link pointing at new page |
| `tab=mcp_servers` placeholder link | ❌ | Replaced — redirect/repoint to new page slug |
| `/mcp-server` slash command | ✅ | Same option `wp_mcp_ai_toolkit_mcp_server_{slug}`; Help tab shows CLI equivalents |
| REST `/mcp-ai-pro/v1/mcp/{slug}/token` + `/mcp-ai-pro/v1/mcp-audit` | ✅ | New page's JS calls these directly via `wp.apiFetch` |
| `/.well-known/mcp` | ✅ | Rendered in the Discovery tab |

**Net new backend:** zero new REST routes, zero new DB tables, zero new options. One thin
`admin-post` handler for the enable/disable toggle (for WP-form-redirect parity with the
slash command).

---

## New Files

```
addons/pro/includes/admin/
  class-wp-mcp-ai-pro-toolkit-mcp-servers-page.php   ← main page class

assets/css/
  pro-toolkit-mcp-servers.css                        ← page-scoped styles

assets/js/
  pro-toolkit-mcp-servers.js                         ← vanilla JS + wp.apiFetch

addons/pro/tests/
  test-pro-toolkit-mcp-servers-page.php               ← PHPUnit registration + cap tests
```

Registration wire-up: add the class `require` + `new` call in `addons/pro/mcp-ai-wpoos-pro.php`
adjacent to where `WP_MCP_AI_Pro_Schedule_Manager_Page` is instantiated.

---

## Page Class: `WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page`

```
PAGE_SLUG  = 'nvoos-pro-toolkit-mcp-servers'
parent     = 'nvoos-pro-dashboard'
priority   = 26 (matches Schedule Manager / Workflow Builder)
capability = manage_options
```

Mirrors the constructor / register_page / enqueue_assets / render_page pattern of
`WP_MCP_AI_Pro_Schedule_Manager_Page` exactly.

---

## Tab Structure (standard WP `nav-tab` pattern)

### Tab 1 — Servers *(default)*

A `WP_List_Table` subclass over `WP_MCP_AI_Toolkit_Server_Registry::get_instance()->all()`.

**Columns:** Status pill · Name + slug · Tier badge · Tool count · Tokens issued · Last
activity · Actions (Enable/Disable toggle, View detail, Copy endpoint URL)

**Toolbar:** text search · filter by Tier (1 / 2 / All) · filter by state
(Enabled / Disabled / All) · bulk enable/disable (with JS confirmation) · "Discovery
(`/.well-known/mcp`)" external link · "Refresh" button

### Tab 2 — Server Detail *(opened via row "View" link or `&server={slug}` query param)*

Per-server panel with five collapsible accordions:

1. **Overview** — name, description, tier, JSON-RPC endpoint URL (copy button), capabilities
   summary (tool count, resource count, prompt count), relevant filter hook names.
2. **Tools** — checkbox list matching what `/mcp-server tools {slug}` returns; Select all /
   none; read-only in v1 (per-tool toggling deferred to Phase 8 if needed).
3. **Credentials** — token table (prefix · label · created · last used); **Generate** (opens
   one-time-reveal modal: full `mcptk_` token shown once, copy button, "I've saved this"
   dismiss); **Rotate** (confirmation); **Revoke** (confirmation); all hit existing
   `GET/POST/DELETE /mcp-ai-pro/v1/mcp/{slug}/token` REST routes.
4. **Limits** — per-server rate / TPM cap inputs matching Phase 3c option; saved via
   admin-post form.
5. **Audit (filtered)** — last 50 entries from
   `/mcp-ai-pro/v1/mcp-audit?source={slug}`; "View all in Audit Log →" link.

### Tab 3 — Audit Log

Full cross-mount audit view backed by `GET /mcp-ai-pro/v1/mcp-audit` (ring buffer max 200).

**Filters:** server slug dropdown · consumer text · action type
(resources/read | prompts/get | tools/call | admin) · date range picker · limit selector

**Columns:** Timestamp · Server · Consumer · Surface · Result · Latency

**Footer actions:** Export CSV (client-side `Blob` download of current filtered set) ·
Export JSON · Clear log (destructive; confirmation required; `manage_options` only)

### Tab 4 — Discovery

- Pretty-printed JSON render of the current `/.well-known/mcp` document (fetched via
  `wp.apiFetch` on tab activation)
- "Open raw" link + "Copy JSON" button
- Inline note explaining the `wp_mcp_ai_well_known_mcp_document` filter for extending
  the manifest
- "Cache TTL" info box (reads `wp_mcp_ai_well_known_mcp_cache_max_age` filter default)

### Tab 5 — Help

- Quick-start summary
- **Slash-command equivalent** table (every UI action mapped to `/mcp-server` sub-command)
- **WP-CLI equivalent** table (every UI action mapped to `wp mcp-server` command)
- **Hooks reference** — all relevant filters/actions:
  `wp_mcp_ai_toolkit_mcp_audit_max_entries`,
  `wp_mcp_ai_well_known_mcp_document`,
  `wp_mcp_ai_well_known_mcp_cache_max_age`,
  `wp_mcp_ai_toolkit_mcp_cross_mount_read`,
  `wp_mcp_ai_toolkit_mcp_server_{slug}` option key pattern
- Link-out to `docs/mcp-servers.md` and `docs/features/toolkit-mcp-servers.md`

---

## JavaScript Stack Decision

**Vanilla JS + `wp.apiFetch`** — same approach as the Pro Schedule Manager page. No React, no
build step needed for v1. `wp.apiFetch` handles nonce injection automatically.

Key JS behaviours:

- **Server list:** live Enable/Disable toggle sends admin-post form via fetch and updates the
  row status pill without a full-page reload.
- **Detail drawer:** clicking "View" swaps the active tab to `server` and sets
  `&server={slug}` in the URL via `history.pushState`, making the URL bookmarkable.
- **Token generate:** after a successful POST, opens a modal overlay with the full token, a
  copy-to-clipboard button, and a "I've saved this token — close" button. The token variable
  is deleted on dismiss and never retained in the DOM.
- **Audit log:** filter form submits via `wp.apiFetch` and re-renders the table in-place;
  CSV/JSON export assembles a `Blob` from the current data array and triggers a download.

---

## Admin-post Handler (Enable/Disable Transport)

A thin `WP_MCP_AI_Pro_Toolkit_MCP_Server_Toggle` admin-post handler
(`action=wp_mcp_ai_toggle_toolkit_mcp_server`) for the enable/disable form:

```
capability  check: manage_options
nonce       check: wp_mcp_ai_pro_mcp_servers_toggle
sanitize:   sanitize_key( $_POST['server_slug'] ), (bool) intval( $_POST['enable'] )
effect:     update_option( "wp_mcp_ai_toolkit_mcp_server_{$slug}", $enable )
fires:      wp_mcp_ai_toolkit_mcp_server_toggled action (3 args: slug, bool, user_id)
redirect:   wp_safe_redirect back to Servers tab with ?toggled={slug}
```

The JS path sends this same form via fetch; the PHP path is the full-page-redirect fallback
for no-JS environments and WP-CLI parity.

---

## Security Checklist

Every render method: `current_user_can( 'manage_options' )` — exits if not met.

| Surface | Nonce name | Verification call |
|---|---|---|
| Enable/Disable admin-post form | `wp_mcp_ai_pro_mcp_servers_toggle` | `check_admin_referer()` |
| Per-server limits form | `wp_mcp_ai_pro_mcp_servers_limits_{slug}` | `check_admin_referer()` |
| Token generate/revoke (JS → REST) | handled by `wp.apiFetch` + `X-WP-Nonce` header | existing REST `permission_callback` |
| Audit export (client-side) | no server round-trip; data already fetched under auth | n/a |
| Clear audit log | `wp_mcp_ai_pro_mcp_servers_clear_audit` | `check_admin_referer()` |

All output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_json_encode()`.
All input: `sanitize_key()` on slug, `absint()` on limits, `sanitize_text_field()` on token label.
Token secret: only ever present in the REST response body at generation time; page JS shows
it once in the modal and deletes the variable on dismiss.

---

## PHPUnit Coverage

Following the pattern of `test-phase5-toolkit-mcp-servers.php`:

1. Page class registers under `nvoos-pro-dashboard` with `manage_options` capability.
2. Page slug is `nvoos-pro-toolkit-mcp-servers`.
3. Un-privileged user (subscriber) cannot access any tab (render exits early).
4. Enable/Disable admin-post handler updates the correct option.
5. Enable/Disable admin-post handler rejects bad nonce (403 / die).
6. Enable/Disable admin-post handler fires `wp_mcp_ai_toolkit_mcp_server_toggled` action.

---

## Implementation Slices

Each slice is a small, independently reviewable PR:

| Slice | Scope | Acceptance criteria |
|---|---|---|
| **A** | Skeleton page class + Servers tab (read-only list) | Menu item appears; table renders; no mutations; `manage_options` gate |
| **B** | Enable/Disable toggle + Server detail tabs (Overview + Tools) | Toggle updates option; `/mcp-server enable` parity confirmed |
| **C** | Credentials accordion (generate / rotate / revoke + one-time reveal modal) | Full token shown once; REST routes exercised via `wp.apiFetch` |
| **D** | Per-server Limits form | Phase 3c option updated; nonce verified |
| **E** | Audit Log tab + filtered view + CSV/JSON export | Pulls from `/mcp-ai-pro/v1/mcp-audit`; exports work client-side |
| **F** | Discovery tab + Help tab | Pretty JSON render; all slash-command/WP-CLI equivalents listed |
| **G** | Cross-link cleanup | Observability card "Manage →" link updated; `tab=mcp_servers` placeholder removed; `docs/ADR_002_toolkit_mcp_servers.md` Phase 7 entry added; `docs/features/toolkit-mcp-servers.md` "Admin UI" section added |

---

## Open Decisions (confirm before Slice A)

| # | Question | Recommendation |
|---|---|---|
| 1 | **WP_List_Table vs custom table?** | `WP_List_Table` for v1 — free sorting + bulk actions; upgrade later without breaking URLs |
| 2 | **Token label field?** | Yes — a short text field at generate time (e.g. "Claude Desktop", "CI pipeline"); stored in token metadata |
| 3 | **Clear Audit Log button in Tab 3?** | Include behind a `manage_options`-only confirmation; fires a new `wp_mcp_ai_toolkit_mcp_admin_action` hook |
| 4 | **Sparklines / inline metrics in Tab 1?** | Defer to a future slice (requires aggregating the audit ring buffer); Tab 1 shows raw "last activity" timestamp for v1 |
