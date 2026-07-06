# Proposal 007: Multi-Tenant Database Isolation for Pro Toolkits

**Status:** Draft
**Created:** 2026-07-06
**Author:** AI Agent (Zed Coding Agent)
**Affected:** 37 of 47 Pro toolkits, 17 custom tables, 32 CPTs

---

## 1. Problem Statement

The Pro addon contains 47 toolkits that store data across WordPress CPTs, custom database tables, JetEngine CCTs, and WordPress options. **None of these have any tenant isolation.** All data is globally visible to any authenticated user with the appropriate capability.

This means:
- A school administrator at School A can see School B's students, ECAs, and attendance records
- A CRM user at Company X can view Company Y's contacts, deals, and pipeline data
- A healthcare provider at Clinic A can access Clinic B's patient records (HIPAA violation)
- API credentials stored in the Vault are accessible across all tenant contexts

The risk is especially acute because the AI assistant tools can search, retrieve, and mutate data across tenant boundaries without any guardrails.

## 2. Industry Research Summary

Research across AWS, Microsoft, Nile.dev, Bytebase, and 2025/2026 SaaS architecture guides establishes three canonical multi-tenant isolation patterns:

### 2.1 Isolation Levels

| Pattern | Description | Isolation | Cost | When to Use |
|---|---|---|---|---|
| **Pool** (shared tables + `tenant_id` column) | All tenants share tables; isolation via WHERE clause | Low | Lowest | < 100 tenants |
| **Bridge** (schema-per-tenant) | One schema per tenant within shared DB | Medium | Medium | 100–500 tenants |
| **Silo** (database-per-tenant) | Separate database per tenant | Highest | Highest | Enterprise / regulated |

### 2.2 Key Principles (from AWS, Nile.dev, 2025 SaaS guides)

1. **Defense in depth**: Two-layer enforcement — database layer (RLS policies) + application layer (middleware/query builder). Never rely on one layer.
2. **Fail-closed**: If tenant context is missing, queries return empty results or errors — never all data.
3. **Session-scoped tenant context**: Set once per request via `current_setting('app.current_tenant')` (PostgreSQL) or application singleton (MySQL/WordPress).
4. **Centralized enforcement**: Policies defined at the table/repository level, not scattered across every query.

### 2.3 MySQL Adaptation (WordPress Context)

WordPress runs on MySQL, which lacks true Row-Level Security (unlike PostgreSQL 9.5+). The equivalent strategy:

| PostgreSQL RLS Feature | MySQL/WordPress Equivalent |
|---|---|
| `CREATE POLICY ... USING (tenant_id = current_setting(...))` | Repository base class with `tenant_where()` method |
| `current_setting('app.current_tenant')` | `WP_MCP_AI_Tenant_Context::instance()->resolve()` |
| `FORCE ROW LEVEL SECURITY` | `require_tenant()` guard in every repository |
| `SECURITY DEFINER` functions | Admin capability checks in tool base class |

## 3. Proposed Architecture

### 3.1 Three-Layer Defense

```
┌─────────────────────────────────────────────────────────┐
│  Layer 1: Tenant Context Manager (singleton)             │
│  Resolves tenant from: user meta → REST header →        │
│  assistant config → multisite blog ID                   │
├─────────────────────────────────────────────────────────┤
│  Layer 2: Tenant Repository (base class)                │
│  Every data-access class extends this.                  │
│  Automatically appends tenant_id to all queries.        │
│  Fails closed if context not set.                       │
├─────────────────────────────────────────────────────────┤
│  Layer 3: Database Schema (columns + indexes)           │
│  All tables: tenant_type VARCHAR(20) + tenant_id BIGINT │
│  Composite indexes: (tenant_type, tenant_id, ...)       │
│  Triggers enforce tenant on INSERT/UPDATE               │
└─────────────────────────────────────────────────────────┘
```

### 3.2 Tenant Hierarchy (Education Example)

```
School (tenant root)
├── Teacher → their ECAs + enrolled students
├── Student → own profile + enrolled ECAs + attendance
└── ECA → schedule, capacity, enrolled students
```

### 3.3 Column Specification

Every custom table and CPT receives:

```sql
tenant_type VARCHAR(20) NOT NULL COMMENT 'Tenant type: school,teacher,student,eca',
tenant_id   BIGINT(20) UNSIGNED NOT NULL COMMENT 'Tenant identifier',
-- Composite index
KEY tenant_scope (tenant_type, tenant_id)
```

## 4. Toolkit Impact Assessment

### 4.1 Summary

| Tier | Count | Risk Level | Description |
|---|---|---|---|
| Tier 0 — CRITICAL | 5 | PII/PHI/Credentials | CRM, Healthcare, Vault, Financial, ECA |
| Tier 1 — HIGH | 16 | Business data | Architectural, Calendar, Comic, CRE, DJ, E-com, ERP, Cognition, FlowHub, Media, Places, Project, Quiz, Regulatory, Shopify, Site Creator |
| Tier 2 — MEDIUM | 16 | Content/config | Analytics, Capture, Docs, Images, Infra, JetEngine, Multilingual, Orchestration, Paper, Vector, Video, WP All Import |
| Tier 3 — LOW | 7 | Credentials only | Chat Channels, Cloudways, DietPi, Email Marketing, Google Workspace, Remote Connections, Social Media |
| Tier 4 — NONE | 6 | Stateless | AI Tool Builder, Architect Agent, Automotive, Developer, Math, Research |

