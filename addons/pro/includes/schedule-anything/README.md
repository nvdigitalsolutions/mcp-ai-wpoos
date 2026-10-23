# Schedule Anything (Pro side)

## Purpose

The Pro-side data layer for the Schedule Anything SaaS surface (Proposal 081):
the tenant toolkit adapter, the installable schedule-preset registry, and the
idempotency bookkeeping the SPA's presets/toolkits REST endpoints consume.

## Public Surface

- `WP_MCP_AI_SA_Toolkit_Adapter` — bridges the REST surface to the platform
  plugin's canonical `SA_Toolkit_Manager` slug/flag map; degrades to an empty
  catalogue when the platform plugin is absent.
- `WP_MCP_AI_SA_Schedule_Presets` — static preset catalogue, query/filter/
  pagination, override whitelisting (mass-assignment guard), deterministic
  install-idempotency keys, and the `create_schedule()` payload builder.

## Conventions

- Tenancy is the **current blog** (WordPress Multisite) — never accept a
  client-supplied tenant slug here (Proposal 081, D3).
- Toolkit slug source of truth is `SA_Toolkit_Manager::TOOLKIT_FLAGS` in the
  platform plugin. Do not duplicate the map.
- All installs go through `WP_MCP_AI_Pro_Schedule_Manager::create_schedule()`
  so validation has one authority.
- Preset overrides are whitelisted via `override_whitelist()` — unknown keys
  are silently dropped, never merged.
- Install is idempotent: key = `preset:{id}:h{sha256(overrides)}`, map capped
  at 100 entries in `wp_mcp_ai_sa_installs`.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 7.4+ |
| **Loaded by** | `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php` (`rest_schedule_anything` module) |
| **Optional dependencies** | Schedule Anything Platform plugin (for the toolkit map + tiers) |

## Inputs / Outputs / Neighbors

- **Reads from:** `SA_Toolkit_Manager` (platform), `wp_mcp_ai_settings`,
  `sa_tenant_meta`, `wp_mcp_ai_sa_installs`.
- **Writes to:** `wp_mcp_ai_settings` (toggles, via the platform manager),
  `wp_mcp_ai_sa_installs`, schedule records (via `WP_MCP_AI_Pro_Schedule_Manager`).
- **Upstream callers:** `addons/pro/includes/rest/class-wp-mcp-ai-pro-sa-rest-controller.php`,
  the Schedule Anything SPA (`addons/schedule-anything-spa`).
- **Downstream collaborators:** `WP_MCP_AI_Pro_Schedule_Manager`,
  `WP_MCP_AI_Pro_Schedule_REST_Controller` (shared capability seam
  `wp_mcp_ai_pro_schedule_rest_capability`).

## Context

Load `.context/tool-registry.md` (Pro REST conventions) and
`.context/security-checklist.md` (canonical envelope, two-gate sanitisation)
when editing this folder.
