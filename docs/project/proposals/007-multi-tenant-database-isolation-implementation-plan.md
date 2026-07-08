# Implementation Plan: Multi-Tenant Database Isolation (Proposal 007)

**Status:** In Progress
**Started:** 2026-07-06
**Estimated Total Effort:** ~40–60 hours across 4 phases
**Affected Files:** ~50 new/modified files

---

## Phase 0: Foundation (This Session)

### 0.1 Tenant Context Manager
**File:** `includes/tenant/class-wp-mcp-ai-tenant-context.php`

Singleton that resolves the current request's tenant context. Resolution order:
1. Explicit REST API header (`X-WP-MCP-AI-Tenant: school:42`)
2. Logged-in user's primary tenant (user meta `_wp_mcp_ai_tenant`)
3. Assistant's bound tenant (assistant post meta)
4. Multisite blog ID (fallback for multisite installs)

Returns `WP_Error` with code `tenant_not_resolved` if none found (fail-closed).

✅ **COMPLETED**

### 0.2 Tenant Repository Base Class
**File:** `includes/tenant/class-wp-mcp-ai-tenant-repository.php`

Abstract base class for all data-access repositories. Provides:
- `set_tenant_context( string $type, int $id ): void`
- `tenant_where(): string` — returns prepared SQL clause
- `require_tenant(): void` — throws if context not set
- `get_tenant_type(): string`
- `get_tenant_id(): int`

✅ **COMPLETED**

### 0.3 Tenant Registry Table
**File:** `includes/tenant/class-wp-mcp-ai-tenant-database.php`

Creates and manages the `wp_mcp_ai_tenants` and `wp_mcp_ai_tenant_user_map` tables:
- Tenants table: `id, tenant_type, tenant_name, external_id, settings (JSON), created_at, updated_at`
- User map table: `user_id, tenant_type, tenant_id, is_primary, assigned_at`

Uses `dbDelta()` for safe schema migration.

✅ **COMPLETED**

### 0.4 Tenant-Scoped Options Helper
**File:** `includes/tenant/class-wp-mcp-ai-tenant-options.php`

Wrapper around WordPress options API that automatically prefixes option names with tenant scope:
- `get_tenant_option( $key, $default )` → reads `wp_mcp_ai_{tenant_type}_{tenant_id}_{$key}`
- `update_tenant_option( $key, $value )`
- `delete_tenant_option( $key )`

✅ **COMPLETED**

### 0.5 Feature Flag System
**File:** `includes/tenant/class-wp-mcp-ai-tenant-feature-flags.php`

Manages `wp_mcp_ai_tenant_isolation_enabled` option and per-toolkit flags:
- `is_enabled(): bool` — global toggle
- `is_toolkit_enabled( string $toolkit ): bool` — per-toolkit toggle
- `require_isolation(): void` — throws if isolation is not active

✅ **COMPLETED**

### 0.6 Autoloader Registration
**File:** `includes/tenant/init.php`

Registers the tenant namespace classes and hooks them into WordPress:
- Creates tenant tables on plugin activation/upgrade
- Registers REST API fields for tenant metadata
- Hooks into `determine_current_user` for tenant context resolution

✅ **COMPLETED**

### 0.7 PHPCS Validation
Run `composer lint:errors-only` and fix any warnings/errors in the new files.

✅ **COMPLETED**

---

## Phase 1: Critical Toolkits (Tier 0)

### 1.1 Vault Tenant Scoping
**Files:** `addons/pro/includes/vault/*`

- Add `tenant_type` + `tenant_id` to vault entries
- Per-tenant encryption key derivation (salt with tenant_id)
- Update `vault_access` and `vault_manage` tools to respect tenant context