**Total toolkits needing enhancement: 37 of 47** (79%)

### 4.2 Custom Tables Requiring `tenant_id`

17 custom tables across the codebase currently have zero tenant isolation:

| Table | Source File | Risk |
|---|---|---|
| `wp_mcp_ai_controls` | `includes/admin/class-wp-mcp-ai-pro-database.php` | Global |
| `wp_mcp_ai_evidence` | same | Global |
| `wp_mcp_ai_audit_trail` | same | Global |
| `wp_mcp_ai_risks` | same | Global |
| `wp_mcp_ai_compliance_checks` | same | Global |
| `wp_mcp_ai_custom_metrics` | `tools/analytics/class-wp-mcp-ai-tool-collect-custom-metrics.php` | Global |
| `wp_mcp_ai_events` | `tools/analytics/class-wp-mcp-ai-tool-real-time-event-tracking.php` | Global |
| `wp_mcp_ai_qms_audit_log` | `addons/pro/includes/qms/class-wp-mcp-ai-qms-audit-log.php` | Global |
| `wp_mcp_ai_async_jobs` | `includes/class-wp-mcp-ai-async-job-queue.php` | Global |
| `wp_mcp_ai_threads` | `includes/class-wp-mcp-ai-thread-manager.php` | Global |
| `wp_mcp_ai_token_usage` | `includes/class-wp-mcp-ai-token-tracking-database.php` | Global |
| `wp_mcp_ai_tool_embeddings` | `includes/data/class-wp-mcp-ai-tool-embedding-store.php` | Global |
| `wp_mcp_ai_metric_events` | `includes/measurement/class-wp-mcp-ai-metric-event-store.php` | Global |
| `wp_mcp_ai_content_embeddings` | `includes/services/class-wp-mcp-ai-content-embedding-store.php` | Global |
| `wp_mcp_ai_context_embeddings` | `includes/services/class-wp-mcp-ai-context-embedding-store.php` | Global |
| `wp_mcp_ai_slash_cmd_audit` | `includes/slash-commands/class-wp-mcp-ai-slash-command-audit.php` | Global |
| `wp_nvoos_graph_*` (×5) | `addons/graphify/includes/class-nvoos-graphify-db.php` | Global |

### 4.3 New Tables Needed

| Table | Purpose | Toolkit |
|---|---|---|
| `wp_mcp_ai_eca_enrollments` | ECA enrollment records with tenant scope | ECA Management |
| `wp_mcp_ai_eca_attendance` | ECA attendance records with tenant scope | ECA Management |
| `wp_mcp_ai_tenants` | Tenant registry (mapping tenant types to IDs) | Global (foundation) |
| `wp_mcp_ai_tenant_user_map` | User-to-tenant assignment mapping | Global (foundation) |

## 5. Backward Compatibility

### 5.1 Migration Path

1. **Phase 0**: Foundation classes created, no behavior change. All existing queries continue to work.
2. **Phase 1**: `tenant_id` columns added with default `0` (global/unscoped). Migration script assigns tenants to existing data. Feature flag `wp_mcp_ai_tenant_isolation_enabled` defaults to `false`.
3. **Phase 2**: Feature flag enabled per-toolkit. Tenant-scoped queries active for opted-in toolkits.
4. **Phase 3**: Feature flag removed. All queries require valid tenant context. `tenant_id=0` is rejected.

### 5.2 Feature Flag

```php
// In wp-config.php or via admin settings:
define( 'WP_MCP_AI_TENANT_ISOLATION', true );

// Or per-toolkit:
add_filter( 'wp_mcp_ai_tenant_isolation_enabled', '__return_true' );
add_filter( 'wp_mcp_ai_tenant_isolation_toolkits', function( $toolkits ) {
    $toolkits[] = 'crm';
    $toolkits[] = 'eca-management';
    return $toolkits;
});
```

## 6. Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Breaking existing single-tenant sites | High | High | Feature flag + `tenant_id=0` default |
| Performance impact from tenant column | Medium | Medium | Composite indexes, query analysis |
| Tenant context resolution failure | Medium | High | Fail-closed, clear error messages, fallback chain |
| Migration script data corruption | Low | High | Dry-run mode, backup before migration, tests |
| Plugin updates breaking isolation | Medium | Medium | Automated tests for every toolkit |

## 7. References

- AWS Database Blog: "Multi-tenant data isolation with PostgreSQL Row Level Security" (2020, updated)
- Nile.dev: "Shipping multi-tenant SaaS using Postgres Row-Level Security" (2022)
- Zenn: "SaaS Design: Multi-Tenant Architecture Patterns 2025 Edition"
- Bytebase: "Multi-Tenant Database Architecture Patterns Explained"
- daily.dev: "Multi-Tenant Database Design Patterns 2026"
- PostgreSQL Docs: "Row Security Policies" (v18)
- WordPress Plugin Handbook: "Managing Custom Database Tables"
