# WordPress.org Compliance — Traceability Matrix

> **Purpose:** Quick-reference table mapping each WordPress.org rejection reason to the specific commit, PR, or release that fixed it, with verification commands.
> **Last Updated:** June 2, 2026

---

## How to Use This

For each issue flagged by the .org plugin review team, this document shows:
1. **The issue** — what was flagged
2. **The fix** — what was changed
3. **The release** — which version landed the fix
4. **Verification** — how to confirm the fix is in the current code

---

## Critical Issues (All Resolved ✅)

| # | Issue | Fix | Release | Files Changed | Verification |
|---|---|---|---|---|---|
| 1 | **Pro Dashboard enabled by default** (trial/freemium model) | Added constant `WP_MCP_AI_PRO_DASHBOARD_ENABLED` defaulting to `true` | PR #3741 (v1.1.1) | `mcp-ai-wpoos.php` | `grep -n "WP_MCP_AI_PRO_DASHBOARD_ENABLED" mcp-ai-wpoos.php` → should find it |
| 2 | **Pro feature gating** (features blocked until license purchase) | Removed `is_pro_active()` license checks. Features activate based on code presence. | PR #3741 + v1.1.2 | `class-wp-mcp-ai-webworker-enqueue.php`, `class-wp-mcp-ai-optional-components.php` | `grep -rn "is_pro_active\|has_feature" includes/` → no license checks remain |
| 3 | **Pro integration settings in base plugin** (Mailjet, Analytics, Yahoo, ESPN, etc.) | Moved 12 pro integration settings to Pro addon. Pro adds its own settings section when active. | v1.1.2 | `class-wp-mcp-ai-section-integrations.php` (removed 12 settings), `class-wp-mcp-ai-section-pro-integrations.php` (new Pro file) | `grep -n "mailjet\|ita_tariff\|plaid\|yahoo\|espn" includes/admin/sections/class-wp-mcp-ai-section-integrations.php` → should NOT find them |
| 4 | **Hardcoded menu positions** (conflicts with other plugins) | Changed all positions to `null` (automatic WordPress positioning) | PR #3741 + v1.1.2 | 6 menu registrations | `grep -rn "add_menu_page\|add_submenu_page" includes/ \| grep -v "null"` → no hardcoded positions remain |
| 5 | **Plugin directory storage** (data lost on update) | Vectorizer and knowledge base use `uploads/` directory | PR #3741 | `class-wp-mcp-ai-optional-components.php` | Check that upload paths use `wp_upload_dir()` |
| 6 | **Forced attribution** (Open-Meteo "Powered by") | Requires admin opt-in. Defaults to hidden. | PR #3741 | `class-wp-mcp-ai-tool-get-open-meteo-forecast.php` | `grep -n "powered_by\|attribution" includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php` |
| 7 | **AI-generated files in deployment** | Excluded via `.distignore` | PR #3741 | `.distignore` | Check `.distignore` for AI-file patterns |

---

## Structural Issues (All Resolved ✅)

| # | Issue | Fix | Release | Verification |
|---|---|---|---|---|
| 8 | **HEREDOC/NOWDOC syntax** (7 instances) | Converted to string concatenation | PR #3741 | `grep -rn "<<<" includes/` → no HEREDOC/NOWDOC |
| 9 | **Inline script/style tags** (8 high-impact files) | Converted to `wp_enqueue_script()` / `wp_enqueue_style()`. 11 new asset files created. | PR #3741 | `grep -rn "<script>\|<style>" includes/ --include="*.php" \| grep -v "wp_enqueue\|wp_add_inline"` → minimal results |
| 10 | **Generic function/variable names** | Verified all prefixed with `wp_mcp_ai_` or `WP_MCP_AI_` | PR #3741 | `grep -rn "function [^w]\|function w[^p]" includes/ --include="*.php"` |

---

## March 2026 Automated Review (v1.1.3)

| # | Issue | Fix | Verification |
|---|---|---|---|
| 11 | **License gating in `is_pro_active()` / `has_feature()`** | Removed all license key checks from these functions | `grep -rn "is_pro_active\|has_feature" includes/` |
| 12 | **404 URLs in readme.txt** (3 documentation URLs) | Updated to match reorganized docs paths | Check `readme.txt` for dead links |
| 13 | **Symfony dependency audit** | 4 packages updated to v6.4.34. 0 advisories. | `composer audit` → clean |
| 14 | **External services not documented** | 31 services documented in `EXTERNAL_SERVICES.md` | `wc -l docs/EXTERNAL_SERVICES.md` → comprehensive |
| 15 | **`file_put_contents` unrestricted** | Restricted to `uploads/` directory. `WP_Filesystem` used where appropriate. | `grep -rn "file_put_contents\|fwrite\|fopen" includes/ --include="*.php"` → all paths use `wp_upload_dir()` |
| 16 | **`$_SERVER` unsanitized usage** | All `$_SERVER` access sanitized | `grep -rn "\$_SERVER\[" includes/ --include="*.php" \| grep -v "sanitize\|esc_"` → all sanitized |
| 17 | **`register_setting` missing `sanitize_callback`** | All `register_setting` calls now include `sanitize_callback` | `grep -rn "register_setting" includes/ --include="*.php" \| grep -v "sanitize_callback"` → all have it |

