# Compliance Security Fixes — July 2026

**Status:** Implemented
**Merged:** July 20, 2026 (PR #5718)
**Scope:** 11 HIGH/P0 security vulnerabilities from the NV oOS compliance review

---

## Overview

A compliance review identified 15 issues spanning authorization bypass, CSRF, path traversal,
data leakage, and integrity vulnerabilities. This document summarizes the 11 **code-level** fixes
implemented across core and Pro code.

---

## Fixes by Severity

### CRITICAL — Authorization Bypasses

#### Fix #2: Professional Selector — HMAC Policy Tokens

**Files:** `includes/class-wp-mcp-ai-professional-selector-shortcode.php`, `assets/js/professional-selector.js`

**Problem:** The professional selector sent raw `shortcode_atts` from client to server. An attacker
could inject `allow_sensitive_tools="true"` or any attribute, bypassing server-side shortcode config.

**Solution:** Replaced client-controlled `shortcode_atts` with an **HMAC-signed policy token**:

1. **Server generates token** in `render_shortcode()`: a base64-encoded JSON payload of allowed
   config values (`assistant`, `allow_guests`, `save_transcript`, `enable_streaming`,
   `allow_sensitive_tools`, `template`, `exp`) signed with `wp_hash()` and a 1-hour expiry.
2. **Client sends token** in `createChatInterface()` via `config.policyToken` instead of
   constructing raw `shortcodeAtts`.
3. **Server verifies token** in `handle_render_professional_chat()`: HMAC check + expiry validation
   before reconstructing shortcode attrs server-side.

**Migration note:** The `professional_id` (user-chosen profession) and `provider`/`model`/
`temperature` (user preferences) remain client-sent POST params — only server-controlled
policy attributes moved into the signed token. PR #5721 fixed a regression where `profession`
was accidentally omitted from the reconstructed shortcode.

#### Fix #8: Google Chat Webhook — OIDC Bypass Hardening (Pro)

**File:** `addons/pro/includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php`

**Problem:** A `disable_oidc_verification` flag allowed total bypass of OIDC token verification,
leaving the webhook endpoint unauthenticated.

**Solution:** Replaced the total bypass with a **shared-secret `verification_token`** check
(mirroring Telegram's bot token model). Requests must include `?token=` query parameter or
`X-Google-Chat-Token` header matching the configured token. When audience is empty, issuer
validation is still enforced. CRITICAL severity is logged when OIDC is disabled without a
fallback token.

---

### HIGH — Path Traversal / CSRF

#### Fix #3: Knowledge Archive ZIP — Path Traversal Prevention

**File:** `includes/class-wp-mcp-ai-optional-components.php`

**Problem:** The knowledge base ZIP extraction had no validation, allowing crafted archives to
write files outside the intended directory via path traversal entries (e.g., `../../wp-config.php`).

**Solution:** Added `validate_knowledge_base_archive()` method that:
1. Checks every ZIP entry against `realpath()` for path traversal
2. Validates file extensions (`.txt` only)
3. Requires ≥200 playbook files after extraction (sanity minimum)
4. Called in `download_knowledge_base()` after `unzip_file()`

Also removed the legacy vectorizer downloader (now bundled) as part of this cleanup.

#### Fix #9: Privacy Imaging — Path Traversal Prevention (Pro)

**File:** `addons/pro/includes/class-wp-mcp-ai-pro-privacy.php`

**Problem:** `erase_imaging_studies()` performed recursive directory deletion without verifying
that the storage path was contained within the uploads directory.

**Solution:** Before `delete_directory_recursively()`:
1. Resolve `$storage_path` with `realpath()`
2. Validate containment within uploads directory using `strpos()` check
3. Skip deletion and log security event if path traversal detected

#### Fix #10: Sync Admin-Post CSRF — 3 Files (Pro)

**Files:**
- `addons/pro/includes/admin/class-wp-mcp-ai-shopify-sync-toolkit-settings-page.php`
- `addons/pro/includes/admin/class-wp-mcp-ai-ezuite-toolkit-settings-page.php`
- `addons/pro/includes/admin/class-wp-mcp-ai-flowhub-toolkit-settings-page.php`

**Problem:** Admin-post sync/dry-run endpoints had no nonce verification, making them vulnerable
to CSRF attacks that could trigger expensive sync operations.

**Solution:** Added `check_admin_referer()` calls with per-toolkit nonce actions:
- Shopify: `wp_mcp_ai_shopify_sync`
- EZuite: `wp_mcp_ai_ezuite_sync`
- FlowHub: `wp_mcp_ai_flowhub_sync`

Nonce parameters are now appended to sync/dry-run URLs in the inline admin scripts via
`wp_create_nonce()`.

---

### MEDIUM — Information Leakage / Spam

#### Fix #4: Health Endpoint — Data Leakage Prevention

**File:** `includes/rest/class-wp-mcp-ai-rest-health.php`

**Problem:** The public (unauthenticated) health endpoint exposed detailed system status including
database connection info, RabbitMQ status, and component health — useful reconnaissance for attackers.

**Solution:** Public response now returns only `{"status":"ok"}` or `{"status":"degraded"}` with
HTTP 200/503. Detailed checks (DB, queues, components) are gated behind `manage_options` capability
or valid application password. RabbitMQ exceptions are never exposed in public responses.

#### Fix #5: Admin Notice Spam — Removal

**File:** `includes/bootstrap/hooks.php`

**Problem:** Three admin hooks were causing issues:
1. `upload_size_limit` filter globally increased upload limits (potential DoS vector)
2. "Pending directory approval" notice was inaccurate and distracting
3. Pro upload-limit promotional notice appeared in base plugin admin

**Solution:** Removed all three hooks and their associated callback functions (~200 lines):
- `wp_mcp_ai_increase_upload_size_limit()` + filter
- `wp_mcp_ai_plugin_directory_pending_notice()` + AJAX dismiss handler
- `wp_mcp_ai_check_upload_limits_notice()` + hook

---

### LOW — Maintenance

#### Fix #6: Autoload Fallback Map Cleanup

**File:** `includes/bootstrap/autoload.php`

**Changes:**
- Removed optional `tiktoken-php` and `php-http/discovery` fallback entries
- Added fallback entries for Symfony Process, Validator, and Translation Contracts
- PR #5721 later fixed a `Psr\Log` path mismatch (`src/` → `Psr/Log/`)

#### Fix #7: Version Alignment

**Files:** `package.json` (root), `addons/pro/package.json`

- Root: `"version": "1.1.29"` → `"1.1.40"` (now matches `WP_MCP_AI_VERSION`)
- Pro: `"version": "1.1.9"` → `"1.1.25"` (now matches `WP_MCP_AI_PRO_VERSION`)

#### Fix #1: Release Workflow Version Sed Target

**File:** `.github/workflows/release.yml`

The `sed` command updating `WP_MCP_AI_VERSION` was targeting `mcp-ai-wpoos.php` but the constant
lives in `includes/bootstrap/constants.php`. Fixed the sed target and verification grep.

#### Fix #11: CDN Loader — SRI Integrity Hashes (Pro)

**File:** `addons/pro/includes/class-wp-mcp-ai-pro-cdn-loader.php`

**Problem:** CDN-loaded libraries had stub/empty `sha384` integrity hashes, making them vulnerable
to CDN compromise or supply-chain attacks.

**Solution:** Added real `sha384` SRI hashes for all 6 CDN libraries:
chart.js, katex, d3, axios, mathjs, prettier. Applied `integrity` + `crossorigin` attributes to
both JS and CSS registrations (previously only JS).

---

## Validation

After implementation, all fixes were validated with:

```bash
composer run lint:base    # PHPCS — base plugin
composer run lint         # PHPCS — full codebase
composer run lint:compat  # PHP 7.4–8.3 compatibility
composer run test         # PHPUnit — full test suite
```

Result: 0 new errors or warnings. No database migrations required.

---

## Rollback

Each change is atomic and independently revertible. The professional selector HMAC change is the
most impactful — if production issues arise, the handler can temporarily accept both old
`shortcode_atts` and new `policy_token` formats during a transition period.

---

## References

- Full implementation plan: [`docs/project/proposals/compliance-security-fixes-2026-07-19.md`](../project/proposals/compliance-security-fixes-2026-07-19.md)
- PR #5718: [github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5718](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5718)
- PR #5721: Profession prompt regression fix
- PR #5730: Re-revert to restore profession fix
