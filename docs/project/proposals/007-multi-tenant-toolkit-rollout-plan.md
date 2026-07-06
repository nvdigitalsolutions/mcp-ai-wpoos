# Implementation Plan: Toolkit-by-Toolkit Tenant Isolation Rollout

**Status:** In Progress  
**Started:** 2026-07-06  
**Based on:** Audit of 643 tools across 20 Pro toolkits  
**Prerequisite:** [Proposal 007](./007-multi-tenant-database-isolation.md) + [Phase 0 Foundation](./007-multi-tenant-database-isolation-implementation-plan.md)

---

## Audit Summary

| # | Toolkit | Total Tools | State-Changing | Priority | Primary Pattern |
|---|---|---|---|---|---|
| 1 | CRM | 106 | 55 | 🔴 CRITICAL | Data Store (13) + CPT (42) |
| 2 | Healthcare | 72 | 30 | 🔴 CRITICAL | CPT only (PHI data) |
| 3 | Financial Planning | 34 | 5 | 🟢 LOW | Mostly stateless |
| 4 | Calendar Booking | 34 | 20 | 🔴 CRITICAL | CPT only |
| 5 | Architectural Design | 40 | 6 | 🟢 LOW | Mostly stateless |
| 6 | Comic Creation | 12 | 6 | 🟢 LOW | Media operations |
| 7 | CRE Debt | 57 | 0 | ⚪ NONE | All stateless |
| 8 | DJ Management | 22 | 13 | 🟠 HIGH | CPT only |
| 9 | Project Management | 43 | 17 | 🟠 HIGH | CPT only |
| 10 | Quiz Management | 11 | 5 | 🟠 HIGH | CPT only |
| 11 | Regulatory Registration | 59 | 22 | 🔴 CRITICAL | CPT only |
| 12 | Places | 10 | 5 | 🟠 HIGH | CPT only |
| 13 | Document Generation | 31 | 12 | 🟡 MODERATE | CPT + Stateless |
| 14 | Analytics | 12 | 3 | 🟢 LOW | Mostly stateless |
| 15 | Orchestration | 34 | 12 | 🟠 HIGH | CPT + Options |
| 16 | Media | 8 | 6 | 🟡 MODERATE | CPT only |
| 17 | ERP Ezuite | 5 | 3 | 🟢 LOW | External API + CCT |
| 18 | Flowhub | 6 | 2 | 🟢 LOW | CCT only |
| 19 | Shopify Sync | 5 | 2 | 🟢 LOW | CCT only |
| 20 | Site Creator Toolkit | 32 | 20 | 🔴 CRITICAL | WP Core — install plugins! |
| | **TOTAL** | **643** | **244** | | |

---

## Phase 1: Data Store Migration (CRM Deals, Leads, Contacts) ← NOW

### 1.1 CRM Data Store Callers (13 tools)

These tools use `WP_MCP_AI_Toolkit_Data_Store_Factory::get_store()` — the easiest migration. One-line change: `get_store()` → `get_tenant_store()`.

**Files to modify:**

```
addons/pro/includes/tools/crm/deals/
├── class-wp-mcp-ai-tool-create-deal.php      → get_store → get_tenant_store
├── class-wp-mcp-ai-tool-delete-deal.php      → get_store → get_tenant_store
├── class-wp-mcp-ai-tool-get-deal.php         → get_store → get_tenant_store
├── class-wp-mcp-ai-tool-list-deals.php       → get_store → get_tenant_store
├── class-wp-mcp-ai-tool-move-deal-stage.php  → get_store → get_tenant_store
└── class-wp-mcp-ai-tool-update-deal.php      → get_store → get_tenant_store

addons/pro/includes/tools/crm/leads/
├── class-wp-mcp-ai-tool-convert-lead-to-customer.php → get_store → get_tenant_store
├── class-wp-mcp-ai-tool-create-lead.php       → get_store → get_tenant_store
├── class-wp-mcp-ai-tool-delete-lead.php       → get_store → get_tenant_store
├── class-wp-mcp-ai-tool-get-lead.php          → get_store → get_tenant_store
├── class-wp-mcp-ai-tool-list-leads.php        → get_store → get_tenant_store
└── class-wp-mcp-ai-tool-update-lead.php       → get_store → get_tenant_store

addons/pro/includes/tools/crm/
└── class-wp-mcp-ai-tool-manage-crm-contact.php → get_store → get_tenant_store
```

**Estimated effort:** 30 minutes  
**Validation:** `composer run lint:errors-only` on CRM tools directory

### 1.2 Tests for Phase 1

Create `tests/tenant/test-crm-data-store-isolation.php`:

```php
// Test: Create deal as Tenant A, Tenant B cannot see it
// Test: Create lead as Tenant A, Tenant B cannot update/delete it
// Test: List deals/leads returns only current tenant's data
// Test: Move deal stage fails for cross-tenant deal
// Test: Convert lead to customer preserves tenant context
```

---

## Phase 2: CPT Post Meta Scoping (186 tools, 10 toolkits)

