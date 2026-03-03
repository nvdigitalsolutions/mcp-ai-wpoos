# WordPress.org Plugin Review Compliance — March 2026

**Review ID:** AUTO nvdigital-open-operator-system-oos/vsamtani/25Dec25/T11 2Mar26/3.9A4
**Date:** March 2, 2026
**Plugin Version:** 1.1.2 → 1.1.3 (compliance update)
**Status:** All identified issues addressed

---

## Summary of Review Feedback

The WordPress.org automated review tools identified the following categories of issues:

1. Trialware and Locked Features (Guideline 5)
2. Invalid URLs in readme.txt (404 errors)
3. Out of Date Libraries
4. Undocumented Use of Third-Party / External Services (Guideline 6)
5. Saving Data in the Plugin Folder
6. Missing Sanitization for `register_setting()`
7. Improper Sanitization of Inputs (`$_SERVER`, `json_decode`)
8. Prefixing Concerns

---

## Issue 1: Trialware and Locked Features

### Problem
The automated review detected that Pro/full features (e.g., Pro Dashboard, REST endpoints, report generation, extra toolkits/tools) are present in the codebase but intentionally gated/disabled via a base-mode constant and a remote license/plan feature check.

### Root Cause
The `WP_MCP_AI_Pro_License::is_pro_active()` method checked a stored license key status as one of its conditions to enable features. While `WP_MCP_AI_PRO_DASHBOARD_ENABLED` defaulted to `true` (making features available without a license), the license-key check path existed as a fallback.

### Fix Applied
**File:** `includes/admin/class-wp-mcp-ai-pro-license.php`

- **`is_pro_active()`** — Removed the license-key status check. The method now returns `true` by default. The `WP_MCP_AI_PRO_DASHBOARD_ENABLED` constant serves as an opt-out mechanism (set to `false` to disable), not as a license gate. All built-in features are fully functional without any license key.

- **`has_feature()`** — Removed the `is_pro_active()` gate from `has_feature()`. All features included in the base plugin are now accessible regardless of license status.

### Compliance Statement
All features included in the plugin hosted on WordPress.org are fully functional without any license key, trial period, or usage limit. The license activation form is retained solely for potential future use with externally-hosted premium add-ons, in accordance with Guideline 5.

---

## Issue 2: Invalid URLs in readme.txt

### Problem
Three documentation URLs in readme.txt returned HTTP 404 errors because the docs had been reorganized into subdirectories:

- `docs/rest-api.md` → moved to `docs/reference/api/rest-api.md`
- `docs/tool-reference.md` → moved to `docs/reference/tools/tool-reference.md`
- `docs/mcp-server-authentication.md` → moved to `docs/reference/api/mcp-server-authentication.md`

### Fix Applied
**File:** `readme.txt`

Updated all five URL references (lines 116-118, 208, 229) to point to the correct paths under `docs/reference/`.

---

## Issue 3: Out of Date Libraries

### Problem
Four Symfony packages were outdated:

| Package | Old Version | New Version |
|---------|------------|-------------|
| symfony/cache | 6.4.33 | 6.4.34 |
| symfony/filesystem | 6.4.30 | 6.4.34 |
| symfony/http-client | 6.4.33 | 6.4.34 |
| symfony/validator | 6.4.33 | 6.4.34 |

### Fix Applied
**Files:** `composer.lock`, `vendor/symfony/*`

Updated all four packages to v6.4.34 via `composer install`. Advisory database confirmed no known vulnerabilities in the updated versions.

---

## Issue 4: Undocumented Use of Third-Party / External Services

### Problem
The review identified 23+ instances of external service calls not documented in readme.txt, including:

- Flowhub API (inventory management)
- Cloudflare API (zone/cache management — distinct from Workers AI)
- Hugging Face API (inference, not just datasets)
- Gmail / Google Mail API
- Plaid API (financial data)
- remove.bg API (background removal)
- NHC / NOAA API (hurricane data)
- DuckDuckGo API (web search)
- PayHere API (payments)
- Auth0 API (authentication)
- NV Digital Solutions activation tracking endpoint
- NV Digital Solutions license server

### Fix Applied (Phase 1 — March 2, 2026)
**File:** `readme.txt` — External Services section

Added comprehensive documentation for 11 previously undocumented services (items 13–21 and 22–23 in the updated External Services section).

### Fix Applied (Phase 2 — Complete Audit)

A full audit of every `wp_remote_post`, `wp_remote_get`, and `wp_remote_request` call in the base plugin uncovered 4 additional undocumented services:

