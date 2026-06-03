# WordPress.org Plugin Directory Compliance — April 9, 2026

**Review ID:** AUTO nvdigital-open-operator-system-oos/vsamtani/25Dec25/T17 9Apr26/3.9.1RC1 (P0TDX269399HGN)
**Date:** 2026-04-09
**Plugin Version:** 1.1.7
**Last Updated:** 2026-04-11 (pre-submission compliance re-check against all 13 guidelines + additional security hardening)

---

## Summary

This document tracks the remediation of all issues identified by the WordPress.org automated review system on April 9, 2026, plus a comprehensive pre-submission compliance re-check performed on April 11, 2026 covering all 13 WordPress.org Plugin Developer Guidelines. Each issue is listed below with the specific fix applied and the files modified.

---

## Guideline-by-Guideline Audit (April 11 re-check)

### Guideline 1: Plugins must be compatible with the GNU General Public License

**Status:** ✅ Compliant

LICENSE file contains GPLv3 full text. Plugin header declares `License: GPL-3.0-or-later`. All bundled dependencies are GPL-compatible.

---

### Guideline 2: Developers are responsible for the contents and actions of their plugins

**Status:** ✅ Compliant

Behavioral guideline — no code-level check required.

---

### Guideline 3: A stable version must be available from the WordPress Plugin Directory page

**Status:** ✅ Compliant

Distribution guideline — readme.txt contains valid `Stable tag:` and `Requires at least:` headers.

---

### Guideline 4: Code must be (mostly) human readable

**Status:** ✅ Compliant

- No `eval()` execution found (only detection/scanning in code optimizer security checks)
- All `base64_encode`/`base64_decode` usage is legitimate API data encoding (image payloads, HTTP Basic Auth, encryption) with PHPCS justification comments
- No obfuscated variable names, no encoded payloads, no minified PHP

---

### Guideline 5: Trialware is not permitted

**Status:** ✅ Compliant

The base plugin has no feature gates, time-limited trials, or upsell prompts. Pro features are in a separate addon (`addons/pro/`) and are not included in the base submission.

---

### Guideline 6: Software as a Service is permitted

**Status:** ✅ Compliant

The plugin connects to external AI services (OpenAI, Gemini, etc.) which is permitted under this guideline. All services are documented in readme.txt.

---

### Guideline 7: Plugins may not track users without their consent

**Status:** ✅ Compliant