### 2.1 Healthcare (30 state-changing tools)

**Pattern:** All tools use `wp_insert_post()` with `mcp_ai_member`, `mcp_ai_imaging_study`, `mcp_ai_vital_log`, etc. CPTs.

**Change needed per tool:**
- In `execute()`: Resolve tenant → add `_tenant_type` + `_tenant_id` to post meta on CREATE
- In query tools: Add `meta_query` with `_tenant_id` filter
- Priority: CRITICAL (PHI data)

**Files to modify (~25 tool files):**
```
addons/pro/includes/tools/healthcare/
├── class-wp-mcp-ai-tool-create-member.php
├── class-wp-mcp-ai-tool-delete-member.php
├── class-wp-mcp-ai-tool-update-member.php
├── class-wp-mcp-ai-tool-create-medical-record.php
├── class-wp-mcp-ai-tool-create-prescription.php
├── class-wp-mcp-ai-tool-create-allergy.php
├── class-wp-mcp-ai-tool-create-checkup.php
├── class-wp-mcp-ai-tool-create-policy.php
├── class-wp-mcp-ai-tool-log-vital-signs.php
├── class-wp-mcp-ai-tool-import-vitals.php
├── class-wp-mcp-ai-tool-track-vaccinations.php
├── class-wp-mcp-ai-tool-import-dicom-study.php
├── class-wp-mcp-ai-tool-attach-radiology-report.php
├── class-wp-mcp-ai-tool-import-fhir-bundle.php
├── class-wp-mcp-ai-tool-import-hl7v2-message.php
├── class-wp-mcp-ai-tool-health-capture-encounter.php
├── class-wp-mcp-ai-tool-merge-duplicate-members.php
├── class-wp-mcp-ai-tool-manage-care-plan.php
├── class-wp-mcp-ai-tool-link-prescription-to-record.php
├── ... and ~10 read tools
```

**Estimated effort:** 4 hours  
**Tests:** `tests/tenant/test-healthcare-tenant-isolation.php`

### 2.2 Calendar Booking (20 state-changing tools)

**Pattern:** All use CPTs (`mcp_appointment`, `mcp_blocked_time`, `mcp_booking_link`, `mcp_event`, `mcp_service`).

**Change needed:** Add `_tenant_id` post meta on create/update. Filter reads by tenant.

**Files to modify (~15 tool files):**
```
addons/pro/includes/tools/calendar-booking/
├── class-wp-mcp-ai-tool-create-appointment.php
├── class-wp-mcp-ai-tool-create-event.php
├── class-wp-mcp-ai-tool-create-service.php
├── class-wp-mcp-ai-tool-update-appointment.php
├── class-wp-mcp-ai-tool-cancel-appointment.php
├── class-wp-mcp-ai-tool-reschedule-appointment.php
├── class-wp-mcp-ai-tool-block-time-slot.php
├── class-wp-mcp-ai-tool-import-services.php
├── class-wp-mcp-ai-tool-sync-from-jetappointment.php
├── class-wp-mcp-ai-tool-sync-from-jetbooking.php
├── class-wp-mcp-ai-tool-sync-to-jetappointment.php
├── ... and ~10 read tools
```

**Estimated effort:** 2.5 hours  
**Tests:** `tests/tenant/test-calendar-tenant-isolation.php`

### 2.3 CRM CPT Callers (42 tools outside Data Store)

The remaining 42 CRM tools that use direct CPT operations (not Data Store). Follow same pattern as Healthcare.

**Estimated effort:** 5 hours  
**Tests:** Included in `test-crm-data-store-isolation.php` (extend existing)

### 2.4 Regulatory Registration (22 state-changing tools)

**Pattern:** `mcp_ai_reg_product` and `mcp_ai_registration` CPTs.

**Estimated effort:** 3 hours  
**Tests:** `tests/tenant/test-regulatory-tenant-isolation.php`

### 2.5 Project Management (17 state-changing tools)

**Estimated effort:** 2.5 hours  
**Tests:** `tests/tenant/test-project-mgmt-tenant-isolation.php`

### 2.6 DJ Management (13 state-changing tools)

**Estimated effort:** 2 hours  
**Tests:** `tests/tenant/test-dj-mgmt-tenant-isolation.php`

### 2.7 Document Generation QMS (10 state-changing tools)

**Estimated effort:** 1.5 hours  
**Tests:** `tests/tenant/test-qms-tenant-isolation.php`

### 2.8 Quiz Management (5 state-changing tools)

**Estimated effort:** 1 hour  
**Tests:** `tests/tenant/test-quiz-tenant-isolation.php`

### 2.9 Places (5 state-changing tools)

**Estimated effort:** 45 minutes  
**Tests:** `tests/tenant/test-places-tenant-isolation.php`

### 2.10 Media (6 tools)

**Estimated effort:** 1 hour  
**Tests:** `tests/tenant/test-media-tenant-isolation.php`

---

## Phase 3: Options Scoping + CCT Scoping

### 3.1 Orchestration (12 tools using Options + CPT)