| Service | File | Fix |
|---------|------|-----|
| Mubert Music API | `includes/services/class-wp-mcp-ai-mubert-music-service.php` | Added as item #22 |
| GDACS Disaster API | `includes/tools/class-wp-mcp-ai-tool-get-gdacs-events.php` | Added as item #23 |
| Google Maps Platform | `includes/class-wp-mcp-ai-google-maps-client.php` | Added as item #24 |
| Meta / Facebook Graph API | `includes/integrations/class-wp-mcp-ai-meta-oauth-handler.php` | Added as item #25 |

Updated the Hugging Face entry (item 7) to include inference API usage in addition to the previously documented dataset access.

Renumbered all services — the readme now documents **31 total external services** with full Terms/Privacy links.

### Activation Tracking Disclosure (Item 22)
The NV Digital Solutions activation tracking service now includes:
- Explicit description of all data collected (hashed site URL, versions, locale)
- Confirmation that no PII is collected
- Opt-out instructions (settings toggle and filter hook)
- Note that local/development environments are automatically excluded

### License Server Disclosure (Item 23)
The NV Digital Solutions license server is now documented as:
- Optional — only contacted when a user manually enters a license key
- Data sent: license key, site URL, product identifier

---

## Issue 5: Saving Data in the Plugin Folder

### Problem
`file_put_contents()` in the code formatting command (`handle_code_format`) allowed writing to arbitrary filesystem paths taken from command arguments.

### Fix Applied
**File:** `includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php`

1. Added path restriction: File operations are now restricted to the WordPress uploads directory (`wp_upload_dir()['basedir']`). Paths outside uploads are rejected with an error message.
2. Used `realpath()` to prevent directory traversal attacks.
3. Added `DIRECTORY_SEPARATOR` suffix check to prevent prefix-matching false positives (e.g., `/uploads_backup/` vs `/uploads/`).
4. Replaced `file_put_contents()` with `WP_Filesystem::put_contents()` for WordPress-compatible file writing.
5. Replaced `file_get_contents()` with `WP_Filesystem::get_contents()` for consistent WordPress file operations.

---

## Issue 6: Missing `sanitize_callback` for `register_setting()`

### Problem
`register_setting()` in `class-wp-mcp-ai-settings-dashboard.php` was called without a `sanitize_callback`, relying solely on manual sanitization in `handle_save_settings()`.

### Fix Applied
**File:** `includes/admin/class-wp-mcp-ai-settings-dashboard.php`

1. Added `'sanitize_callback' => array( $this, 'sanitize_settings_callback' )` to the `register_setting()` call.
2. Created new method `sanitize_settings_callback()` that validates input is an array and delegates to the existing `sanitize_settings()` method.
3. The primary sanitization path (`handle_save_settings()`) remains unchanged for tab/subtab-aware saves via `admin_post`.

---

## Issue 7: Improper Sanitization of Inputs

### Problem
Multiple instances of unsanitized input from `$_SERVER` and `json_decode()` of `$_POST` data.

### Fixes Applied

#### 7a. `$_SERVER` Sanitization

| File | Variable | Fix |
|------|----------|-----|
| `includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php` | `$_SERVER['REMOTE_ADDR']` | Wrapped with `sanitize_text_field( wp_unslash() )` |
| `includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php` | `$_SERVER['HTTP_USER_AGENT']` | Wrapped with `sanitize_text_field( wp_unslash() )` |
| `includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php` | `$_SERVER['REQUEST_TIME_FLOAT']` | Safe cast to `(float)` with null coalescing fallback |
| `includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php` | `$_SERVER['HTTP_AUTHORIZATION']` | Wrapped with `sanitize_text_field( wp_unslash() )` |
| `includes/integrations/class-wp-mcp-ai-integration-auth0-github.php` | `$_SERVER['HTTP_AUTHORIZATION']` | Wrapped with `sanitize_text_field( wp_unslash() )` |
| `includes/class-wp-mcp-ai-simple-jwt-login-integration.php` | `$_SERVER` (entire array) | Replaced with sanitized subset of relevant IP-related keys |
| `includes/class-wp-mcp-ai-proxy-utils.php` | `$_SERVER['HTTP_X_FORWARDED_HOST']` | Wrapped with `sanitize_text_field( wp_unslash() )` |

#### 7b. `json_decode()` Sanitization