### 1.2 CRM Tenant Scoping
**Files affected (estimated 8–10 files):**
- `addons/pro/includes/class-wp-mcp-ai-contact-cpt.php` → add `_tenant_id` post meta
- `addons/pro/includes/class-wp-mcp-ai-company-cpt.php` → same
- `addons/pro/includes/class-wp-mcp-ai-deal-cpt.php` → same
- `addons/pro/includes/class-wp-mcp-ai-lead-cpt.php` → same
- `addons/pro/includes/class-wp-mcp-ai-customer-cpt.php` → same
- `addons/pro/includes/class-wp-mcp-ai-crm-activity-cpt.php` → same
- `addons/pro/includes/tools/crm/class-wp-mcp-ai-tool-*.php` → add tenant scope to execute()
- `addons/pro/includes/tools/crm/*/class-*.php` → all CRUD tools

### 1.3 Healthcare Tenant Scoping
**Files affected (estimated 8–12 files):**
- `addons/pro/includes/class-wp-mcp-ai-health-wellness-cpt.php` → add `_tenant_id`
- `addons/pro/includes/class-wp-mcp-ai-imaging-study-cpt.php` → add `_tenant_id`
- `addons/pro/includes/class-wp-mcp-ai-imaging-audit-log.php` → add tenant_id column
- `addons/pro/includes/tools/healthcare/*.php` → all tools
- PHI audit log: append-only buffer, strict tenant scoping

### 1.4 Financial Planning Tenant Scoping
**Files affected (estimated 5–8 files):**
- Add `tenant_id` to any CCTs used for financial data
- Scoped options for budgets, transactions, portfolios
- Updates to financial planning tools

### 1.5 ECA Management Tenant Scoping
**Files affected (estimated 12–15 files):**
- `addons/pro/includes/class-wp-mcp-ai-eca-cpt.php` → add `_tenant_id` (school_id)
- Create `wp_mcp_ai_eca_enrollments` table with tenant columns
- Create `wp_mcp_ai_eca_attendance` table with tenant columns
- Update all 30+ ECA management tools
- Update REST controller to enforce tenant scope
- Update dashboard/consolidate pages to filter by tenant

---

## Phase 2: Business Data Toolkits (Tier 1) + Custom Tables (Tier 2)

### 2.1 CPT-Based Toolkits (16 toolkits)
For each, add `_tenant_id` post meta and update CRUD tools:
1. Architectural Design
2. Calendar Booking
3. Comic Creation
4. CRE Debt
5. DJ Management
6. E-commerce (leverage existing WooCommerce isolation)
7. ERP EZUite
8. Extended Cognition
9. FlowHub
10. Media Collections/Templates
11. Places
12. Project Management
13. Quiz Management
14. Regulatory Registration
15. Shopify Sync
16. Site Creator Toolkit

### 2.2 Custom Tables Migration (17 tables)
For each custom table:
1. Add `tenant_type VARCHAR(20) NOT NULL DEFAULT ''` column
2. Add `tenant_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0` column
3. Add composite index `KEY tenant_scope (tenant_type, tenant_id)`
4. Update all queries to include tenant_where()
5. Migration script: set tenant_id=0 for existing rows (backward compatible)

### 2.3 Content/Config Toolkits (Tier 2)
1. Analytics — add tenant_id to metrics/events tables
2. Capture — tenant-scope MemPalace service
3. Document Generation — media library already per-site
4. Image Production — media library already per-site
5. Infrastructure — ensure called CPTs are tenant-scoped
6. JetEngine — ensure managed data is tenant-scoped
7. Multilingual — tenant-scoped translation memory options
8. Orchestration — tenant-scoped schedules/templates
9. Paper Store — per-tenant repository directories
10. Vector Storage — media library already per-site
11. Video Production — media library already per-site
12. WP All Import/Export — tenant-scoped config references

---

## Phase 3: Credential-Bound Toolkits (Tier 3)

These 7 toolkits only need Vault isolation (completed in Phase 1.1):
- Chat Channels, Cloudways, DietPi, Email Marketing, Google Workspace, Remote Connections, Social Media

No additional code changes needed beyond Phase 1.1 Vault scoping.

---

## Phase 4: Validation & Hardening

