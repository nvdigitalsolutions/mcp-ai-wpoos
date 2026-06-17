# Security Audit: Pro Toolkits — Phase 2 Manual Review (P0–P1)

**Scope:** Manual review of HIGH and MEDIUM findings from Phase 1  
**Date:** 2026-06-01  
**Reviewer:** Automated analysis + manual code inspection  
**Skills Applied:** `wp-security-audit`, `wp-security-deep`, `wp-security-secrets`

---

## Executive Summary

After detailed manual review of the 23 flagged findings from Phase 1, **zero critical security vulnerabilities** were found. Most initial flags were false positives — the code patterns are defensive. Two real (but lower-severity) issues were identified in the vault toolkit.

---

## 🔴 Previously Flagged HIGH — Reviewed & Downgraded

### 1. `extract-pdf-text` — `exec()` usage → **LOW (No Vulnerability)**
- **File:** `document-generation/class-wp-mcp-ai-tool-extract-pdf-text.php` (L505–607)
- **Analysis:** Both `exec()` calls properly escape all user-controlled values with `escapeshellarg()`. The pdftotext command uses `(int)` cast on `$max_pages` and `escapeshellarg()` on file paths. The Node.js service path is a fixed constant.
- **Recommendation:** Migrate to `proc_open` via `wp_mcp_ai_run_shell()` to match project convention per `CLAUDE.md`. Not a security fix — a consistency improvement.

### 2. `export-products-report` — `exec()` usage → **LOW (No Vulnerability)**
- **File:** `ecommerce/class-wp-mcp-ai-tool-export-products-report.php` (L567–571)
- **Analysis:** `escapeshellcmd('node')` + `escapeshellarg($script_path)` + `escapeshellarg($input_data)`. All arguments properly sanitized. `$input_data` is `wp_json_encode()` of controlled data.
- **Recommendation:** Same as above — migrate to `proc_open` for consistency.

### 3. `generate-invoice-pdf` — `exec()` usage → **LOW (No Vulnerability)**
- **File:** `ecommerce/class-wp-mcp-ai-tool-generate-invoice-pdf.php` (L440–444)
- **Analysis:** Identical safe pattern to #2.
- **Recommendation:** Same as above.

---

## 🟡 Previously Flagged MEDIUM — Reviewed

### 4. ZipArchive — ZipSlip Risk → **CLEARED (No Vulnerability)**
- **Files:** `social-media/download-facebook-page-images.php`, `download-instagram-page-images.php`, `download-google-maps-images.php`
- **Analysis:** All three use `ZipArchive::addFromString()` to CREATE archives from downloaded images. They do NOT use `extractTo()`. Entry names are constructed with `sanitize_file_name()` from programmatic values (not user-uploaded archive entries). No ZipSlip vector exists.
- **Verdict:** CLEARED.

### 5. SSRF: `wp_remote_get/post` with constructed URLs → **CLEARED**
- **12 instances reviewed** across: Brevo, Mailjet, Mailgun, Telegram, EZUite ERP, DICOMweb
- **Analysis:** All use:
  - Hardcoded API host constants (Brevo, Mailjet, Mailgun, EZUite)
  - Fixed hosts with variable path segments properly encoded (Telegram: `rawurlencode($token)` on `api.telegram.org`)
  - Admin-configured base URLs (DICOMweb — requires `manage_options`)
- **No user-controlled full URLs found.** No SSRF vector.
- **Verdict:** CLEARED.

### 6. No `hash_equals` usage → **CLEARED (Not Needed)**
- **Analysis:** The vault toolkit uses integer comparisons (`get_current_user_id() != $post->post_author`) which don't benefit from timing-safe comparison. No token/signing comparisons exist in the tool code. Token-based auth happens at the framework level, not in individual tools.
- **Verdict:** CLEARED — `hash_equals` not needed at tool level.

### 7. Path Traversal: `file_get_contents`/`file_put_contents` → **LOW (Generally Safe)**
- **Files:** `architect-agent/manage-files.php`, `ai-tool-builder/generate-tool-scaffold.php`, `ai-tool-builder/refactor-tool-code.php`
- **Analysis:** 
  - `architect-agent`: Uses `WP_CONTENT_DIR` containment check before reads/writes
  - `ai-tool-builder`: Scaffolds within `WP_CONTENT_DIR` with path validation (`0 === strpos(...)`)
  - `phpcs:ignore` comments document the intentional use