---

## April 2026 Reviews (v1.1.7–v1.1.8)

| # | Issue | Fix | Verification |
|---|---|---|---|
| 18 | **404 URLs fixed** (Trade.gov privacy, Mailjet terms) | Corrected in `readme.txt` | Check URLs in `readme.txt` |
| 19 | **13 mislabeled capability flags** (tools marked `local-only` that call external APIs) | Changed to `external-api`. 2 loopback tools kept `local-only` with clarifying comments. | `grep -rn "local-only" includes/tools/ --include="*.php"` → only true local operations |
| 20 | **CLI export path traversal** (`wp mcp-ai assistant export --file=`) | Restricted to `uploads/mcp-ai/exports/`. Path separators stripped. | Check `includes/class-wp-mcp-ai-cli-command.php` export handler |
| 21 | **File write in sync-docs** | Auto-fix removed for file-type docs. Only post-type docs auto-fix via `wp_update_post()`. | |
| 22 | **Capability flags re-audited** | All 13 WP.org Plugin Guidelines re-audited and confirmed compliant | See `WORDPRESS_ORG_COMPLIANCE_2026_04_15.md` |
| 23 | **Pro addon external services documented** | 3 Pro services added to `EXTERNAL_SERVICES.md` | |

---

## Verification Commands (One-Shot)

Run these to confirm all fixes are in the current codebase:

```bash
# 1. No freemium/trial gating
grep -rn "is_pro_active\|has_feature" includes/ --include="*.php"

# 2. No hardcoded menu positions
grep -rn "add_menu_page\|add_submenu_page" includes/ --include="*.php" | grep -v "null"

# 3. No HEREDOC/NOWDOC
grep -rn "<<<" includes/ --include="*.php"

# 4. No pro settings in base
grep -n "mailjet\|ita_tariff\|plaid\|yahoo\|espn" includes/admin/sections/class-wp-mcp-ai-section-integrations.php

# 5. All sanitize_callbacks present
grep -rn "register_setting" includes/ --include="*.php" | grep -v "sanitize_callback"

# 6. No unprepared file writes outside uploads
grep -rn "file_put_contents\|fwrite\|fopen" includes/ --include="*.php" | grep -v "wp_upload_dir"

# 7. PHPCS clean on base tree
composer run lint:base

# 8. Composer audit clean
composer audit

# 9. wp plugin-check passes
# (requires a built ZIP)
composer run build && wp plugin-check mcp-ai-wpoos.zip
```

---

## Release History

| Version | Date | Issues Resolved |
|---|---|---|
| PR #3741 (v1.1.1) | Jan 2026 | 15 issues — trial model, storage, HEREDOC, inline scripts, menu positions (partial), attribution |
| v1.1.2 | Feb 16, 2026 | 20 issues — architectural correction, complete menu positions, pro settings moved, misleading labels fixed |
| v1.1.3 | Mar 4, 2026 | Automated review — external services, sanitization, library versions, license gating, URLs, WPCS sweep |
| v1.1.7 | Apr 9, 2026 | 404 URLs, capability flags, CLI export path, file writes |
| v1.1.8 | Apr 15, 2026 | Full 13-guideline re-audit, Pro external services documented |
| v1.1.10 | Apr 27, 2026 | April security audit — 50 findings, 0 Critical |
| v1.1.15–v1.1.16 | May 9, 2026 | Inline scripts, cache directory, user hardening, input sanitization, production builds |
| v1.1.21–v1.1.22 | May 19–23, 2026 | Pre-submission code review — 1 Critical + 5 Warnings resolved. Addons PHPCS 93% reduction. |
| v1.1.25 | May 31, 2026 | Unified Blueprint System, Cloudways Toolkit, CRM Toolkit, Chat UI, Unix-theory reorg. All prior compliance maintained. |
| v1.1.26 | June 3, 2026 | Cross-platform extraction engine (Phases 0–2), site-builder node-graph pipeline, SPA a11y hardening, screenshot & docs overhaul, form submissions data source, Cloudways Dashboard SPA, Laravel/Craft CMS adapters, pro toolkits security audit (9 HIGH fixed), reviewer onboarding docs, Docker/test/infra fixes. |
| v1.1.27 | June 5, 2026 | Real-time SSE streaming, 35 new OOS core tools, JFB submission tools 8 fixes, Extended Cognition vision recognition, Graphify capability fence, DeepSeek agentic tool handling, doc link fixes, June 2026 model pricing update, restructuring proposals v3.0. |

---

**Related documents:** [WORDPRESS_ORG_COMPLIANCE_COMPLETE.md](compliance/WORDPRESS_ORG_COMPLIANCE_COMPLETE.md) · [FOR_REVIEWERS.md](FOR_REVIEWERS.md) · [SECURITY_POSTURE.md](SECURITY_POSTURE.md)
