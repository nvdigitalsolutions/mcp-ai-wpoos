# Multi-Tenant Architecture

> How the NV oOS plugin isolates data between tenants (schools, companies, practices, etc.)

## Overview

The tenant isolation system ensures that data belonging to Tenant A is never accessible to Tenant B, even when both tenants share the same WordPress installation. This is achieved through a three-layer defence-in-depth approach.

## Architecture

### Three-Layer Defence

```
Layer 1: Tenant Context Manager (singleton)
  Resolves "who is the current tenant" from the request.
  Sources: REST header → user meta → assistant config → multisite

Layer 2: Tenant Repository (abstract base class)
  Every data-access class extends this. Automatically appends
  tenant_id to all queries. Fails closed if context not set.

Layer 3: Database Schema (columns + indexes)
  All tables: tenant_type VARCHAR(20) + tenant_id BIGINT
  Composite indexes: (tenant_type, tenant_id, ...)
```

### Resolution Priority

1. **REST API Header** — `X-WP-MCP-AI-Tenant: school:42`
2. **Logged-in User** — user meta `_wp_mcp_ai_tenant`
3. **Assistant Binding** — assistant post meta `_wp_mcp_ai_bound_tenant`
4. **Multisite** — blog ID (fallback for multisite installs)

If none match, `WP_Error` is returned (fail-closed).

## Key Classes

| Class | File | Purpose |
|---|---|---|
| `WP_MCP_AI_Tenant_Context` | `includes/tenant/class-wp-mcp-ai-tenant-context.php` | Resolves current tenant from request |
| `WP_MCP_AI_Tenant_Repository` | `includes/tenant/class-wp-mcp-ai-tenant-repository.php` | Abstract base for tenant-scoped data access |
| `WP_MCP_AI_Tenant_Database` | `includes/tenant/class-wp-mcp-ai-tenant-database.php` | Schema manager for tenant tables |
| `WP_MCP_AI_Tenant_Options` | `includes/tenant/class-wp-mcp-ai-tenant-options.php` | Tenant-scoped WordPress options |
| `WP_MCP_AI_Tenant_Feature_Flags` | `includes/tenant/class-wp-mcp-ai-tenant-feature-flags.php` | Gradual rollout gating |
| `WP_MCP_AI_Tenant_Migration` | `includes/tenant/class-wp-mcp-ai-tenant-migration.php` | Migration helper for existing data |

## Database Tables

| Table | Purpose |
|---|---|
| `wp_mcp_ai_tenants` | Tenant registry (type, name, settings) |
| `wp_mcp_ai_tenant_user_map` | User-to-tenant assignments |
| `wp_mcp_ai_eca_enrollments` | ECA enrollment records (tenant-scoped) |
| `wp_mcp_ai_eca_attendance` | ECA attendance records (tenant-scoped) |

## Tenant Types

The system supports arbitrary tenant types. Common examples:

| Type | Use Case |
|---|---|
| `school` | Educational institution |
| `company` | Business/organization |
| `practice` | Healthcare practice |
| `site` | Multisite blog |
| `eca` | Extra-curricular activity |
| `teacher` | Individual teacher |
| `student` | Individual student |

## Feature Flags

Tenant isolation is **opt-in** by default to preserve backward compatibility:

```php
// Global enable (wp-config.php)
define( 'WP_MCP_AI_TENANT_ISOLATION', true );

// Per-toolkit enable (WordPress admin or code)
WP_MCP_AI_Tenant_Feature_Flags::enable_toolkit( 'crm' );
WP_MCP_AI_Tenant_Feature_Flags::enable_toolkit( 'eca-management' );

// Check status
if ( WP_MCP_AI_Tenant_Feature_Flags::is_toolkit_enabled( 'crm' ) ) {
    // Tenant isolation active for CRM
}
```

## WP-CLI Commands

```bash
# List all tenants
wp mcp tenant list
wp mcp tenant list --type=school

# Create a tenant
wp mcp tenant create school "Springfield Elementary"
wp mcp tenant create company "Acme Corp" --external-id=ACM001

# Assign a user to a tenant
wp mcp tenant assign 42 school 1 --primary
wp mcp tenant assign admin@school.edu school 1

# Migrate existing data
wp mcp tenant migrate cpt:mcp_ai_eca school 1 --dry-run
wp mcp tenant migrate cpt:mcp_ai_eca school 1

# Check status
wp mcp tenant status

# Toggle globally
wp mcp tenant toggle on
```

## See Also

- [Tenant Repository Guide](tenant-repository-guide.md) — How to use in toolkits
- [Multi-Tenant Setup Guide](../admin-guides/multi-tenant-setup.md) — Admin configuration
- [Proposal 007](../project/proposals/007-multi-tenant-database-isolation.md) — Full design rationale
