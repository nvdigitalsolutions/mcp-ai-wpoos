# Admin Sections

## Purpose

Hosts one concrete `WP_MCP_AI_Settings_Section` implementation per settings
tab (or logical group of settings). Each class defines field schemas, per-field
sanitization, form rendering, and input validation for its own slice of the
`wp_mcp_ai_settings` option.

## Tier

| | |
|---|---|
| **Distribution** | Both (base sections here; Pro addon adds extra sections through the same registry) |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/settings-dashboard-init.php` |
| **Depends on** | `abstract-wp-mcp-ai-settings-section.php` (base class, same folder) |

## Public Surface

All concrete section classes are treated as internal to this folder.
External callers interact with sections through the `WP_MCP_AI_Settings_Registry`
— see `includes/admin/README.md` for the contract.

| Symbol | File | Notes |
|---|---|---|
| `WP_MCP_AI_Settings_Section` (abstract) | `abstract-wp-mcp-ai-settings-section.php` | Base; referenced from tests and Pro sections |
| `WP_MCP_AI_Section_Security` | `class-wp-mcp-ai-section-security.php` | Security Center — five sub-tabs (overview, access, network, ai_safety, audit). Renders posture score card, IP dry-run, header preview, capability fence table, snapshot/restore, self-test. |

All other `class-wp-mcp-ai-section-*.php` files are internal.

## Sub-tab pattern

Sections that need sub-tabs implement `get_subtab_groups()` and
`get_active_subtab()` (inherited helpers exist in the abstract base).
The security section overrides `render_wrapper()` to give the `overview`
sub-tab a posture-only layout (no form save) while standard sub-tabs use the
normal `<table class="form-table">` flow.

See `class-wp-mcp-ai-section-providers.php` for another example of the
sub-tab pattern.

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` option, `WP_MCP_AI_Tool_Registry` (capability fence table), `WP_MCP_AI_Security_Posture` service (`includes/security/`).
- **Writes to:** `wp_mcp_ai_settings` option (via registry sanitize/save cycle).
- **Upstream callers:** `settings-dashboard-init.php` (registration), `WP_MCP_AI_Settings_Dashboard` (rendering).
- **Downstream collaborators:** `includes/security/` (posture service), `includes/rest/class-wp-mcp-ai-rest-security-center-controller.php` (AJAX endpoints used by Security Center JS).

## Conventions

- Every new section MUST extend `WP_MCP_AI_Settings_Section` and register via `settings-dashboard-init.php`.
- Option keys must use the `wp_mcp_ai_*` prefix.
- `get_fields()` returns the canonical field schema; `sanitize()` from the base class uses it automatically.
- Sub-tab field lists in `get_subtab_groups()` must be disjoint and together must equal the complete set of field keys returned by `get_fields()` (excluding `_heading_*` pseudo-fields and the overview's empty list).
- Never call `wp_enqueue_*` from a section class; use `WP_MCP_AI_Admin_Scripts` instead.

## Also Load

- [`.context/conventions.md`](../../../.context/conventions.md) — naming + style
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — nonces, capability gates, escaping
- [`.context/pro-vs-base.md`](../../../.context/pro-vs-base.md) — which sections/fields are Pro-only
