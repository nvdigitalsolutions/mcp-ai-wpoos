# Security Hardening Proposal

**Date:** 2026-04-04 (original), updated 2026-06-11 (v1.1.29)
**Author:** NV Digital Solutions
**Status:** P0 Complete ✅ | P1-P3 Partially Addressed — SQL injection, direct DB queries, guest token TTL, output escaping, and sanitization order all fixed. Remaining REST audit and JS i18n items tracked in docs/operations/security/.
**Branch:** `copilot/review-wordpress-plugin-security`

---

## Executive Summary

A comprehensive security audit of the NV oOS WordPress plugin identified issues across SQL query construction, output escaping, input sanitization, and authentication token management. This document tracks what has been fixed and outlines the remaining hardening plan organized by priority.

---

## ✅ P0 — Completed (This Release)

### 1. SQL Injection Pattern in Analytics Engine

**Problem:** The analytics engine (`class-wp-mcp-ai-analytics-engine.php`) built a `$where` clause with `$wpdb->prepare()` then string-interpolated it into a larger raw query, defeating the prepared statement.

**Fix Applied:**
- Replaced the pre-prepared `$where` fragment with a single `$wpdb->prepare()` call per branch
- Table name validated via `$repository->table_exists()` and escaped with `esc_sql()`

**Files Changed:**
- `includes/class-wp-mcp-ai-analytics-engine.php` (lines 715–724)

### 2. Direct DB Queries Without `esc_sql()` or `$wpdb->prepare()`

**Problem:** Multiple files interpolated table names directly into SQL strings without escaping, and some used literal string values instead of `$wpdb->prepare()` placeholders.

**Fixes Applied:**

| File | Issue | Fix |
|------|-------|-----|
| `includes/tools/class-wp-mcp-ai-tool-performance-optimizer-assistant.php` | `$wpdb->dbname` directly interpolated into SQL | Use `$wpdb->prepare()` with `DB_NAME` constant |
| `includes/admin/class-wp-mcp-ai-report-generator.php` | `$evidence_table` interpolated without escaping | Added `esc_sql()` |
| `includes/admin/class-wp-mcp-ai-pro-database.php` | `$controls_table` interpolated without escaping | Added `esc_sql()` |
| `includes/class-wp-mcp-ai-async-job-queue.php` | `$table_name` interpolated, literal status strings | Added `esc_sql()` + `$wpdb->prepare()` for status filters |
| `includes/admin/class-wp-mcp-ai-pro-dashboard-rest.php` | `$checks_table` interpolated without escaping | Added `esc_sql()` |
| `includes/admin/class-wp-mcp-ai-pro-dashboard.php` | Multiple CCT table queries with `$table` interpolation | Already used `esc_sql()` — verified safe |

### 3. Guest Token Expiration Mechanism

**Problem:** Guest tokens had a hardcoded TTL of `HOUR_IN_SECONDS` (3600 seconds) that ignored the admin `guest_token_lifetime` setting (default: 86400 seconds / 24 hours). Additionally, the sliding TTL renewal on each validation could keep tokens alive indefinitely.

**Fixes Applied:**
- Connected `generate_guest_token()` and `validate_guest_token()` to the admin `guest_token_lifetime` setting
- Added `get_guest_token_ttl()` helper that reads from Settings Registry with bounds enforcement (60s min, 604800s max)
- Added absolute maximum lifetime enforcement: tokens now check their `created` timestamp against `GUEST_TOKEN_MAX_TTL` (7 days) and self-destruct if exceeded
- Updated `GUEST_TOKEN_TTL` default from `HOUR_IN_SECONDS` to `DAY_IN_SECONDS` to align with admin default

**Files Changed:**
- `includes/class-wp-mcp-ai-shortcode.php` (guest token methods)

### 4. Output Escaping — Shortcode Content

**Problem:** Assistant content echoed in shortcode used `phpcs:ignore` instead of proper escaping.

**Fix Applied:** Replaced raw `echo $assistant_content` with `echo wp_kses_post( $assistant_content )`.

**Files Changed:**
- `includes/class-wp-mcp-ai-shortcode.php` (line 1303)

