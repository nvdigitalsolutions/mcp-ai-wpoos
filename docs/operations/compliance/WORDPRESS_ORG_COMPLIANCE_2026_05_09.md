# WordPress.org Plugin Directory Compliance Hardening — May 9, 2026

**Plugin:** NV Digital Open Operator System (oOS)
**Plugin Version:** 1.1.15 / 1.1.16
**Review Date:** 2026-05-09
**Audited By:** Automated code audit + CI-enforced gates
**Status:** ✅ All findings resolved — ready for re-submission
**Scope:** Base plugin only (`includes/`, `assets/`, `mcp-ai-wpoos.php`, `readme.txt`).
Addons (`addons/`) are separately distributed and are **not** part of the WordPress.org submission.

> **See also:** [`SUBMISSION.md`](../../SUBMISSION.md) for the authoritative per-finding reviewer-response table and CI-pipeline proof that `addons/` never enters the submission ZIP.

---

## Executive Summary

This document records the WordPress.org Plugin-Directory hardening pass completed on May 9, 2026, addressing five clusters of issues raised during the most recent automated review. All findings are resolved; every fix is locked in by an automated regression gate.

| Finding | Description | Status |
|---------|-------------|--------|
| **B3** | Inline `<script>` / `<style>` output in base-plugin admin screens | ✅ Resolved |
| **B8** | Plugin writing to `WP_CONTENT_DIR/cache/` instead of uploads | ✅ Resolved |
| **B10** | Unchecked `wp_set_current_user()` / `wp_update_user()` calls | ✅ Resolved |
| **B13** | Missing `wp_unslash()` on `$_POST` values; unexplained `phpcs:ignore` | ✅ Resolved |
| **Build / Vendor** | Dev packages surviving in `vendor/` on production build | ✅ Resolved |

Continuity with the April 15, 2026 audit: **333 capability checks, 147 nonce verifications, 200+ sanitization instances, 500+ output-escaping instances** remain unchanged. No regressions were introduced.

---

## Scope

The WordPress.org submission ZIP is built exclusively from the base plugin tree. The `addons/` directory is never included. This is enforced by three pipeline guards in `.github/workflows/release.yml`:

1. `find build/${PLUGIN_SLUG} -type d -name 'addons'` must return nothing.
2. `unzip -l mcp-ai-wpoos-{version}.zip | grep -E '(^|/)addons/'` must return nothing.
3. `WordPress.org Plugin Check` job (`wp plugin check`) runs against the base-only ZIP; any `ERROR`-severity finding fails the build.

For the complete separation story, see [`SUBMISSION.md` — "How separation is enforced"](../../SUBMISSION.md).

---

## Findings Addressed in This Pass

### B3 — Inline `<script>` / `<style>` Output Removed

**Reviewer description:** Plugin admin screens contained raw `echo '<script>'` and `echo '<style>'` blocks that must be enqueued through the WordPress script/style API.

**Root cause:** Dead fallback branches targeting WordPress < 5.7 (the plugin now requires WordPress 6.0+) that still emitted config variables and small admin style blocks inline.

**Fix summary:**
- Removed all dead WP < 5.7 fallback branches that generated inline `<script>` tags.
- Converted multi-line `echo '<script>'` config blocks to `wp_print_inline_script_tag()` (WP 5.7+ API) attached to `admin_enqueue_scripts`.
- Moved the sole inline CSS block (admin telemetry) to `wp_add_inline_style()` hooked on `admin_enqueue_scripts`.

**Files touched:**
- `includes/admin/class-wp-mcp-ai-admin-settings.php`
- `includes/admin/class-wp-mcp-ai-admin-telemetry.php`
- `includes/class-wp-mcp-ai-shortcode.php`

**Proof:** `WordPress.org Plugin Check` CI job (`wp plugin check --format=json`) reports zero `INLINE_SCRIPT` or `INLINE_STYLE` errors against the base-only ZIP.

---

### B8 — Filesystem Cache Directory Relocated

**Reviewer description:** Plugin created a cache directory at `WP_CONTENT_DIR/cache/wp-mcp-ai`, which is outside the plugin's permitted write space and conflicts with other caching plugins.

**Root cause:** The cache service resolved its base path from `WP_CONTENT_DIR . '/cache/wp-mcp-ai'`, a path shared with object-cache plugins and not writeable on all hosts.

