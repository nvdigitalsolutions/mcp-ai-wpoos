# WordPress.org Plugin Directory Compliance — April 9, 2026

**Review ID:** AUTO nvdigital-open-operator-system-oos/vsamtani/25Dec25/T17 9Apr26/3.9.1RC1 (P0TDX269399HGN)
**Date:** 2026-04-09
**Plugin Version:** 1.1.7

---

## Summary

This document tracks the remediation of all issues identified by the WordPress.org automated review system on April 9, 2026. Each issue is listed below with the specific fix applied and the files modified. An additional proactive audit was performed to identify and fix similar issues across the entire base plugin.

---

## Issue 1: Invalid / 404 URLs in readme.txt

**Guideline:** All URLs declared in the plugin (Terms/Privacy) must return valid HTTP responses.

**Finding:** Two URLs in the External Services section returned HTTP 404:
- `https://www.trade.gov/privacy` (ITA Tariff Rates API — Privacy Policy)
- `https://www.mailjet.com/legal/terms-of-use/` (Mailjet API — Terms of Service)

**Fix Applied:**

| File | Old (404) | New (200) |
|------|-----------|-----------|
| `readme.txt` line 810 | `https://www.trade.gov/privacy` | `https://www.trade.gov/privacy-program` |
| `readme.txt` line 880 | `https://www.mailjet.com/legal/terms-of-use/` | `https://www.mailjet.com/legal/terms/` |

**Proactive Audit:** All Terms/Privacy URLs in readme.txt were verified — no other 404s found.

**Status:** ✅ Fixed

---

## Issue 2: Undocumented Use of a Third-Party / External Service

**Guideline:** Plugins must disclose all external service connections with service description, data sent, when, terms of service, and privacy policy links.

**Finding:** The automated reviewer flagged two external service calls:
1. **Auth0 API** — `includes/integrations/class-wp-mcp-ai-integration-auth0-github.php:321` makes requests to `https://{domain}/api/v2/users/{subject}`.
2. **GDACS API** — `includes/tools/class-wp-mcp-ai-tool-get-gdacs-events.php:114-120` makes requests to `https://www.gdacs.org/gdacsapi/api/events/geteventlist/MAP`.

**Resolution:** Both services were **already documented** in the `== External Services ==` section of readme.txt:
- **Auth0** → Service #21 (lines 780–786): includes service URL, data sent, when, terms of service, and privacy policy links.
- **GDACS** → Service #23 (lines 796–802): includes service URL, data sent, when, terms of service, and privacy policy links.

No readme changes were required. The automated tools did not cross-reference the existing documentation.

**Additional Fix — GDACS capability flag:** The GDACS tool's `get_capability_flags()` method incorrectly declared `'local-only'` despite making external HTTP calls.

| File | Change |
|------|--------|
| `includes/tools/class-wp-mcp-ai-tool-get-gdacs-events.php` | `'local-only'` → `'external-api'` in `get_capability_flags()` |

**Additional Proactive Fix — Comprehensive capability flag audit:** A full audit found **12 additional base tools** that declared `'local-only'` but actually make external HTTP requests. All were corrected:

| Tool file | External service | Flag change |
|-----------|-----------------|-------------|
| `class-wp-mcp-ai-tool-get-nhc-active-storms.php` | NOAA NHC API | `'local-only'` → `'external-api'` |
| `class-wp-mcp-ai-tool-get-site-health.php` | WordPress.org API | `'local-only'` → `'external-api'` |
| `class-wp-mcp-ai-tool-generate-auth0-token.php` | Auth0 OAuth API | `'local-only'` → `'external-api'` |
| `class-wp-mcp-ai-tool-crawl4ai-price-lookup.php` | Crawl4AI endpoint | `'local-only'` → `'external-api'` |
| `class-wp-mcp-ai-tool-run-openai-external-action.php` | OpenAI API | `'local-only'` → `'external-api'` |
| `class-wp-mcp-ai-tool-purge-cloudflare-cache.php` | Cloudflare API | `'local-only'` → `'external-api'` |
| `class-wp-mcp-ai-tool-purge-varnish-cache.php` | Varnish server | `'local-only'` → `'external-api'` |
| `class-wp-mcp-ai-tool-reliefweb-reports.php` | ReliefWeb API | `'local-only'` → `'external-api'` |
| `class-wp-mcp-ai-tool-query-remote-site.php` | Remote WordPress sites | `'local-only'` → `'external-api'` |
| `class-wp-mcp-ai-tool-store-agent-context.php` | User-provided URLs | `'local-only'` → `'external-api'` |
| `class-wp-mcp-ai-tool-create-woo-product.php` | External brand lookup | `'local-only'` → `'external-api'` |
| `class-wp-mcp-ai-tool-image-base.php` | External image URLs | `'local-only'` → `'external-api'` |

Two tools that use `wp_remote_*` only for **loopback / internal** HTTP were verified correct and kept as `'local-only'` with clarified comments:
- `class-wp-mcp-ai-tool-invoke-jetengine-route.php` — dispatches to local REST API
- `class-wp-mcp-ai-tool-trigger-all-import.php` — triggers local site import via `home_url()`