### 5. Input Sanitization Order — Security Monitor

**Problem:** `urldecode()` called after `sanitize_text_field()` could reintroduce encoded characters.

**Fix Applied:** Removed `urldecode()` from the escaping chain.

**Files Changed:**
- `includes/admin/class-wp-mcp-ai-security-monitor-admin.php` (line 275)

---

## 🔶 P1 — Next Sprint

### 6. Remaining Table Name Interpolation Patterns

**Scope:** The async job queue has additional methods (`get_job`, `process_next_job`, `cleanup_old_jobs`, `get_jobs_by_status`) that still use `$table_name` without `esc_sql()` — they do use `$wpdb->prepare()` for user values but the table name itself is unescaped.

**Action Items:**
- [ ] Add `esc_sql()` to all remaining `$table_name` uses in `class-wp-mcp-ai-async-job-queue.php` (lines 272, 395, 634, 714)
- [ ] Audit `includes/admin/class-wp-mcp-ai-pro-dashboard.php` lines 4078–4108 for consistency with the `esc_sql()` pattern already in place at line 4074
- [ ] Create a shared utility method for building safe table name references from prefix + constant

### 7. REST API Endpoint Audit

**Scope:** The MCP REST controller OPTIONS endpoint uses `'permission_callback' => '__return_true'` for CORS preflight. While this is standard for OPTIONS handlers, the callback should be verified to have no side effects.

**Action Items:**
- [ ] Audit `handle_mcp_options()` in `class-wp-mcp-ai-rest-mcp-controller.php` to confirm it only sets CORS headers
- [ ] Add explicit comment documenting why `__return_true` is intentional for OPTIONS
- [ ] Review all other endpoints for consistent permission callback patterns

### 8. JavaScript i18n Standardization

**Scope:** Some admin JavaScript files use hardcoded English strings instead of `wp.i18n.__()`.

**Action Items:**
- [ ] Audit `assets/js/admin-settings.js` for hardcoded strings in error messages and status text
- [ ] Replace all user-visible strings with `wp.i18n.__()` calls
- [ ] Verify all admin JS files follow the same pattern

---

## 🔷 P2 — Next Release

### 9. Refactor Large REST Controller

**Scope:** `class-wp-mcp-ai-rest.php` is 10,195 lines with 158 methods. While functional, this size makes security auditing difficult.

**Action Items:**
- [ ] Identify method groups that can be extracted to specialized controllers
- [ ] Move tool execution methods to `class-wp-mcp-ai-rest-tools-controller.php` (partially done)
- [ ] Move chat/SSE methods to `class-wp-mcp-ai-rest-chat-controller.php` (partially done)
- [ ] Target: no single controller file exceeds 3,000 lines

### 10. Extract Common HTTP Adapter for API Clients

**Scope:** OpenAI client (6,769 lines) and Gemini client (4,903 lines) share similar patterns for endpoint resolution, request header building, response parsing, and error handling.

**Action Items:**
- [ ] Create `class-wp-mcp-ai-api-client-base.php` with shared HTTP patterns
- [ ] Extract `resolve_endpoint()`, `build_request_headers()`, and response normalization
- [ ] Have OpenAI, Gemini, Anthropic, and NVIDIA clients extend the base class
- [ ] Estimated reduction: ~150 lines of duplicated code

### 11. Enable Test Coverage Enforcement

**Scope:** The CI pipeline has a 70% coverage threshold that is currently in warning-only mode (`true || exit 1` in `phpunit.yml`).

**Action Items:**
- [ ] Remove `true ||` guard from coverage check in `.github/workflows/phpunit.yml` line 112
- [ ] Set initial enforcement threshold to match current actual coverage
- [ ] Add coverage badge to README.md
- [ ] Gradually increase threshold with each release

### 12. PHPCS Baseline Reduction Plan

**Scope:** Current PHPCS baseline is 1,175 errors. The CI allows up to 10 errors per changed file.

**Action Items:**
- [ ] Categorize remaining errors by type (formatting, security, best-practice)
- [ ] Fix all security-related PHPCS errors first (estimate: ~50 errors)
- [ ] Fix formatting errors in batch (~200 per sprint)
- [ ] Target: reduce to 500 within 2 releases, 0 within 4 releases