**Fix summary:**
- Cache base directory changed to `wp_upload_dir()['basedir'] . '/wp-mcp-ai-cache'`.
- The uploads directory is always writable on a valid WordPress install and is the accepted location for plugin-managed files.
- Debug-log path construction retains `WP_CONTENT_DIR` only where WordPress Core itself defines that path (parity with `WP_DEBUG_LOG`).
- Existing sites are not broken: the new path is created on first use; nothing reads from the old path.

**Files touched:**
- `includes/cache/class-wp-mcp-ai-cache-service.php`

**Proof:** `WordPress.org Plugin Check` (`wp plugin check`) reports zero `PLUGIN_REVIEW_FILESYSTEM` warnings. `PHPCS WordPress.WP.AlternativeFunctions` passes clean.

---

### B10 — `wp_set_current_user()` Hardening

**Reviewer description:** Several base-plugin code paths called `wp_set_current_user()` without first confirming the user exists, creating a potential to corrupt the global `$current_user` state (including multisite edge cases where the user may not belong to the current blog).

**Root cause:** Direct `wp_set_current_user( $user_id )` calls without prior `get_userdata()` validation, and without a multisite blog-membership guard.

**Fix summary:**
- Introduced `WP_MCP_AI_User_Context_Helper::safe_set_current_user( $user_id )`:
  - Calls `get_userdata( $user_id )` first; returns `false` immediately if the user does not exist.
  - On multisite, calls `is_user_member_of_blog( $user_id )` and returns `false` if the user does not belong to the current blog before touching global state.
  - Only calls `wp_set_current_user()` when both checks pass.
- All base-plugin call sites of the bare `wp_set_current_user()` replaced with the helper.

**Files touched:**
- `includes/helpers/class-wp-mcp-ai-user-context-helper.php` *(new file)*
- `includes/class-wp-mcp-ai-rest.php`
- `includes/class-wp-mcp-ai-plugin.php`

**PHPUnit coverage:** `tests/test-user-context-helper.php` — covers: user-not-found guard, single-site happy path, multisite not-a-member guard, multisite member happy path.

---

### B13 — Input Sanitization & Nonce Verification Hardening

**Reviewer description:** (a) `$_POST` values read in the Approvals AJAX handler lacked `wp_unslash()` before sanitization, as required by WPCS `WordPress.Security.ValidatedSanitizedInput`. (b) Several bare `// phpcs:ignore NonceVerification.Recommended` comments in the DAG builder gave no justification, making them unacceptable to the Plugin Review team.

**Root cause:**
- `class-wp-mcp-ai-admin-approvals.php`: `sanitize_text_field( $_POST['approval_id'] )` — missing `wp_unslash()` wrapper.
- `class-wp-mcp-ai-admin-dag-builder.php`: read-only `$_GET` accesses (display-only, no state change) had bare `phpcs:ignore` lines.

**Fix summary:**
- Approvals handler: changed to `sanitize_text_field( wp_unslash( $_POST['approval_id'] ) )` (and the same pattern for `resolution` and `note`).
- DAG builder: bare `// phpcs:ignore` lines replaced with inline comments that explain the access is display-only / nonce-verified at the top of the page load.
- Full AJAX handler audit: all 49 base-plugin AJAX handlers carry `check_ajax_referer()` — confirmed by PHPCS scan; 0 unguarded handlers remain.

**Files touched:**
- `includes/admin/class-wp-mcp-ai-admin-approvals.php`
- `includes/admin/class-wp-mcp-ai-admin-dag-builder.php`

**Proof:** `composer run lint:base` produces zero `NonceVerification` or `ValidatedSanitizedInput` PHPCS errors in the base tree.

---

### Build / Vendor — Production-Only Composer Autoloader

**Description:** The `vendor/` directory in prior builds included dev-only packages (`phpunit/`, `phpcs/`, `wp-phpunit/`, etc.), meaning the submission ZIP was larger than necessary and contained developer tooling that served no runtime purpose.

**Fix summary:**
- Build pipeline updated to run `composer install --no-dev --classmap-authoritative` (no separate `dump-autoload` step needed).
- `vendor/composer/installed.json` now reports `"dev": false` with an empty `dev-package-names` array.
- `vendor/composer/autoload_real.php` sets `setClassMapAuthoritative(true)`, skipping PSR-4 filesystem fallback lookups at runtime.
- Net classmap diff: −6,761 / +279 lines as dev-only packages drop out of `vendor/`.

**Files touched:**
- `vendor/` *(regenerated)*
- `composer.json` *(no-dev constraint comment)*

**Proof:** `unzip -l mcp-ai-wpoos-{version}.zip | grep -E 'vendor/(phpunit|phpcs|wp-phpunit)'` returns empty. The production ZIP contains 677 production-only classes.

