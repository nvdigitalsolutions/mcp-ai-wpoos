# Tenant Isolation Subsystem

> Multi-tenant data isolation foundation for the NV oOS plugin.

## Purpose

Provides the foundation layer for tenant-scoped data access across all Pro toolkits. Centralises tenant context resolution, repository enforcement, tenant registry storage, scoped options, and feature-flag gating so that each toolkit can adopt isolation incrementally.

## Public Surface

| File | Class | Purpose |
|---|---|---|
| `class-wp-mcp-ai-tenant-context.php` | `WP_MCP_AI_Tenant_Context` | Singleton that resolves the current tenant from headers, user meta, assistant config, or multisite |
| `class-wp-mcp-ai-tenant-repository.php` | `WP_MCP_AI_Tenant_Repository` | Abstract base for all tenant-scoped data-access classes; provides `tenant_where()`, `tenant_meta_query()`, and `require_tenant()` |
| `class-wp-mcp-ai-tenant-database.php` | `WP_MCP_AI_Tenant_Database` | Schema manager for `mcp_ai_tenants` and `mcp_ai_tenant_user_map` tables via `dbDelta()` |
| `class-wp-mcp-ai-tenant-options.php` | `WP_MCP_AI_Tenant_Options` | Wraps `get_option`/`update_option` with automatic tenant-scoped key prefixing |
| `class-wp-mcp-ai-tenant-feature-flags.php` | `WP_MCP_AI_Tenant_Feature_Flags` | Global and per-toolkit feature flags for gradual rollout |
| `init.php` | — | Bootstrap: loads all classes, creates tables, registers REST meta, adds admin columns |

## Neighbours

- `includes/bootstrap/loader.php` — wired in at the bottom of the loader chain
- `includes/admin/class-wp-mcp-ai-pro-database.php` — existing compliance tables (will gain tenant columns in Phase 2)
- `addons/pro/includes/tools/` — all 37 tenant-relevant toolkits (consumers of this subsystem)

## Context Files

- `.context/security.md` — security checklist (tenant isolation is a defence-in-depth layer)
- `.context/toolkit.md` — tool registry and registration patterns
- `docs/project/proposals/007-multi-tenant-database-isolation.md` — full proposal
- `docs/project/proposals/007-multi-tenant-database-isolation-implementation-plan.md` — phased implementation plan