---

## 🔘 P3 — Ongoing Improvement

### 13. Replace `file_get_contents()` With WP_Filesystem

**Scope:** 13 instances of `file_get_contents()` for local file reads across `includes/admin/class-wp-mcp-ai-pro-dashboard.php` and other admin files.

**Action Items:**
- [ ] Identify which usages can be migrated to `WP_Filesystem::get_contents()`
- [ ] Migrate progressively, starting with admin context files where `WP_Filesystem` is available
- [ ] Keep `file_get_contents()` only where `WP_Filesystem` is provably unavailable (e.g., early bootstrap)

### 14. Add Security Headers at Plugin Level

**Action Items:**
- [ ] Evaluate adding Content-Security-Policy headers for admin pages
- [ ] Add X-Content-Type-Options: nosniff for REST API responses
- [ ] Document any headers that might conflict with theme or host configurations

### 15. Guest Token Revocation API

**Scope:** Currently, guest tokens can only be revoked by waiting for expiration or manually deleting transients.

**Action Items:**
- [ ] Add REST endpoint for revoking a specific guest token
- [ ] Add admin UI action to revoke all guest tokens for an assistant
- [ ] Add automatic revocation when an assistant is trashed or unpublished
- [ ] Log token creation and revocation events for audit trail

### 16. Rate Limiting Hardening

**Scope:** Rate limiting is applied to all REST authentication methods but thresholds may need tuning.

**Action Items:**
- [ ] Audit current rate limit thresholds per auth method
- [ ] Add configurable rate limits per assistant (not just global)
- [ ] Consider implementing progressive backoff for failed auth attempts
- [ ] Log rate limit events for monitoring

---

## Architecture Notes

### Why `esc_sql()` for Table Names

WordPress `$wpdb->prepare()` does not support table name placeholders in WP < 6.2 (the `%i` identifier placeholder was added in WP 6.2). Since this plugin requires WordPress 6.0+, we use `esc_sql()` on table names as the standard defense-in-depth pattern:

```php
$safe_table = esc_sql( $wpdb->prefix . 'table_name' );
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$safe_table} WHERE user_id = %d",
        $user_id
    )
);
```

This pattern is safe because:
1. Table names are derived from `$wpdb->prefix` (internal) + hardcoded constant
2. `esc_sql()` provides defense-in-depth against any future prefix manipulation
3. `$wpdb->prepare()` handles all user-supplied values

### Guest Token Security Model

```
Token Lifecycle:
┌─────────────────────────────────────────────────────────────┐
│  generate_guest_token()                                      │
│  ├─ Creates 32-char random token via wp_generate_password()  │
│  ├─ Stores in transient with configured TTL                  │
│  └─ Records creation timestamp for absolute expiry           │
│                                                              │
│  validate_guest_token()                                      │
│  ├─ Checks transient existence (sliding TTL)                 │
│  ├─ Checks absolute max lifetime (7 days hard cap)           │
│  ├─ Verifies assistant_id scope                              │
│  └─ Refreshes sliding TTL on successful validation           │
│                                                              │
│  Expiration triggers:                                        │
│  ├─ Sliding TTL expires (configurable, default 24h)          │
│  ├─ Absolute max TTL exceeded (7 days)                       │
│  └─ [Future] Manual revocation via REST API                  │
└─────────────────────────────────────────────────────────────┘
```

---

## Verification

All P0 fixes have been verified with:
- PHP syntax checks (`php -l`) on all changed files
- Automated code review (passed)
- CodeQL security scan (passed)

Remaining P1–P3 items should be verified by:
- Full PHPUnit test suite (`composer run test`)
- PHPCS linting (`composer run lint`)
- Manual review of REST API authentication flow
- Guest token integration testing with the chat UI

---

## References

- WordPress Coding Standards: https://developer.wordpress.org/coding-standards/
- WordPress Data Validation: https://developer.wordpress.org/apis/security/data-validation/
- OWASP SQL Injection Prevention: https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html
- WordPress `$wpdb->prepare()` documentation: https://developer.wordpress.org/reference/classes/wpdb/prepare/