---

## What Is Unchanged from the April 15, 2026 Audit

The following metrics from `docs/compliance/WORDPRESS_ORG_COMPLIANCE_2026_04_15.md` are confirmed unchanged by a post-fix scan:

- **Capability checks:** 333+ `current_user_can()` calls across base plugin (`grep -r "current_user_can" includes/ | wc -l`)
- **Nonce verifications:** 147+ `check_ajax_referer()` / `wp_verify_nonce()` calls (`grep -rE "check_ajax_referer|wp_verify_nonce" includes/ | wc -l`)
- **Sanitization instances:** 200+ `sanitize_*` / `absint` / `wp_unslash` calls (`grep -rE "sanitize_|absint|wp_unslash" includes/ | wc -l`)
- **Output-escaping instances:** 500+ `esc_html` / `esc_attr` / `esc_url` / `wp_kses` calls (`grep -rE "esc_html|esc_attr|esc_url|wp_kses" includes/ | wc -l`)
- **All 13 WordPress.org Plugin Guidelines:** ✅ PASS (see `WORDPRESS_ORG_COMPLIANCE_2026_04_15.md` for the full per-guideline table)
- **Inline text-domain:** Single `mcp-ai-wpoos` text domain — enforced by `WordPress.WP.I18n` PHPCS gate + `Assert single text domain` CI step
- **HEREDOC syntax:** 0 occurrences in base tree — enforced by `WordPress.PHP.PreventUseOfHereDocSyntax` phpcs rule

---

## CI / Regression Gates

The following automated gates in the repository prevent regressions for every finding resolved in this pass:

| Gate | Finding Locked | Configuration |
|------|---------------|---------------|
| `WordPress.org Plugin Check` job in `release.yml` | B3 (inline script/style) | `wp plugin check --format=json` against base-only ZIP; `ERROR` = build failure |
| `WordPress.WP.AlternativeFunctions` PHPCS rule | B8 (filesystem path) | `phpcs.xml.dist` |
| `WordPress.Security.ValidatedSanitizedInput` PHPCS rule | B13 (wp_unslash) | `phpcs.xml.dist` |
| `WordPress.Security.NonceVerification` PHPCS rule | B13 (nonce guard) | `phpcs.xml.dist` |
| `WordPress.PHP.PreventUseOfHereDocSyntax` PHPCS rule | General hygiene | `phpcs.xml.dist` |
| `WordPress.WP.I18n` PHPCS rule + `Assert single text domain` CI step | Text domain | `phpcs.xml.dist` + `release.yml` |
| `Assert no addons/ in base-only build` CI step | Scope separation | `release.yml` |
| PHPUnit REST permission-callback walker | B12 (REST `permission_callback`) | `tests/test-rest-permission-callbacks.php` |
| PHPUnit user-context-helper suite | B10 (wp_set_current_user) | `tests/test-user-context-helper.php` |

---

## Cross-References

| Resource | Purpose |
|----------|---------|
| [`SUBMISSION.md`](../../SUBMISSION.md) | Authoritative per-finding reviewer-response table (A1–A4, B3, B8, B10, B12, B13, B14, vendor remap) |
| [`docs/compliance/WORDPRESS_ORG_COMPLIANCE_2026_04_15.md`](WORDPRESS_ORG_COMPLIANCE_2026_04_15.md) | April 15, 2026 full guideline-by-guideline audit (all 13 guidelines — baseline for continuity assertions above) |
| [`docs/compliance/WORDPRESS_ORG_COMPLIANCE_2026_04_09.md`](WORDPRESS_ORG_COMPLIANCE_2026_04_09.md) | April 9, 2026 remediation pass |
| [`docs/compliance/WORDPRESS_ORG_COMPLIANCE_2026_04_02.md`](WORDPRESS_ORG_COMPLIANCE_2026_04_02.md) | April 2, 2026 remediation pass |
| [`docs/compliance/WORDPRESS_ORG_COMPLIANCE_2026_03_24.md`](WORDPRESS_ORG_COMPLIANCE_2026_03_24.md) | March 24, 2026 remediation pass |
| [`docs/compliance/SECURITY_AUDIT_2026_04.md`](SECURITY_AUDIT_2026_04.md) | April 2026 security audit summary (no Critical; 5 High, 3 Fixed, 2 Partially Fixed) |
| `CHANGELOG.md` | Version-bump entry for the corresponding release |
| `.github/workflows/release.yml` | Release pipeline (base-only ZIP build, Plugin Check job, `Assert no addons/` guard) |
