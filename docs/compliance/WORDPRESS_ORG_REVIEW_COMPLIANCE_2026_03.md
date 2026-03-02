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

### Fix Applied
**File:** `readme.txt` — External Services section

Added comprehensive documentation for all 11 previously undocumented services (items 13–23 in the updated External Services section), including:

- **Purpose** — What each service does
- **Data Sent** — Exactly what data is transmitted
- **When** — Under what conditions data is sent
- **Service URL** — The endpoint(s) contacted
- **Terms of Service** — Link to the service's terms
- **Privacy Policy** — Link to the service's privacy policy

Updated the Hugging Face entry (item 7) to include inference API usage in addition to the previously documented dataset access.

Renumbered OAuth/integration services (items 24–27) and removed the "Pro Version Only" label to avoid implying feature gating.

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
3. Replaced `file_put_contents()` with `WP_Filesystem::put_contents()` for WordPress-compatible file writing.
4. Replaced `file_get_contents()` with the resolved `$real_file` path.

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

A comprehensive audit of the entire base plugin was performed after applying all fixes:

| Category | Files Checked | Issues Found | Status |
|----------|--------------|--------------|--------|
| `$_SERVER` sanitization | All includes/*.php | 0 remaining | ✅ Clean |
| `json_decode` sanitization | All includes/*.php | 0 remaining | ✅ Clean |
| `register_setting` callbacks | All includes/*.php | 0 remaining | ✅ Clean |
| `file_put_contents` safety | All includes/*.php | 0 remaining | ✅ Clean |
| External service documentation | readme.txt | 0 undocumented | ✅ Clean |
| License/feature gating | pro-license.php | 0 gated features | ✅ Clean |
| Class/function prefixing | All includes/*.php | 0 unprefixed globals | ✅ Clean |
| Inline scripts | All includes/*.php | 0 violations | ✅ Clean |
| External CDN assets | All includes/*.php | 0 violations | ✅ Clean |
| Library versions | composer.lock | All current | ✅ Clean |

---

## Files Changed

### Code Changes
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

### Documentation Changes
12. `readme.txt` — Fixed URLs, added 11 external service disclosures, updated privacy policy
13. `docs/compliance/WORDPRESS_ORG_REVIEW_COMPLIANCE_2026_03.md` — This document

### Dependency Updates
14. `composer.lock` — Updated Symfony packages to v6.4.34
15. `vendor/symfony/cache/*` — Updated to v6.4.34
16. `vendor/symfony/filesystem/*` — Updated to v6.4.34
17. `vendor/symfony/http-client/*` — Updated to v6.4.34
18. `vendor/symfony/validator/*` — Updated to v6.4.34