Options used for: circuit breaker config, schedule widget defaults, session status.  
Pattern: Replace `get_option('wp_mcp_ai_...')` with `WP_MCP_AI_Tenant_Options::from_context()->get(...)`.

**Estimated effort:** 2 hours

### 3.2 Site Creator Toolkit (20 state-changing)

**⚠️ SPECIAL HANDLING REQUIRED:** Two tools (`install_and_activate_plugin`, `install_and_activate_theme`) install plugins/themes globally. These MUST be restricted with `manage_options` capability + tenant admin check. Cannot be scoped to a tenant.

**Estimated effort:** 3 hours (includes capability hardening)

### 3.3 ERP Ezuite + Flowhub + Shopify Sync (7 tools using CCTs)

**Pattern:** CCT queries need `_tenant_id` field filter. Settings need scoped options.

**Estimated effort:** 2 hours

### 3.4 Financial Planning + Analytics (5 tools using Options)

**Estimated effort:** 1 hour

---

## Phase 4: Global Guard for Site Creator

### 4.1 Restrict Plugin/Theme Install Tools

Add capability check to:
- `install_and_activate_plugin` → require `manage_options` + tenant admin
- `install_and_activate_theme` → require `manage_options` + tenant admin

These tools modify the WordPress installation globally and cannot be tenant-scoped. They must be restricted to super admins only.

---

## Test Plan

Every test file follows the same pattern:

```php
// 1. Set tenant context to Tenant A
// 2. Create data as Tenant A
// 3. Switch to Tenant B
// 4. Assert Tenant B cannot see/update/delete Tenant A's data
// 5. Clean up
```

| Test File | Toolkit(s) | Tests | Est. Lines |
|---|---|---|---|
| `test-tenant-context.php` | Foundation | Resolve from header, user meta, assistant, multisite; fail-closed | ~150 |
| `test-tenant-repository.php` | Foundation | `tenant_where()`, `tenant_meta_query()`, `require_tenant()`, strict mode | ~120 |
| `test-tenant-database.php` | Foundation | Create/assign/list tenants, user mapping | ~100 |
| `test-tenant-options.php` | Foundation | Scoped get/update/delete, type-level options | ~80 |
| `test-tenant-migration.php` | Foundation | Add columns, add index, backfill, CPT migration | ~100 |
| `test-crm-tenant-isolation.php` | CRM | Data Store: create/list/update/delete leads+deals | ~150 |
| `test-healthcare-tenant-isolation.php` | Healthcare | Members, prescriptions, allergies, vitals cross-tenant | ~150 |
| `test-calendar-tenant-isolation.php` | Calendar | Appointments, events, services cross-tenant | ~100 |
| `test-regulatory-tenant-isolation.php` | Regulatory | Products, registrations cross-tenant | ~100 |
| `test-project-mgmt-tenant-isolation.php` | Project Mgmt | Projects, tasks, sprints cross-tenant | ~100 |
| `test-dj-mgmt-tenant-isolation.php` | DJ Mgmt | Equipment, bookings, playlists cross-tenant | ~80 |
| `test-qms-tenant-isolation.php` | Doc Gen QMS | Controlled docs, approvals cross-tenant | ~80 |
| `test-quiz-tenant-isolation.php` | Quiz | Quizzes, submissions, grading cross-tenant | ~80 |
| `test-places-tenant-isolation.php` | Places | Place CRUD cross-tenant | ~60 |
| `test-media-tenant-isolation.php` | Media | Collections, templates cross-tenant | ~60 |
| `test-cross-tenant-isolation.php` | Integration | Full end-to-end: multiple toolkits, cross-tenant access denied | ~200 |
| **TOTAL** | | | **~1,710 lines** |

---

## Rollout Checklist

### Now (Phase 1)
- [ ] Migrate 13 CRM Data Store callers
- [ ] Create CRM tenant isolation tests
- [ ] Run PHPCS on modified files

### Next Session (Phase 2)
- [ ] Healthcare CPT scoping (30 tools)
- [ ] Calendar Booking CPT scoping (20 tools)
- [ ] CRM CPT scoping (42 tools)
- [ ] Regulatory CPT scoping (22 tools)

### Session After (Phase 2 continued)
- [ ] Project Management (17 tools)
- [ ] DJ Management (13 tools)
- [ ] Document Gen QMS (10 tools)
- [ ] Quiz, Places, Media (16 tools)

### Final Session (Phases 3-4)
- [ ] Options scoping (Orch, Analytics, Financial, Settings)
- [ ] CCT scoping (ERP, Flowhub, Shopify)
- [ ] Site Creator capability hardening
- [ ] Full integration tests
- [ ] Docs update

---

## Estimated Total Effort

| Phase | Hours |
|---|---|
| Phase 1: Data Store (13 tools) | 0.5 |
| Phase 2: CPT scoping (186 tools) | 21.5 |
| Phase 3: Options + CCT (35 tools) | 8 |
| Phase 4: Global guard (2 tools) | 3 |
| Tests (16 files) | 4 |
| **TOTAL** | **~37 hours** |