The activation tracker (`class-wp-mcp-ai-activation-tracker.php`) is:
- **Disabled by default** (explicit opt-in required via Settings → NV oOS → "Enable activation tracking")
- Sends only non-PII data (hashed site URL, PHP/WP versions, locale)
- Documented in readme.txt External Services section (#26)
- Skips local/development environments
- Filterable via `wp_mcp_ai_enable_usage_tracking`

---

### Guideline 8: Plugins may not send executable code via third-party systems

**Status:** ✅ Compliant

- No external CDN scripts in any `wp_enqueue_script()` or `wp_register_script()` call in the base plugin
- No `file_get_contents()` on remote URLs (all use `wp_remote_get()`)
- No dynamic code loading from external sources

---

### Guideline 9: Developers and their plugins must not do anything illegal, dishonest, or morally offensive

**Status:** ✅ Compliant

Behavioral guideline — no code-level check required.

---

### Guideline 10: Plugins may not embed external links or credits on the public site without explicitly asking

**Status:** ✅ Fixed (April 11)

**Finding:** Two instances of "powered by NV oOS" branding in A2A agent card default descriptions:
- `includes/a2a/class-wp-mcp-ai-a2a-agent-card.php:100` — `'An AI assistant powered by NV oOS.'`
- `includes/a2a/class-wp-mcp-ai-a2a-agent-card.php:137` — `'AI agent for %s, powered by NV oOS.'`

While A2A agent cards are JSON API responses (not rendered HTML), they are publicly accessible and contain branding without user opt-in.

**Fix Applied:**
| File | Old | New |
|------|-----|-----|
| `class-wp-mcp-ai-a2a-agent-card.php:100` | `'An AI assistant powered by NV oOS.'` | `'An AI assistant.'` |
| `class-wp-mcp-ai-a2a-agent-card.php:137` | `'AI agent for %s, powered by NV oOS.'` | `'AI agent for %s.'` |

---

### Guideline 11: Plugins should not hijack the admin dashboard

**Status:** ✅ Compliant

The plugin registers its own top-level admin menu and submenu pages under it. Dashboard widgets are added via standard `wp_add_dashboard_widget()`. No admin notices outside the plugin's own pages. No full-page takeovers or aggressive upsells.

---

### Guideline 12: Plugins must use WordPress' default libraries

**Status:** ✅ Compliant

- No `wp_deregister_script()` or `wp_deregister_style()` calls
- No bundled versions of jQuery, Backbone, Underscore, or other WordPress-shipped libraries
- Plugin uses WordPress-bundled jQuery via dependency declarations

---

### Guideline 13: Developers must not abuse or monopolize resources shared by the WordPress.org community

**Status:** ✅ Compliant

Community guideline — no code-level check required. Plugin uses standard WordPress APIs and does not abuse update checks or API calls.

---

## Automated Reviewer Issues (April 9)

### Issue 1: Invalid / 404 URLs in readme.txt

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

### Issue 2: Undocumented Use of a Third-Party / External Service

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

**April 11 Follow-up:** Three additional tools found with `get_capability_flags()` methods that make external API calls but were missing `'external-api'`:

| Tool File | External Service | Change |
|-----------|-----------------|--------|
| `class-wp-mcp-ai-tool-edit-gemini-image.php` | Gemini API + external image URLs | Added `'external-api'` |
| `class-wp-mcp-ai-tool-vision-object-localization.php` | Google Cloud Vision API | Added `'external-api'` |
| `class-wp-mcp-ai-tool-vision-product-search.php` | Google Cloud Vision API | Added `'external-api'` |

All three external services were already documented in readme.txt External Services section.

**Status:** ✅ Fixed

---

### Issue 3: Saving Data in the Plugin Folder

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

## Issue 4: do_shortcode() Output Without wp_kses_post() (April 11, revised April 16)

**Guideline:** All shortcode output must be properly escaped when echoed.

**Original Finding (April 11):** Seven instances in the base plugin where `do_shortcode()` output was echoed without `wp_kses_post()` wrapping. These were initially wrapped in `wp_kses_post()`.

**Revised (April 16):** The `wp_kses_post()` wrapping broke the chat UI because it strips `data-*` attributes, SVGs, `<form>`/`<input>`/`<button>` element attributes, and `<script type="application/json">` config blocks. A custom `kses_chat_output()` method with an extended allowlist was attempted (PR #4698), but it only allowlisted 4 specific `data-*` attributes while the chat UI uses 15+.

**Current approach:** The shortcode's `render_shortcode()` method individually escapes every dynamic value at the point of insertion using `esc_attr()`, `esc_html()`, `esc_url()`, and `wp_json_encode()`. The shortcode output is echoed directly with `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` and a detailed inline justification. This is the same pattern used by WordPress core's own block rendering (`render_block()` → `echo` without `wp_kses_post()`).

| File | Line | Handling |
|------|------|----------|
| `includes/elementor/class-wp-mcp-ai-elementor-widget.php` | 1202 | `echo do_shortcode(...)` with phpcs:ignore justification |
| `includes/elementor/class-wp-mcp-ai-elementor-professional-selector-widget.php` | 411 | Same |
| `includes/elementor/class-wp-mcp-ai-elementor-chat-bubble-widget.php` | 866 | `echo $safe_html` (deferred attr rename) with phpcs:ignore justification |
| `includes/elementor/class-wp-mcp-ai-elementor-telegram-login-widget.php` | 267 | `echo do_shortcode(...)` — Telegram SDK `<script>` embed |
| `includes/admin/class-wp-mcp-ai-admin-test-model.php` | 161 | `echo do_shortcode(...)` with phpcs:ignore justification |
| `includes/admin/class-wp-mcp-ai-admin-profession-research-page.php` | 226 | Same |
| `includes/admin/class-wp-mcp-ai-admin-team-research-page.php` | 231 | Same |
| `includes/blocks/chat/render.php` | 71 | `echo do_shortcode(...)` with phpcs:ignore justification |
| `includes/blocks/chat-bubble/render.php` | 218 | `echo $safe_html` (deferred attr rename) with phpcs:ignore justification |
| `includes/blocks/professional-selector/render.php` | 81 | `echo do_shortcode(...)` with phpcs:ignore justification |
| `includes/class-wp-mcp-ai-chat-bubble-frontend.php` | 372 | `echo $safe_html` (deferred attr rename) with phpcs:ignore justification |

The AJAX response in `class-wp-mcp-ai-professional-selector-shortcode.php:582` retains `wp_kses_post()` because the JSON response does not require complex HTML structures.

**Status:** ✅ Fixed (revised approach)

---

## Issue 5: AJAX Dismiss Handlers Missing Capability Checks (April 11)

**Guideline:** All state-changing AJAX handlers must verify both nonce and user capability before performing operations.

**Finding:** Two AJAX handlers that dismiss admin notices verified nonces but did not check `current_user_can()`. While these handlers only modify the calling user's own meta (harmless in practice), WordPress.org reviewers require explicit capability checks on all state-changing AJAX handlers.

**Fix Applied:**

| File | Handler | Change |
|------|---------|--------|
| `includes/bootstrap/hooks.php` | `wp_mcp_ai_dismiss_directory_notice_ajax()` | Added `current_user_can( 'manage_options' )` check before `update_user_meta()` |
| `includes/class-wp-mcp-ai-model-pricing-checker.php` | `dismiss_price_notice()` | Replaced `is_user_logged_in()` with `current_user_can( 'manage_options' )` — only administrators see pricing notices |

**Status:** ✅ Fixed

---

## Issue 6: Unsanitised `$_POST` Iteration in Settings Diagnostic Logging (April 11)

**Guideline:** All `$_POST`, `$_GET`, `$_REQUEST`, and `$_SERVER` superglobal accesses must be properly sanitised — even when used only in diagnostic logging.

**Finding:** `includes/admin/sections/abstract-wp-mcp-ai-settings-section.php` iterated raw `$_POST` keys and values when logging subtab diagnostics. While the output went only to `error_log()` via `wp_json_encode()`, raw superglobal access is flagged by automated reviewers.

**Fix Applied:**

| File | Line | Change |
|------|------|--------|
| `includes/admin/sections/abstract-wp-mcp-ai-settings-section.php` | 201–204 | Added `sanitize_key()` on `$key` and `sanitize_text_field( wp_unslash() )` on `$value` in `$_POST` iteration loop |

**Status:** ✅ Fixed

Beyond the reviewer-flagged issues, the following compliance areas were proactively audited across the full base plugin:

| Check | Result |
|-------|--------|
| ABSPATH guards on all 762 PHP files in `includes/` | ✅ All present |
| Text domain consistency (`'mcp-ai-wpoos'`) | ✅ All translation functions use correct domain |
| External CDN scripts in base plugin enqueues | ✅ None found |
| `file_get_contents()` on remote URLs | ✅ Not used; all HTTP via `wp_remote_*()` |
| Obfuscated code (`eval`, `base64_decode`) | ✅ No obfuscation; base64 for legitimate API data |
| `wp_redirect()` followed by `exit` | ✅ All redirects properly terminated |
| Nonce verification on AJAX handlers | ✅ All handlers verify nonces |
| `$wpdb->prepare()` for all dynamic queries | ✅ All prepared |
| Sanitization of `$_GET`, `$_POST`, `$_REQUEST`, `$_SERVER` | ✅ All sanitized |
| `$_POST` / `$_GET` / `$_REQUEST` sanitisation | ✅ All superglobal accesses use `sanitize_text_field()`, `sanitize_key()`, `absint()`, or `wp_unslash()` |
| `$_COOKIE` iteration | ✅ Cookie forwarding in JetFormBuilder handlers uses `sanitize_key()` + `sanitize_text_field( wp_unslash() )` |
| `$_SERVER` headers | ✅ `HTTP_CLIENT_IP` already sanitised with `sanitize_text_field( wp_unslash() )` |
| AJAX handler capability checks | ✅ All handlers verify `current_user_can()` (fixed in Issue 5) |
| `set_time_limit(0)` | ✅ Not used; all bounded (300s or variable) |
| File writes to plugin directory | ✅ None; all to uploads/temp/stdout |
| `wp_deregister_script()` overrides | ✅ None |
| User tracking consent | ✅ Opt-in only, disabled by default |
| "Powered by" branding | ✅ Fixed — removed from A2A agent cards |

---

## Summary Checklist

### April 9 — Automated Review Issues
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

### April 11 — Pre-Submission Compliance Re-Check (All 13 Guidelines)
- [x] Guideline 1 (GPL): GPLv3 license ✅
- [x] Guideline 2 (Responsibility): N/A ✅
- [x] Guideline 3 (Stable version): readme.txt valid ✅
- [x] Guideline 4 (Human-readable): No obfuscation ✅
- [x] Guideline 5 (No trialware): No feature gates ✅
- [x] Guideline 6 (SaaS): Services documented ✅
- [x] Guideline 7 (No tracking): Opt-in activation tracker ✅
- [x] Guideline 8 (No external code): No CDN scripts ✅
- [x] Guideline 9 (Legal): N/A ✅
- [x] Guideline 10 (No credits): "Powered by" removed from A2A agent cards ✅ Fixed
- [x] Guideline 11 (No hijacking): Standard menu usage ✅
- [x] Guideline 12 (WP libraries): No overrides ✅
- [x] Guideline 13 (Resources): N/A ✅
- [x] do_shortcode() escaping: shortcode output uses direct echo with `phpcs:ignore` justification (render_shortcode() self-sanitizes; wp_kses_post() strips required data-* attrs/SVGs/forms/JSON config). AJAX response retains wp_kses_post(). ✅ Revised April 16
- [x] Tool capability flags: 3 additional tools corrected to 'external-api' ✅ Fixed
- [x] AJAX dismiss handlers hardened with `current_user_can( 'manage_options' )` ✅ Fixed
- [x] Unsanitised `$_POST` iteration in settings diagnostic logging sanitised ✅ Fixed
- [x] Expanded superglobal audit: `$_COOKIE` iteration, `$_SERVER` headers all properly sanitised ✅ Verified
- [x] All previous April 9 fixes verified intact ✅
