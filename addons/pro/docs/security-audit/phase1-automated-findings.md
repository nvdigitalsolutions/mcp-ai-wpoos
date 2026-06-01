# Security Audit: Pro Toolkits — Phase 1 Automated Findings

**Scope:** `addons/pro/includes/tools/**/*.php` (1,039 PHP files across 43 toolkits)  
**Date:** 2026-06-01  
**Method:** Automated grep/pattern scanning across all pro tool PHP files  
**Skills Applied:** `wp-security-audit`, `wp-security-deep`, `wp-security-secrets`

---

## Scan Summary

| Check | Result | Matches |
|---|---|---|
| Hardcoded API keys (`sk_live_`, `AKIA`, `ghp_`, `xoxb-`, `AIza`) | ✅ CLEAN | 0 actual secrets (8 documentation refs only) |
| `define(...KEY/SECRET/TOKEN...)` with literals | ✅ CLEAN | 0 |
| `unserialize()` on user input | ✅ CLEAN | 0 |
| CSRF on GET (`$_GET['action']` with writes) | ✅ CLEAN | 0 |
| Mass assignment (`foreach $_POST` → write functions) | ✅ CLEAN | 0 |
| `eval()` / dangerous `preg_replace` with `/e` | ✅ CLEAN | 1 (safe markdown conversion) |
| `wp_redirect`/`wp_safe_redirect` with user input | ✅ CLEAN | 0 |
| `wp_set_auth_cookie`/`wp_signon` | ✅ CLEAN | 0 |
| `__return_true` on REST permission callbacks | ✅ CLEAN | 0 |
| `$_SERVER['HTTP_*']` unsanitized | ✅ CLEAN | 2 (both properly sanitized) |
| Nonce verification | ⚠️ BY DESIGN | Tools called via registry which handles auth |
| `uniqid()` usage | ⚠️ LOW (16) | Non-security IDs only |
| `wp_rand()` usage | ⚠️ LOW (10+) | Simulation data, non-security IDs |
| `wp_generate_password()` usage | ⚠️ LOW (15+) | Temp file names, link tokens (non-security) |
| `md5()` usage | ⚠️ LOW (20) | Cache keys, file naming, dedup only |
| No `hash_equals()` anywhere | ⚠️ MEDIUM | Vault toolkit needs manual review |
| `wp_remote_get/post` with variable URL | ⚠️ MEDIUM (12) | Needs URL construction review |
| `wp_mail()` usage | ⚠️ LOW (17) | Fixed subjects, sanitized recipients |
| `ZipArchive` usage | ⚠️ MEDIUM (8) | ZipSlip check needed on 3 implementations |
| `file_get_contents`/`file_put_contents` | ⚠️ MEDIUM (50+) | Path validation review needed |
| `exec`/`shell_exec` usage | 🔴 HIGH (4 actual) | Command injection risk |
| `maybe_unserialize` usage | ⚠️ LOW (4) | Reading stored data — generally safe |
| `include`/`require` with variables | ⚠️ LOW | All in `init.php` with fixed paths |
| ABSPATH guard | ✅ PRESENT | Confirmed on scanned files |

---

## 🔴 HIGH — Needs Immediate Manual Review

### 1. `document-generation/extract-pdf-text` — `exec()` with user file paths
- **File:** `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-extract-pdf-text.php`
- **Issue:** Uses `shell_exec('which pdftotext')` and `exec($cmd, $output, $return_code)` where `$cmd` is constructed with user-supplied file paths
- **Lines:** ~505-607
- **Risk:** Command injection via specially crafted file paths

### 2. `ecommerce/export-products-report` — `exec()` for PDF
- **File:** `addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-tool-export-products-report.php`
- **Lines:** ~567-571
- **Risk:** Command injection

### 3. `ecommerce/generate-invoice-pdf` — `exec()` for PDF
- **File:** `addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-tool-generate-invoice-pdf.php`
- **Lines:** ~440-444
- **Risk:** Command injection

---

## ⚠️ MEDIUM — Needs Review

### 4. SSRF: `wp_remote_get/post` with constructed URLs
12 instances across: Telegram, Brevo, Mailjet, Mailgun, EZUite ERP, DICOMweb
- Need to verify base URLs are from trusted configs/constants, not user input

### 5. ZipSlip: ZipArchive usage in social media download tools
- `social-media/class-wp-mcp-ai-pro-tool-download-facebook-page-images.php` (~L705-727)
- `social-media/class-wp-mcp-ai-pro-tool-download-instagram-page-images.php` (~L759-781)
- `social-media/class-wp-mcp-ai-pro-tool-download-google-maps-images.php` (~L664-686)
- Need to verify ZipSlip mitigation (entry name validation, path containment)

### 6. No `hash_equals` for token comparison
- No usage found anywhere. Vault tools (`vault-access`, `vault-manage`) need review for credential comparison

### 7. Path traversal: `file_get_contents`/`file_put_contents` in architect-agent + ai-tool-builder
- `architect-agent/class-wp-mcp-ai-tool-manage-files.php` — self-editing tool
- `ai-tool-builder/class-wp-mcp-ai-tool-generate-tool-scaffold.php` — writes new tool files
- `ai-tool-builder/class-wp-mcp-ai-tool-refactor-tool-code.php` — overwrites tool files
- Need to verify path containment within `WP_CONTENT_DIR`

---

## ⚠️ LOW — Noteworthy Patterns

### 8. `uniqid()` for non-security IDs (16 instances)
Used for: temp file names, rule IDs, account IDs, goal IDs, DOM element IDs
- All non-security contexts. No action needed but document as accepted pattern.

### 9. `md5()` for cache keys and dedup (20 instances)
Used for: transient cache keys, content deduplication, file naming
- All non-security contexts. No action needed.

### 10. `maybe_unserialize` on stored data (4 instances)
- `ecommerce/abandoned-cart-recovery` — reading session data
- `infrastructure/pro-tool-cpt` — reading post meta
- `law-firm/litigation-support` — reading post meta
- Generally safe as data was written by admin/plugin, not user-controlled

---

## Next Steps: Phase 2 Manual Review Priority

| Priority | Files | Toolkits |
|---|---|---|
| 🔴 P0 | 4 files | `document-generation`, `ecommerce` (exec usage) |
| 🟡 P1 | ~15 files | `vault`, `social-media` (ZipArchive), `architect-agent` (file ops) |
| 🟢 P2 | ~12 files | SSRF URL review across 6 toolkits |
| 🔵 P3 | ~200 files | Tier 1 critical toolkits full review |
