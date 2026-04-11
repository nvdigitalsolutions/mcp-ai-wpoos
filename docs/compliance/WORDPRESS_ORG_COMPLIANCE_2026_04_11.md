# WordPress.org Plugin Directory Compliance — April 11, 2026

**Audit Type:** Pre-submission compliance check against all 13 WordPress.org Plugin Guidelines
**Date:** 2026-04-11
**Plugin Version:** 1.2.0 (base plugin)
**Scope:** Base plugin only (`includes/`, root PHP files, `assets/`)

---

## Summary

This document records a full compliance audit of the base plugin against all 13 WordPress.org Plugin Developer Guidelines, plus the automated review checks from the April 9 review cycle. Three categories of issues were identified and remediated.

---

## Guideline-by-Guideline Audit

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

**Status:** ✅ Fixed (was non-compliant)

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

## Automated Review Check Remediation

In addition to the 13 guidelines, the following issues from the April 9 automated review and proactive audit were verified or remediated:

### Issue A: do_shortcode() Output Without wp_kses_post()

**Guideline:** All shortcode output must be properly escaped when echoed.

**Finding:** Seven instances in the base plugin where `do_shortcode()` output was echoed without `wp_kses_post()` wrapping. Some had `phpcs:ignore` comments instead of proper escaping.

**Fix Applied:**

| File | Line | Change |
|------|------|--------|
| `includes/elementor/class-wp-mcp-ai-elementor-widget.php` | 1202 | `echo do_shortcode(...)` → `echo wp_kses_post( do_shortcode(...) )` |
| `includes/elementor/class-wp-mcp-ai-elementor-professional-selector-widget.php` | 411 | Same |
| `includes/elementor/class-wp-mcp-ai-elementor-chat-bubble-widget.php` | 743 | Same |
| `includes/elementor/class-wp-mcp-ai-elementor-telegram-login-widget.php` | 267 | Same |
| `includes/admin/class-wp-mcp-ai-admin-test-model.php` | 161 | Same |
| `includes/admin/class-wp-mcp-ai-admin-profession-research-page.php` | 226 | Same |
| `includes/admin/class-wp-mcp-ai-admin-team-research-page.php` | 231 | Same |

All three block render files (`blocks/chat/render.php`, `blocks/chat-bubble/render.php`, `blocks/professional-selector/render.php`) already used `wp_kses_post()` correctly.

---

### Issue B: Tool Capability Flag Mismatches

**Guideline:** Tools making external HTTP calls must declare `'external-api'` capability flag, not `'local-only'`.

**Finding:** Three tools with `get_capability_flags()` methods that make external API calls but did not include `'external-api'`:

| Tool File | External Service | Change |
|-----------|-----------------|--------|
| `class-wp-mcp-ai-tool-edit-gemini-image.php` | Gemini API + external image URLs | Added `'external-api'` |
| `class-wp-mcp-ai-tool-vision-object-localization.php` | Google Cloud Vision API | Added `'external-api'` |
| `class-wp-mcp-ai-tool-vision-product-search.php` | Google Cloud Vision API | Added `'external-api'` |

All three external services were already documented in readme.txt External Services section.

---

## Verification of Previous Fixes (April 9)

All fixes from the April 9 compliance round were verified as still in place:

| Check | Status |
|-------|--------|
| readme.txt URLs (Trade.gov, Mailjet) | ✅ Updated URLs still in place |
| GDACS tool capability flag (`'external-api'`) | ✅ Correct |
| 12 additional tool capability flags | ✅ All correct |
| CLI assistant export path restriction | ✅ Writes only to `uploads/mcp-ai/exports/` |
| sync-docs file write removal | ✅ Removed branch still absent |

---

## Full Proactive Audit Results

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
| `set_time_limit(0)` | ✅ Not used; all bounded (300s or variable) |
| File writes to plugin directory | ✅ None; all to uploads/temp/stdout |
| `wp_deregister_script()` overrides | ✅ None |
| User tracking consent | ✅ Opt-in only, disabled by default |
| "Powered by" branding | ✅ Fixed — removed from A2A agent cards |

---

## Summary Checklist

- [x] Guideline 1 (GPL): GPLv3 license ✅
- [x] Guideline 2 (Responsibility): N/A ✅
- [x] Guideline 3 (Stable version): readme.txt valid ✅
- [x] Guideline 4 (Human-readable): No obfuscation ✅
- [x] Guideline 5 (No trialware): No feature gates ✅
- [x] Guideline 6 (SaaS): Services documented ✅
- [x] Guideline 7 (No tracking): Opt-in activation tracker ✅
- [x] Guideline 8 (No external code): No CDN scripts ✅
- [x] Guideline 9 (Legal): N/A ✅
- [x] Guideline 10 (No credits): "Powered by" removed ✅ Fixed
- [x] Guideline 11 (No hijacking): Standard menu usage ✅
- [x] Guideline 12 (WP libraries): No overrides ✅
- [x] Guideline 13 (Resources): N/A ✅
- [x] do_shortcode() escaping: 7 instances fixed with wp_kses_post() ✅ Fixed
- [x] Tool capability flags: 3 tools corrected to 'external-api' ✅ Fixed
- [x] Previous April 9 fixes verified intact ✅