Created new helper function `wp_mcp_ai_sanitize_recursive()` in `includes/container-helpers.php` that recursively sanitizes decoded JSON arrays:
- Strings → `sanitize_text_field()`
- Integers → `(int)` cast
- Floats → `(float)` cast
- Booleans → preserved
- Arrays → recursively sanitized
- Keys → `sanitize_text_field()`

Applied to:

| File | Variable |
|------|----------|
| `includes/admin/class-wp-mcp-ai-workflow-editor-page.php` | `$_POST['steps']` (line 219) |
| `includes/admin/class-wp-mcp-ai-workflow-editor-page.php` | `$_POST['params']` (line 298) |
| `includes/admin/class-wp-mcp-ai-model-manager-ajax.php` | `$_POST['config']` (line 133) |
| `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` | `$_POST['config']` (line 2898) |

---

## Issue 8: Prefixing

### Assessment
The review tools flagged potential prefixing issues. Our audit found:

- **All global functions** use the `wp_mcp_ai_` prefix ✓
- **All global classes** use the `WP_MCP_AI_` prefix ✓
- **All constants** use the `WP_MCP_AI_` prefix ✓
- **All hooks** use the `wp_mcp_ai_` prefix ✓
- **All database option keys** use the `wp_mcp_ai_` prefix ✓

**Namespaced classes** (23 argument validators + 4 constraint validators) use PHP namespaces under `WP_MCP_AI\Validators\` and `WP_MCP_AI\Tools\Arguments\`, which provides equivalent namespace isolation. These do not require additional prefixing as the namespace prevents conflicts.

**No action required** — all elements are properly namespaced/prefixed.

---

## Issue 9: Privacy Policy Updates

### Fix Applied
**File:** `readme.txt` — Privacy Policy section

- Replaced "No External Tracking" with "Activation Tracking (Opt-Out Available)"
- Added complete disclosure of what activation tracking collects
- Added opt-out instructions
- Updated GDPR FAQ to reference activation tracking opt-out

---

## Full Audit Verification

A comprehensive audit of the entire base plugin was performed after applying all fixes. The audit checked every PHP file in `includes/` (excluding `vendor/`) that ships in the WordPress.org distribution ZIP.

### Sanitization Audit

| Category | Files Checked | Issues Found | Status |
|----------|--------------|--------------|--------|
| `$_SERVER` sanitization | All includes/*.php | 0 remaining unsanitized | ✅ Clean |
| `$_GET`/`$_POST`/`$_REQUEST` sanitization | All includes/*.php | 0 remaining unsanitized | ✅ Clean |
| `json_decode` sanitization | All includes/*.php | 0 remaining unsanitized | ✅ Clean |
| `register_setting` callbacks | All includes/*.php | 0 remaining without callback | ✅ Clean |
| `$_COOKIE`/`$_SESSION` sanitization | All includes/*.php | 0 unsanitized access | ✅ Clean |

### File System Audit

| Category | Files Checked | Status |
|----------|--------------|--------|
| `file_put_contents` safety | 18 instances in includes/ | ✅ All write to uploads dir, temp files, or admin-only paths |
| `file_get_contents` safety | Checked all instances | ✅ No user-controlled path access without validation |
| Plugin folder data storage | All includes/ | ✅ No data saved in plugin folder |

**Note on file_put_contents usage:** All 18 remaining `file_put_contents` calls in the base plugin write to:
- WordPress uploads directory (`wp_upload_dir()['basedir']`) — skill files, reports, images, workflows
- System temp directory (`sys_get_temp_dir()`, `wp_tempnam()`) — temporary processing files
- Admin-only slash commands with `manage_options` capability check

These calls use `file_put_contents` rather than `WP_Filesystem` because they operate within WordPress-managed directories (uploads/temp) and are invoked from admin-only contexts where `WP_Filesystem` initialization may not be available. Each call is guarded by capability checks and writes only to safe, controlled paths. The specifically flagged instance in `handle_code_format` was converted to `WP_Filesystem` as that was the one with user-controlled paths.

### External Services Audit

| Category | Status |
|----------|--------|
| External service documentation | ✅ 31 services fully documented with Terms/Privacy links |
| License/feature gating | ✅ 0 gated features — all built-in features fully available |
| Activation tracking disclosure | ✅ Fully documented with opt-out instructions |

### Code Quality Audit

| Category | Status |
|----------|--------|
| Class/function prefixing | ✅ All globals use `wp_mcp_ai_`/`WP_MCP_AI_` prefix |
| Namespaced classes | ✅ Use `WP_MCP_AI\` namespace (equivalent isolation) |
| External CDN assets | ✅ CDN-dependent files excluded via .distignore |
| Library versions | ✅ All Symfony packages at v6.4.34 |

### Noted Items (Not Flagged as Blocking)

The review noted the following items for awareness. These are not blocking issues but are documented for completeness:

1. **Inline `<script>` and `<style>` tags**: Multiple admin PHP files use inline scripts/styles for metabox UIs, Elementor widgets, and settings pages. Many use `wp_add_inline_script()` / `wp_add_inline_style()` already; some Elementor widgets use `wp_print_inline_script_tag()` with backward-compatible fallbacks. Admin-only contexts are generally acceptable exceptions per the review guidelines.

2. **Prefixing**: All global-scope elements (functions, classes, constants, hooks, option keys) use the `wp_mcp_ai_`/`WP_MCP_AI_` prefix. Namespaced classes use PHP namespaces under `WP_MCP_AI\` which provides equivalent isolation.

---

## Post-Review Audit — Additional Base Plugin Fixes (March 3, 2026)

After applying the fixes above, a full compliance audit of the base plugin was run to catch any remaining issues before re-submission. The following additional fixes were applied to the base plugin only (no changes to the Pro add-on).

---

### Fix A: Output Escaping — Unescaped CSS Class Attributes

#### Problem
Five locations in admin PHP files used unescaped PHP expressions directly inside HTML `class="..."` attributes. WordPress Coding Standards require all dynamic attribute values to be wrapped in `esc_attr()`.

#### Fixes Applied

| File | Line | Before | After |
|------|------|--------|-------|
| `includes/admin/class-wp-mcp-ai-admin-profession-settings.php` | 186 | `echo $active_tab === $tab_slug ? 'nav-tab-active' : ''` | `echo esc_attr( $active_tab === $tab_slug ? 'nav-tab-active' : '' )` |
| `includes/admin/class-wp-mcp-ai-admin-team-settings.php` | 191 | `echo $active_tab === $tab_slug ? 'nav-tab-active' : ''` | `echo esc_attr( $active_tab === $tab_slug ? 'nav-tab-active' : '' )` |
| `includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php` | 271 | `echo $compact ? 'compact-view' : ''` | `echo esc_attr( $compact ? 'compact-view' : '' )` |
| `includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php` | 1541 | `echo $health_metrics['health_score'] >= 70 ? 'good' : (... ? 'fair' : 'poor')` | `echo esc_attr( $health_metrics['health_score'] >= 70 ? 'good' : (... ? 'fair' : 'poor') )` |
| `includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php` | 1562 | `echo $health_metrics['metrics']['expiring_soon'] > 0 ? 'warning' : ''` | `echo esc_attr( $health_metrics['metrics']['expiring_soon'] > 0 ? 'warning' : '' )` |

One additional instance in `includes/admin/sections/class-wp-mcp-ai-section-tools.php` (line 1145) outputs a `wp_json_encode()` result inside an inline `<script>` block. Since `wp_json_encode()` already produces safe output for script contexts, a `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` comment with justification was added in place of re-escaping (which would corrupt the JSON).

---

### Fix B: Missing ABSPATH Direct-Access Guards

#### Problem
Four PHP files were missing the standard WordPress direct-access guard at the top of the file:

```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

