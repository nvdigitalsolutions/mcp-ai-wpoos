# Multi-Tenant Setup Guide

> How to configure tenant isolation for the NV oOS Pro toolkits.

## Quick Start

### 1. Enable Tenant Isolation

**Option A: Globally (recommended for new installs)**

Add to `wp-config.php`:

```php
define( 'WP_MCP_AI_TENANT_ISOLATION', true );
```

**Option B: Via WP-CLI**

```bash
wp mcp tenant toggle on
```

**Option C: Programmatically**

```php
WP_MCP_AI_Tenant_Feature_Flags::enable();
```

### 2. Create Tenants

```bash
# Create a school tenant
wp mcp tenant create school "Springfield Elementary"

# Create a company tenant for CRM
wp mcp tenant create company "Acme Corp"

# List all tenants
wp mcp tenant list
```

### 3. Assign Users to Tenants

```bash
# Assign by user ID
wp mcp tenant assign 42 school 1 --primary

# Assign by email
wp mcp tenant assign admin@school.edu school 1 --primary

# Assign by username
wp mcp tenant assign jsmith company 2
```

### 4. Enable Per-Toolkit (if not using global toggle)

For a gradual rollout, enable tenant isolation only for specific toolkits:

```php
// In a mu-plugin or theme functions.php
add_action( 'init', function() {
    WP_MCP_AI_Tenant_Feature_Flags::enable_toolkit( 'crm' );
    WP_MCP_AI_Tenant_Feature_Flags::enable_toolkit( 'eca-management' );
    WP_MCP_AI_Tenant_Feature_Flags::enable_toolkit( 'healthcare' );
} );
```

### 5. Migrate Existing Data

```bash
# Preview migration (dry run)
wp mcp tenant migrate cpt:mcp_ai_eca school 1 --dry-run
wp mcp tenant migrate cpt:mcp_ai_student school 1 --dry-run

# Run migration
wp mcp tenant migrate cpt:mcp_ai_eca school 1
wp mcp tenant migrate cpt:mcp_ai_student school 1

# Migrate custom tables
wp mcp tenant migrate mcp_ai_custom_metrics school 1
wp mcp tenant migrate mcp_ai_audit_trail school 1
```

## Tenant Types

The system uses `tenant_type` + `tenant_id` compound identifiers. The type is an arbitrary string; the ID is a reference to the `wp_mcp_ai_tenants` table.

### Common Types

| Type | Description | Example |
|---|---|---|
| `school` | Educational institution | "Springfield Elementary" (ID 1) |
| `company` | Business/organization | "Acme Corp" (ID 2) |
| `practice` | Healthcare practice | "City Medical" (ID 3) |
| `site` | Multisite blog | Blog ID |

### Education Hierarchy

For educational use (ECA Management toolkit):

```
School (tenant_id=1)
├── Teacher users → can manage their ECAs
├── Student users → can view own ECAs + attendance
└── Admin users → can manage all school data
```

## API Usage

### REST API Header

Clients consuming the REST API must include the tenant header:

```bash
curl -H "X-WP-MCP-AI-Tenant: school:42" \
     -H "X-WP-Nonce: abc123" \
     https://example.com/wp-json/mcp-ai/v1/ecas
```

### Vault Credentials

When tenant isolation is active, vault credentials are encrypted with tenant-scoped keys. A credential stored by Tenant A cannot be decrypted by Tenant B, even if both have access to the vault.

## Verification

### Check Status

```bash
wp mcp tenant status
```

Output:
```
Global tenant isolation: yes
Toolkits with tenant isolation:
  - crm
  - eca-management
  - healthcare
```

### Verify Isolation

1. Log in as a user assigned to Tenant A (school 1)
2. Create an ECA
3. Log in as a user assigned to Tenant B (school 2)
4. List ECAs — should be empty (or show only Tenant B's ECAs)

## Troubleshooting

### "Tenant context not resolved" error

This means none of the resolution sources (header, user meta, assistant, multisite) provided a tenant. Solutions:

1. Assign the user to a tenant: `wp mcp tenant assign <user> <type> <id> --primary`
2. Include the REST header: `X-WP-MCP-AI-Tenant: school:42`
3. Disable tenant isolation for that toolkit if not ready

### "Tenant isolation is not enabled for toolkit" error

The toolkit's execute() method calls `require_isolation()` but the feature flag is off. Enable it:

```php
WP_MCP_AI_Tenant_Feature_Flags::enable_toolkit( 'my-toolkit' );
```

### Data disappears after enabling isolation

Existing data has `tenant_id = 0` (global/unscoped). You need to migrate:

```bash
wp mcp tenant migrate cpt:mcp_ai_eca school 1
```

## See Also

- [Multi-Tenant Architecture](../developer/multi-tenant-architecture.md) — Technical architecture
- [Tenant Repository Guide](../developer/tenant-repository-guide.md) — Developer guide
- [Proposal 007](../project/proposals/007-multi-tenant-database-isolation.md) — Design rationale
