# Tenant Isolation Subsystem

> Multi-tenant data isolation foundation for the NV oOS plugin.

## Purpose

Provides the foundation layer for tenant-scoped data access across all Pro toolkits. Centralises tenant context resolution, repository enforcement, tenant registry storage, scoped options, and feature-flag gating so that each toolkit can adopt isolation incrementally.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` (bottom of the loader chain) → `init.php` |
| **Optional dependencies** | none |

## Public Surface

| File | Class | Purpose |
|---|---|---|
| `class-wp-mcp-ai-tenant-context.php` | `WP_MCP_AI_Tenant_Context` | Singleton that resolves the current tenant from headers, user meta, assistant config, or multisite |
| `class-wp-mcp-ai-tenant-repository.php` | `WP_MCP_AI_Tenant_Repository` | Abstract base for all tenant-scoped data-access classes; provides `tenant_where()`, `tenant_meta_query()`, and `require_tenant()` |
| `class-wp-mcp-ai-tenant-database.php` | `WP_MCP_AI_Tenant_Database` | Schema manager for `mcp_ai_tenants` and `mcp_ai_tenant_user_map` tables via `dbDelta()` |
| `class-wp-mcp-ai-tenant-options.php` | `WP_MCP_AI_Tenant_Options` | Wraps `get_option`/`update_option` with automatic tenant-scoped key prefixing |
| `class-wp-mcp-ai-tenant-feature-flags.php` | `WP_MCP_AI_Tenant_Feature_Flags` | Global and per-toolkit feature flags for gradual rollout |
| `class-wp-mcp-ai-tenant-migration.php` | `WP_MCP_AI_Tenant_Migration` | Tenant data migration helpers |
| `class-wp-mcp-ai-tenant-cli-command.php` | `WP_MCP_AI_Tenant_CLI_Command` | WP-CLI commands (loaded only when `WP_CLI` is defined) |
| `init.php` | — | Bootstrap: loads all classes, creates tables, registers REST meta, adds admin columns |

## Inputs / Outputs / Neighbors

- **Reads from:** request headers, user meta, assistant config, multisite context (tenant resolution); custom tables `mcp_ai_tenants` / `mcp_ai_tenant_user_map`; feature-flag options.
- **Writes to:** custom tenant tables (via `dbDelta()`), tenant-scoped options.
- **Upstream callers:** `includes/bootstrap/loader.php`; `addons/pro/includes/tools/` (all 37 tenant-relevant toolkits — consumers of this subsystem).
- **Downstream collaborators:** `includes/admin/class-wp-mcp-ai-pro-database.php` (existing compliance tables — gains tenant columns in Phase 2).
- **Events fired:** none public.
- **Events listened to:** none public.

## Conventions

- **Fail closed.** Tenant resolution returns a `WP_Error` (code `tenant_not_resolved`) when no tenant matches — repositories must treat that as a hard stop, never a silent fallback.
- Repositories extend `WP_MCP_AI_Tenant_Repository` and route every query through `tenant_where()` / `tenant_meta_query()` — no raw tenant ID concatenation into SQL.
- Option access for tenant-scoped data goes through `WP_MCP_AI_Tenant_Options`, never bare `get_option()`.
- New toolkits adopt isolation incrementally behind a feature flag registered here.

## Tests

```bash
vendor/bin/phpunit tests/tenant/
```

11 test files cover tenant context resolution, repository enforcement, options isolation, and cross-tenant isolation for calendar, CRM, healthcare, project management, quiz/places/media, and regulatory/DJ toolkits.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security (tenant isolation is a defence-in-depth layer; always)
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — tool registry and registration patterns
- [`.context/pro-vs-base.md`](../../.context/pro-vs-base.md) — Base vs Pro placement (subsystem is Base; consumers are Pro)

## See Also

- [`docs/project/proposals/007-multi-tenant-database-isolation.md`](../../docs/project/proposals/007-multi-tenant-database-isolation.md) — full proposal
- [`docs/project/proposals/007-multi-tenant-database-isolation-implementation-plan.md`](../../docs/project/proposals/007-multi-tenant-database-isolation-implementation-plan.md) — phased implementation plan
- [`docs/project/proposals/007-multi-tenant-toolkit-rollout-plan.md`](../../docs/project/proposals/007-multi-tenant-toolkit-rollout-plan.md) — toolkit rollout