This guard prevents the file from being executed directly via the web server, bypassing WordPress's bootstrap.

#### Fixes Applied

| File | Note |
|------|------|
| `includes/toolkit-metadata-mapping.php` | Guard added after PHPDoc block |
| `includes/filesystem/class-wp-mcp-ai-filesystem-service.php` | Guard added after `namespace` declaration (PHP requires `namespace` to be first) |
| `includes/services/class-wp-mcp-ai-process-service.php` | Guard added after `namespace` declaration |
| `includes/validators/class-wp-mcp-ai-validator-service.php` | Guard added after `namespace` declaration |

**Note on ordering:** For namespaced files, PHP requires the `namespace` declaration to appear before any other code. The ABSPATH guard therefore follows `namespace` rather than preceding it. This is the correct pattern for namespaced WordPress plugin files.

---

### Fix C: Remaining Hardcoded Admin Menu Position

#### Problem
`includes/admin/class-wp-mcp-ai-pro-dashboard.php` still used a hardcoded position argument (`85`) in its `add_menu_page()` call. This had been missed in the v1.1.2 sweep that removed hardcoded positions from the five other locations. Hardcoded menu positions can conflict with other plugins that use the same position slot.

#### Fix Applied

**File:** `includes/admin/class-wp-mcp-ai-pro-dashboard.php` — `register_menu()` method

