# Compliance Security Fixes — Implementation Plan

**Date:** 2026-07-19  
**Status:** In Progress  
**Scope:** HIGH and MEDIUM security fixes from the NV oOS (mcp-ai-wpoos) Compliance Review

---

## Problem Statement

A compliance review identified 15 issues spanning security, data leakage, authorization bypass, CSRF, and path traversal vulnerabilities. This document provides a comprehensive implementation plan for all **code-level** fixes, excluding build/CI process changes.

## Implementation Order (by severity and dependency)

| Order | Issue # | Title | Severity | Area |
|-------|---------|-------|----------|------|
| 1 | #5 | Admin notice / upload size spam removal | MEDIUM | Core |
| 2 | #6 | Autoload fallback map cleanup | LOW | Core |
| 3 | #7 | Version alignment (package.json) | LOW | Config |
| 4 | #4 | Health endpoint data leakage | MEDIUM | Core REST |
| 5 | #2 | Professional selector HMAC authorization | HIGH | Core AJAX |
| 6 | #3 | Knowledge archive ZIP validation | HIGH | Core Optional |
| 7 | #1 | Release workflow version sed target | P0 | CI (workflow only) |
| 8 | #8 | Google Chat OIDC bypass hardening | HIGH | Pro REST |
| 9 | #9 | Privacy imaging path traversal | HIGH | Pro Privacy |
| 10 | #10 | Sync admin-post CSRF (3 files) | HIGH | Pro Admin |
| 11 | #11 | CDN loader SRI integrity hashes | HIGH | Pro CDN |

---

## Detailed Change Specification

### Fix #5: Admin Notice / Upload Size Spam

**Files:** `includes/bootstrap/hooks.php`

**Changes:**
1. Remove `wp_mcp_ai_increase_upload_size_limit()` function and its `add_filter('upload_size_limit', ...)` call (lines 81-117)
2. Remove `wp_mcp_ai_plugin_directory_pending_notice()` function, `wp_mcp_ai_dismiss_directory_notice_ajax()`, and their hooks (lines 228-297)
3. Remove `wp_mcp_ai_check_upload_limits_notice()` function and its hook (lines 299-408)

**Rationale:** These hooks globally increase upload limits (potential DoS vector), display a now-inaccurate "pending directory approval" notice, and show Pro promotional content in the base plugin admin.

---

### Fix #6: Autoload Fallback Map

**Files:** `includes/bootstrap/autoload.php`

**Changes in `$prefix_map`:**
- Remove: `'Rahul900day\\Tiktoken\\' => 'vendor/rahul900day/tiktoken-php/src/'` — now optional
- Remove: `'Http\\Discovery\\' => 'vendor/php-http/discovery/src/'` — not guaranteed
- Add: `'Symfony\\Component\\Validator\\' => 'vendor/symfony/validator/'`
- Add: `'Symfony\\Component\\Process\\' => 'vendor/symfony/process/'`
- Add: `'Symfony\\Contracts\\Translation\\' => 'vendor/symfony/translation-contracts/'`

---

### Fix #7: Version Alignment

**Files:** `package.json` (root), `addons/pro/package.json`

- Root: `"version": "1.1.29"` → `"1.1.40"` (matches `WP_MCP_AI_VERSION`)
- Pro: `"version": "1.1.9"` → `"1.1.25"` (matches `WP_MCP_AI_PRO_VERSION`)

---

### Fix #4: Health Endpoint Data Leakage

**Files:** `includes/rest/class-wp-mcp-ai-rest-health.php`

**Changes:**
- Public (unauthenticated) response: only `{"status":"ok"}` or `{"status":"degraded"}` with HTTP 200/503
- Authenticated (`manage_options` cap or valid application password): full detailed response
- RabbitMQ exceptions are never exposed in public responses