- **Verdict:** LOW risk — path validation present. Recommend adding explicit allowlist of writable directories.

---

## 🔴 NEW FINDING — Vault Toolkit

### V-1: Canonical Return Envelope Violation (MEDIUM)
- **Files:** `vault/class-wp-mcp-ai-pro-tool-vault-access.php`, `vault/class-wp-mcp-ai-pro-tool-vault-manage.php`
- **Issue:** Both tools return `array( 'success' => false, 'error' => '...' )` on failures instead of `new WP_Error( 'code', 'message' )`. This violates the project's `WPMCPAI.Tools.CanonicalReturnEnvelope` PHPCS sniff.
- **Affected lines in `vault-access.php`:** 107–110, 116–119, 136–139, 196–199, 212–215, 232–235, 242–245
- **Affected lines in `vault-manage.php`:** ~15 locations
- **Severity:** MEDIUM — Not a direct vulnerability, but could cause inconsistent error handling upstream (registry, REST, CLI callers expect `WP_Error` for failures).
- **Fix:** Replace all `array( 'success' => false, 'error' => ... )` with `new WP_Error( 'wp_mcp_ai_vault_error', __( '...', 'mcp-ai-wpoos-pro' ) )`

### V-2: Loose Comparison on Ownership Check (LOW)
- **Files:** Both vault tools
- **Issue:** `get_current_user_id() != $post->post_author` uses loose comparison (`!=`). Should be strict (`!==`).
- **Severity:** LOW — Both values are integers from WordPress functions, so no practical exploitation. Fix for correctness.
- **Fix:** Replace `!=` with `!==`

### V-3: Password Field Not Sanitized (BY DESIGN — ACCEPTABLE)
- **File:** `vault/class-wp-mcp-ai-pro-tool-vault-manage.php` (L411)
- **Issue:** `$data['password'] = $arguments['password']; // Don't sanitize password.`
- **Analysis:** Intentional — sanitizing would modify/corrupt the password. Data is AES-256-GCM encrypted at rest. Password is never echoed/displayed unencrypted. This is the correct approach.
- **Verdict:** BY DESIGN — No action needed.

---

## Positive Findings (What's Done Well)

1. ✅ **Strong capability enforcement**: `manage_options` required for vault access, `edit_posts` for content tools
2. ✅ **Consistent input sanitization**: `sanitize_text_field`, `absint`, `esc_url_raw`, `sanitize_email` used correctly
3. ✅ **Ownership checks**: Vault items verify `post_author` before returning decrypted data
4. ✅ **AES-256-GCM encryption**: Credentials encrypted at rest via `WP_MCP_AI_Vault_Encryption_Service`
5. ✅ **Private post status**: Vault items stored as `post_status = 'private'`
6. ✅ **Properly structured REST endpoints**: All have `permission_callback`, args schemas with `sanitize_callback`
7. ✅ **No hardcoded secrets**: All API keys loaded from options/config
8. ✅ **ABSPATH guards**: Present on all reviewed files
9. ✅ **Proper error handling**: Most tools use `WP_Error` correctly (vault is the exception)
10. ✅ **Prepared queries**: Uses `WP_Query`/`get_post`/`get_post_meta` — no raw SQL

---

## Phase 2 Summary

| Severity | Count | Status |
|---|---|---|
| 🔴 HIGH vulnerabilities | 0 | — |
| 🟡 MEDIUM issues | 1 | Vault return envelope violation |
| 🟢 LOW issues | 1 | Vault loose comparison |
| ✅ False positives cleared | 21 | Reviewed and documented |

---

## Recommendations

### Immediate (P0)
None. No critical vulnerabilities found.

### Short-term (P1)
1. Fix vault toolkit return envelope (V-1) — replace `array('success' => false, ...)` with `WP_Error`
2. Fix vault toolkit strict comparison (V-2) — `!=` → `!==`
3. Migrate `exec()` calls to `wp_mcp_ai_run_shell()` / `proc_open` for consistency (3 files)

### Medium-term (P2)
1. Proceed with Tier 1 critical toolkit manual review (healthcare, site-creator, financial-planning, law-firm)
2. Add allowlist of writable paths for architect-agent and ai-tool-builder file operations
3. Run `composer audit` for third-party dependency CVEs

### Long-term (P3)
1. Complete Tier 2–4 toolkit reviews per the audit plan
2. Add custom PHPCS sniff for `exec()` usage without `escapeshellarg()` on all arguments
3. Automated regression tests for vault encryption/decryption round-trip