### 4.1 Test Suite
**New test files:**
- `tests/tenant/test-tenant-context.php`
- `tests/tenant/test-tenant-repository.php`
- `tests/tenant/test-tenant-database.php`
- `tests/tenant/test-tenant-options.php`
- `tests/tenant/test-cross-tenant-isolation.php` — integration test: try to access Tenant B data as Tenant A
- `tests/tenant/test-eca-tenant-isolation.php`
- `tests/tenant/test-crm-tenant-isolation.php`
- `tests/tenant/test-healthcare-tenant-isolation.php`

### 4.2 Migration CLI Commands
- `wp mcp tenant list` — list all tenants
- `wp mcp tenant create <type> <name>` — create a new tenant
- `wp mcp tenant assign <user_id> <tenant_type> <tenant_id>` — assign user to tenant
- `wp mcp tenant migrate <toolkit>` — migrate existing data to tenant scope

### 4.3 Documentation
- `docs/developer/multi-tenant-architecture.md` — architecture overview
- `docs/developer/tenant-repository-guide.md` — how to use TenantRepository
- `docs/admin-guides/multi-tenant-setup.md` — admin setup guide
- Update `docs/DOCUMENTATION_INDEX.md`

---

## File Manifest (All New Files)

### Foundation (Phase 0) — 6 files
```
includes/tenant/class-wp-mcp-ai-tenant-context.php
includes/tenant/class-wp-mcp-ai-tenant-repository.php
includes/tenant/class-wp-mcp-ai-tenant-database.php
includes/tenant/class-wp-mcp-ai-tenant-options.php
includes/tenant/class-wp-mcp-ai-tenant-feature-flags.php
includes/tenant/init.php
```

### ECA New Tables (Phase 1) — 2 DDL files
```
addons/pro/includes/eca/class-wp-mcp-ai-eca-enrollments-db.php
addons/pro/includes/eca/class-wp-mcp-ai-eca-attendance-db.php
```

### Migration Helpers (Phase 1–2) — 1 file
```
includes/tenant/class-wp-mcp-ai-tenant-migration.php
```

### Tests (Phase 4) — 8 files
```
tests/tenant/test-tenant-context.php
tests/tenant/test-tenant-repository.php
tests/tenant/test-tenant-database.php
tests/tenant/test-tenant-options.php
tests/tenant/test-cross-tenant-isolation.php
tests/tenant/test-eca-tenant-isolation.php
tests/tenant/test-crm-tenant-isolation.php
tests/tenant/test-healthcare-tenant-isolation.php
```

### Docs (Phase 4) — 3 files
```
docs/developer/multi-tenant-architecture.md
docs/developer/tenant-repository-guide.md
docs/admin-guides/multi-tenant-setup.md
```

**Total new files: ~20** (not counting modifications to existing toolkits)

---

## Implementation Checklist

### Session 1 (Now): Phase 0 Foundation
- [x] 0.1 Tenant Context Manager
- [x] 0.2 Tenant Repository Base Class
- [x] 0.3 Tenant Registry Table (dbDelta)
- [x] 0.4 Tenant-Scoped Options Helper
- [x] 0.5 Feature Flag System
- [x] 0.6 Init/autoloader registration
- [x] 0.7 PHPCS validation

### Session 2: Phase 1 Critical Toolkits
- [ ] 1.1 Vault tenant scoping
- [ ] 1.2 CRM tenant scoping
- [ ] 1.3 Healthcare tenant scoping
- [ ] 1.4 Financial Planning tenant scoping
- [ ] 1.5 ECA Management tenant scoping + new tables

### Session 3: Phase 2 Business Data + Custom Tables
- [ ] 2.1 CPT-based toolkits (16 toolkits)
- [ ] 2.2 Custom tables migration (17 tables)
- [ ] 2.3 Content/config toolkits

### Session 4: Phase 3 + Phase 4
- [ ] 3.1 Credential-bound toolkits (verification only)
- [ ] 4.1 Test suite
- [ ] 4.2 Migration CLI commands
- [ ] 4.3 Documentation