Two tools that return external URLs but make **no server-side HTTP calls** were kept as `'local-only'` with clarified comments:
- `class-wp-mcp-ai-tool-open-openai-logs.php` — returns `platform.openai.com/logs` link
- `class-wp-mcp-ai-tool-open-openai-usage.php` — returns `platform.openai.com/usage` link

All 12 corrected tools were already documented in the readme.txt `== External Services ==` section; no new service disclosures were needed.

**Status:** ✅ Fixed

---

## Issue 3: Saving Data in the Plugin Folder

**Guideline:** Plugins must not write data to the plugin folder. Use the Settings API, media uploader, or the uploads directory (under a plugin-specific subfolder).

**Finding:** `includes/cli/class-wp-mcp-ai-cli-assistant-command.php:443` — `file_put_contents($file, $json)` allowed the `wp mcp-ai assistant export --file=` command to write to any path within the uploads directory.

**Fix Applied:**

| File | Change |
|------|--------|
| `includes/cli/class-wp-mcp-ai-cli-assistant-command.php` | `--file` parameter changed from accepting full paths to only a bare filename. All exports are written exclusively to `wp-content/uploads/mcp-ai/exports/`. Path separators stripped via `sanitize_file_name( basename( $file ) )`. Export directory created via `wp_mkdir_p()` on demand. PHPDoc updated accordingly. |

**Additional Fix — sync-docs auto-fix removed:**

| File | Change |
|------|--------|
| `includes/slash-commands/commands/class-wp-mcp-ai-slash-command-sync-docs.php` | Removed `elseif ('file' === $doc['type'])` branch that wrote auto-fixed content back to README files in plugin/theme directories via `file_put_contents()`. Auto-fix now only applies to post-type docs (saved via `wp_update_post()`). Explanatory comment added. |

**Full Audit of File Write Operations:**

All `file_put_contents()`, `fopen()`, and `fwrite()` calls in the base plugin (`includes/`) were audited:

| Location | Writes to | Status |
|----------|-----------|--------|
| Image/chart/media tools | `wp_upload_dir()['path']` | ✅ OK |
| Report generator | `uploads/mcp-ai-reports/` | ✅ OK |
| Workflow commands | `uploads/mcp-ai/workflows/` | ✅ OK |
| Skill registry | `uploads/wp-mcp-ai-skills/` | ✅ OK |
| Custom tool loader | `uploads/wp-mcp-ai-custom-tools/` | ✅ OK |
| Profession playbook seeder | `uploads/wp-mcp-ai/profession-playbooks/` | ✅ OK |
| Temp file operations | `wp_tempnam()` / `sys_get_temp_dir()` | ✅ OK |
| CLI/MCP transport | `STDOUT` / `STDERR` | ✅ OK |
| Logger (error log prune) | `ini_get('error_log')` (PHP error log path) | ✅ OK |
| CLI assistant export | `uploads/mcp-ai/exports/` | ✅ Fixed |
| Sync-docs auto-fix | Plugin/theme directories | ✅ Removed |

**No file writes to the plugin directory remain.** All writes target the WordPress uploads directory (under plugin-specific subdirectories), temp directories, or standard output streams.

**Status:** ✅ Fixed

---

## Proactive Audit — Additional Checks

Beyond the three reviewer-flagged issues, the following compliance areas were proactively audited:

| Check | Result |
|-------|--------|
| ABSPATH guards on all PHP files | ✅ All files have `if ( ! defined( 'ABSPATH' ) ) { exit; }` |
| Text domain consistency | ✅ All `__()`, `_e()`, etc. use `'mcp-ai-wpoos'` |
| External CDN scripts in `wp_enqueue_*` | ✅ No external CDN URLs in base plugin enqueue calls |
| `file_get_contents()` on remote URLs | ✅ Not used; `wp_remote_get()` used instead |
| Obfuscated code (`eval`, `base64_decode`) | ✅ No obfuscated code; `base64_decode` used legitimately for API binary data |
| `wp_redirect()` followed by `exit` | ✅ All redirects properly followed by `exit;` |
| Nonce verification on AJAX handlers | ✅ All `wp_ajax_*` handlers verify nonces |
| `$wpdb->prepare()` for all queries | ✅ All dynamic queries use prepared statements |
| Sanitization of `$_GET`, `$_POST`, `$_REQUEST` | ✅ All superglobal access uses `sanitize_text_field()`, `absint()`, etc. |

---

## Summary Checklist

- [x] Two broken URLs in readme.txt corrected (Trade.gov, Mailjet)
- [x] All external services verified as documented in readme.txt (Auth0, GDACS)
- [x] GDACS tool capability flag corrected (`'local-only'` → `'external-api'`)
- [x] 12 additional tool capability flags corrected (`'local-only'` → `'external-api'`)
- [x] 2 additional tool capability flag comments clarified (URL-returning tools)
- [x] CLI assistant export restricted to plugin-specific uploads subdirectory
- [x] sync-docs file write to plugin directories removed
- [x] Full audit of all file write operations — all write to uploads/temp/stdout
- [x] Proactive audit of ABSPATH, text domain, CDN, obfuscation, redirects, nonces, SQL, sanitization — all pass
- [x] `composer install --no-dev --classmap-authoritative` run for production classmap