**Implementation:**
```php
public static function health_check( $request ) {
    $is_authenticated = is_user_logged_in() && current_user_can( 'manage_options' );
    
    if ( ! $is_authenticated ) {
        global $wpdb;
        $result = $wpdb->get_var( 'SELECT 1' );
        return new WP_REST_Response(
            array( 'status' => '1' === (string) $result ? 'ok' : 'degraded' ),
            '1' === (string) $result ? 200 : 503
        );
    }
    // ... existing full checks
}
```

Also update `permission_callback` to accept the `$request` parameter so the callback can inspect auth.

---

### Fix #2: Professional Selector Authorization Bypass

**Files:** `includes/class-wp-mcp-ai-professional-selector-shortcode.php`, `assets/js/professional-selector.js`

**Problem:** Client sends raw `shortcode_atts` string to `handle_render_professional_chat()` AJAX. An attacker can inject `allow_sensitive_tools="true"` or any attribute, bypassing server-side shortcode config.

**Solution: HMAC-signed policy token.**

**Server-side (PHP):**
1. In `render_shortcode()`: generate an HMAC-signed policy token containing the allowed config values (assistant, allow_guests, save_transcript, enable_streaming, allow_sensitive_tools, template, exp). Pass via JSON config as `policyToken`.
2. In `handle_render_professional_chat()`: accept `policy_token`, `provider`, `model`, `temperature` instead of raw `shortcode_atts`. Verify HMAC, check expiry, reconstruct shortcode atts server-side.

**Client-side (JS):**
1. In `createChatInterface()`: read `config.policyToken` and send it instead of constructing `shortcodeAtts`.

**Policy token format:** `base64(json_payload + '|' + wp_hash(json_payload))`

---

### Fix #3: Knowledge Archive ZIP Validation

**Files:** `includes/class-wp-mcp-ai-optional-components.php`

**Changes:**
1. Remove `download_vectorizer()` method — vectorizer is now bundled
2. Remove `is_vectorizer_installed()` method
3. Remove vectorizer branches from `background_download()`, `download_on_activation()`, `get_status()`, `show_download_notice()`, `ajax_download_component()`
4. Add `validate_knowledge_base_archive()` method that:
   - Checks every entry against `realpath()` for path traversal
   - Validates file extensions (only `.txt`)
   - Requires ≥200 playbook files after extraction
5. Call validation in `download_knowledge_base()` after `unzip_file()`

---

### Fix #1: Release Workflow Version Update

**Files:** `.github/workflows/release.yml`

**Change at line 152:**
```yaml
# Before:
sed -i "s/define( 'WP_MCP_AI_VERSION', '.*' );/define( 'WP_MCP_AI_VERSION', '\${VERSION}' );/" mcp-ai-wpoos.php

# After:
sed -i "s/define( 'WP_MCP_AI_VERSION', '.*' );/define( 'WP_MCP_AI_VERSION', '\${VERSION}' );/" includes/bootstrap/constants.php
```

Also update the verification grep at line 161.

---

### Fix #8: Google Chat OIDC Bypass — Pro

**Files:** `addons/pro/includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php`

**Changes:**
1. Replace the `disable_oidc_verification` bypass (L400-406) with a shared-secret token fallback:
   - Require a `verification_token` on the connection when `disable_oidc_verification` is set
   - Accept requests with `?token=` query parameter or `X-Google-Chat-Token` header matching the token
2. When audience is empty, still enforce strict issuer validation
3. Log CRITICAL severity when OIDC is disabled without fallback token

---

### Fix #9: Privacy Imaging Path Traversal — Pro

**Files:** `addons/pro/includes/class-wp-mcp-ai-pro-privacy.php`

**Changes in `erase_imaging_studies()` (L380-385):**
1. Before `delete_directory_recursively()`, resolve `$storage_path` with `realpath()`
2. Validate containment within uploads directory using `strpos()` check
3. Skip deletion and log security event if path traversal detected

---

