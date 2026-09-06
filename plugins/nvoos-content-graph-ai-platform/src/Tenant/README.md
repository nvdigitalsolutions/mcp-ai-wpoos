# Tenant

## Purpose

Wave E4 port surface. The multi-tenant data-isolation foundation from
the base plugin's `includes/tenant/`: context resolution, repository
enforcement, tenant registry storage, scoped options, feature-flag
gating, and incremental data migration — ported byte-identically so
the later Pro-toolkit ports (Wave F) can adopt isolation incrementally.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::registerTenant()` — standalone-only (`! defined('WP_MCP_AI_PATH')`); the base loader owns the same wiring in monolith installs |
| **Optional dependencies** | None |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Tenant\TenantContext` | `TenantContext.php` | Singleton — resolved by the bootstrap filters, `TenantOptions::from_context()`, and future Pro-toolkit repositories |
| `NvoosContentGraphAiPlatform\Tenant\TenantRepository` | `TenantRepository.php` | Abstract base for all tenant-scoped data-access classes (Wave F consumers) |
| `NvoosContentGraphAiPlatform\Tenant\TenantDatabase` | `TenantDatabase.php` | `TenantBootstrap::register()`; schema owner for `mcp_ai_tenants` / `mcp_ai_tenant_user_map` |
| `NvoosContentGraphAiPlatform\Tenant\TenantOptions` | `TenantOptions.php` | Tenant-scoped option wrappers (Wave F consumers) |
| `NvoosContentGraphAiPlatform\Tenant\TenantFeatureFlags` | `TenantFeatureFlags.php` | Static — read by the bootstrap filters and future toolkits |
| `NvoosContentGraphAiPlatform\Tenant\TenantMigration` | `TenantMigration.php` | Static — the bulk custom-table migration + CLI migrate command |
| `NvoosContentGraphAiPlatform\Tenant\TenantBootstrap` | `TenantBootstrap.php` | `Plugin::registerTenant()` — the init.php hook wiring (REST meta, admin columns, query filters, save stamping, upgrade migration, WP-CLI probe) |
| `NvoosContentGraphAiPlatform\Tenant\TenantCliCommand` | `TenantCliCommand.php` | `wp mcp tenant` WP-CLI commands — self-registers on autoload under the `WP_CLI` gate |

## Inputs / Outputs / Neighbors

- **Reads from:** request headers (`X-WP-MCP-AI-Tenant`), user meta
  (`_wp_mcp_ai_tenant`), assistant post meta (`_wp_mcp_ai_bound_tenant`),
  multisite context, the `wp_mcp_ai_settings` /
  `wp_mcp_ai_tenant_isolation_enabled` /
  `wp_mcp_ai_tenant_isolation_toolkit_*` options, and the
  `WP_MCP_AI_TENANT_ISOLATION` constant.
- **Writes to:** the `mcp_ai_tenants` / `mcp_ai_tenant_user_map` custom
  tables (dbDelta), tenant-scoped options, `_tenant_type` /
  `_tenant_id` post meta, tenant columns + `tenant_lookup` indexes on
  the migratable custom tables, and the `wp_mcp_ai_tenant_db_version`
  option.
- **Upstream callers:** `Plugin::registerTenant()`; future Pro-toolkit
  repositories/execute() methods (Wave F); the WP-CLI `mcp tenant`
  command surface.
- **Downstream collaborators:** WordPress options/post-meta/query
  APIs; the `wp_mcp_ai_activate` + `wp_mcp_ai_after_plugin_upgrade`
  lifecycle actions (emitted by the owning plugin in both modes).
- **Events fired:** none public.
- **Events listened to:** `admin_init` (table version gate),
  `wp_mcp_ai_activate`, `rest_api_init`, `pre_get_posts` (10 + 15),
  `save_post` (20), `wp_mcp_ai_after_plugin_upgrade`,
  `manage_posts_columns` / `manage_pages_columns` (conditional),
  `manage_posts_custom_column` / `manage_pages_custom_column`
  (conditional).

## Conventions

- **Fail closed.** Tenant resolution returns a `WP_Error` (code
  `tenant_not_resolved`) when no tenant matches — repositories must
  treat that as a hard stop, never a silent fallback.
- Repositories extend `TenantRepository` and route every query through
  `tenant_where()` / `tenant_meta_query()` — no raw tenant ID
  concatenation into SQL.
- Byte-identical constants/hooks/error codes/shapes with the base;
  deviations documented in the class docblocks (text domain, PSR-4
  class names, global functions → static methods on `TenantBootstrap`,
  `TenantOptions::from_context()` resolving this package's
  `TenantContext`, the WP-CLI file self-registering on autoload).
- Standalone-only registration — the base bootstrap loader owns the
  same hooks in monolith installs.

## Tests

- `tests/test-tenant-context.php` — resolution order + per-source error
  codes, fail-closed envelope, caching, set/accessors (both matrices).
- `tests/test-tenant-repository.php` — prepared WHERE + bypass, strict
  mode, meta-query clause, post-meta stamping (both matrices).
- `tests/test-tenant-options.php` — scoped/type-level key formats,
  isolation, `from_context()` (both matrices).
- `tests/test-tenant-database.php` — real-DDL schema, version gate,
  tables_installed probe, assign/get mappings (both matrices; suspends
  the harness TEMPORARY-table rewrite).
- `tests/test-tenant-migration.php` — column/index DDL, backfill, CPT
  meta migration on a real scratch table (both matrices; suspends the
  harness TEMPORARY-table rewrite).
- `tests/test-tenant-bootstrap.php` — the init.php hook surface, REST
  meta registration, admin columns, query filters, save stamping,
  migratable-table registry (both matrices).
- `tests/test-tenant-feature-flags.php` — global/settings/constant
  resolution, toolkit opt-in/opt-out, enabled-toolkit scan (both
  matrices).

```bash
vendor/bin/phpunit plugins/nvoos-content-graph-ai-platform/tests/test-tenant-*.php
```

## Also Load

- [`../Queues/README.md`](../Queues/README.md) — the E2 queue family
  (shares the `wp_mcp_ai_activate` lifecycle + custom-table DDL
  patterns)
- [`../README.md`](../README.md) — composition root + subsystem index
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (tenant isolation is a defence-in-depth layer)

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E4 row status
- [`docs/project/proposals/007-multi-tenant-database-isolation.md`](../../../../docs/project/proposals/007-multi-tenant-database-isolation.md) — base subsystem proposal (the port's origin)