Changed:
```php
add_menu_page( ..., 'dashicons-shield-alt', 85 );
```
To:
```php
add_menu_page( ..., 'dashicons-shield-alt', null ); // Let WordPress automatically position the menu to avoid conflicts.
```

All six `add_menu_page()` calls in the base plugin now use `null` for the position argument.

---

### Post-Audit Compliance Status

| Category | Status |
|----------|--------|
| Output escaping (all `echo` with dynamic values) | ✅ All escaped with `esc_html()`, `esc_attr()`, `esc_url()`, or documented exemption |
| ABSPATH guards in all PHP files | ✅ Present in all files that ship in the WordPress.org ZIP |
| Hardcoded admin menu positions | ✅ None — all six `add_menu_page()` calls use `null` |
| All issues from original review email | ✅ Resolved (see Issues 1–9 above) |

**Plugin version at submission: 1.1.3**

---

## Files Changed

### Code Changes (Original Review — Issues 1–9)
1. `includes/admin/class-wp-mcp-ai-settings-dashboard.php` — Added sanitize_callback and sanitize_settings_callback()
2. `includes/admin/class-wp-mcp-ai-pro-license.php` — Removed license-key gating from is_pro_active() and has_feature()
3. `includes/admin/class-wp-mcp-ai-workflow-editor-page.php` — Added wp_mcp_ai_sanitize_recursive() for json_decode outputs
4. `includes/admin/class-wp-mcp-ai-model-manager-ajax.php` — Added wp_mcp_ai_sanitize_recursive() for json_decode output
5. `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` — Added wp_mcp_ai_sanitize_recursive() for config array
6. `includes/container-helpers.php` — Added wp_mcp_ai_sanitize_recursive() helper function
7. `includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php` — Sanitized $_SERVER, restricted file_put_contents to uploads
8. `includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php` — Sanitized $_SERVER['HTTP_AUTHORIZATION']
9. `includes/integrations/class-wp-mcp-ai-integration-auth0-github.php` — Sanitized $_SERVER['HTTP_AUTHORIZATION']
10. `includes/class-wp-mcp-ai-simple-jwt-login-integration.php` — Sanitized $_SERVER keys before third-party library
11. `includes/class-wp-mcp-ai-proxy-utils.php` — Sanitized $_SERVER['HTTP_X_FORWARDED_HOST']

### Code Changes (Post-Audit — Fix A, B, C)
12. `includes/admin/class-wp-mcp-ai-admin-profession-settings.php` — Added esc_attr() to nav-tab class attribute (Fix A)
13. `includes/admin/class-wp-mcp-ai-admin-team-settings.php` — Added esc_attr() to nav-tab class attribute (Fix A)
14. `includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php` — Added esc_attr() to compact-view class attribute (Fix A)
15. `includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php` — Added esc_attr() to health-score and warning class attributes (Fix A)
16. `includes/admin/sections/class-wp-mcp-ai-section-tools.php` — Added phpcs:ignore with justification for wp_json_encode() in script context (Fix A)
17. `includes/toolkit-metadata-mapping.php` — Added ABSPATH exit guard (Fix B)
18. `includes/filesystem/class-wp-mcp-ai-filesystem-service.php` — Added ABSPATH exit guard (Fix B)
19. `includes/services/class-wp-mcp-ai-process-service.php` — Added ABSPATH exit guard (Fix B)
20. `includes/validators/class-wp-mcp-ai-validator-service.php` — Added ABSPATH exit guard (Fix B)
21. `includes/admin/class-wp-mcp-ai-pro-dashboard.php` — Changed hardcoded menu position 85 → null (Fix C)
22. `mcp-ai-wpoos.php` — Bumped version constant to 1.1.3

### Documentation Changes
23. `readme.txt` — Fixed URLs, added 15 external service disclosures (31 total), updated privacy policy; bumped Stable tag to 1.1.3; added 1.1.3 changelog entry
24. `docs/compliance/WORDPRESS_ORG_REVIEW_COMPLIANCE_2026_03.md` — This document

### Dependency Updates
25. `composer.lock` — Updated Symfony packages to v6.4.34
26. `vendor/symfony/cache/*` — Updated to v6.4.34
27. `vendor/symfony/filesystem/*` — Updated to v6.4.34
28. `vendor/symfony/http-client/*` — Updated to v6.4.34
29. `vendor/symfony/validator/*` — Updated to v6.4.34