### Fix #10: Sync Admin-Post CSRF — Pro (3 files)

**Files:**
- `addons/pro/includes/admin/class-wp-mcp-ai-shopify-sync-toolkit-settings-page.php`
- `addons/pro/includes/admin/class-wp-mcp-ai-ezuite-toolkit-settings-page.php`
- `addons/pro/includes/admin/class-wp-mcp-ai-flowhub-toolkit-settings-page.php`

**Changes:**
1. In the inline `<script>` that builds sync/dry-run URLs: add `&_wpnonce=` parameter using `wp_create_nonce()`
2. In `handle_sync_action()`: add `check_admin_referer()` at the top after the capability check

**Nonce action names per file:**
- Shopify: `wp_mcp_ai_shopify_sync`
- EZuite: `wp_mcp_ai_ezuite_sync`
- FlowHub: `wp_mcp_ai_flowhub_sync`

---

### Fix #11: CDN Loader SRI Integrity — Pro

**Files:** `addons/pro/includes/class-wp-mcp-ai-pro-cdn-loader.php`

**Changes:**
1. Replace stub `'sha384-'` for chart.js with real SRI hash
2. Add `sri` field to all other CDN libraries (katex, d3, axios, mathjs, prettier)
3. Apply `integrity` + `crossorigin` attributes to CSS registrations (currently only JS)

**Hashes to compute:**
| Library | Current SRI |
|---------|-------------|
| chart.js 4.4.7 | `sha384-...` (replace stub) |
| katex 0.16.11 | `sha384-...` |
| d3 7.8.5 | `sha384-...` |
| axios 1.6.5 | `sha384-...` |
| mathjs 15.2.0 | `sha384-...` |
| prettier 3.4.2 | `sha384-...` |

---

## Validation Plan

After implementation, run:

```bash
# PHP lint (base plugin)
composer run lint:base

# PHP compatibility check
composer run lint:compat

# Full PHP lint (base + pro)
composer run lint

# PHPUnit tests
composer run test
```

Expected: 0 new errors or warnings introduced.

---

## Rollback Plan

Each change is atomic and can be reverted independently. No database migrations are required. The professional selector HMAC change is the most impactful — if issues arise in production, the `handle_render_professional_chat()` handler can temporarily fall back to accepting both the old `shortcode_atts` and new `policy_token` format during a transition period.

---

## Files Modified Summary

| # | File | Lines Changed |
|---|------|--------------|
| 1 | `includes/bootstrap/hooks.php` | ~200 removed |
| 2 | `includes/bootstrap/autoload.php` | 2 removed, 3 added entries |
| 3 | `package.json` | 1 line |
| 4 | `addons/pro/package.json` | 1 line |
| 5 | `includes/rest/class-wp-mcp-ai-rest-health.php` | ~30 lines restructured |
| 6 | `includes/class-wp-mcp-ai-professional-selector-shortcode.php` | ~40 lines changed |
| 7 | `assets/js/professional-selector.js` | ~20 lines changed |
| 8 | `includes/class-wp-mcp-ai-optional-components.php` | ~100 removed, ~40 added |
| 9 | `.github/workflows/release.yml` | 2 lines |
| 10 | `addons/pro/includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php` | ~30 lines changed |
| 11 | `addons/pro/includes/class-wp-mcp-ai-pro-privacy.php` | ~10 lines added |
| 12 | `addons/pro/includes/admin/class-wp-mcp-ai-shopify-sync-toolkit-settings-page.php` | ~6 lines |
| 13 | `addons/pro/includes/admin/class-wp-mcp-ai-ezuite-toolkit-settings-page.php` | ~6 lines |
| 14 | `addons/pro/includes/admin/class-wp-mcp-ai-flowhub-toolkit-settings-page.php` | ~6 lines |
| 15 | `addons/pro/includes/class-wp-mcp-ai-pro-cdn-loader.php` | ~15 lines |
