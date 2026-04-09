# WP.org Plugin Directory Compliance Verification

**Document version:** 2.0 — 2026-04-09  
**Plugin version:** 1.1.7

This document records every issue raised by the WordPress.org plugin directory automated
reviews and the exact code changes made to resolve each one.

---

# Review 2 — April 2026

**Review ID:** AUTO nvdigital-open-operator-system-oos/vsamtani/25Dec25/T17 9Apr26/3.9.1RC1 (P0TDX269399HGN)  
**Date received:** 2026-04-09  

---

## R2-1. Invalid / 404 URLs in readme.txt

**Issue:** Two URLs in the External Services section returned HTTP 404:
- `https://www.trade.gov/privacy` (ITA Tariff Rates API — Privacy Policy)
- `https://www.mailjet.com/legal/terms-of-use/` (Mailjet API — Terms of Service)

### Changes made

| File | Old (404) | New (working) |
|------|-----------|---------------|
| `readme.txt` (line 810) | `https://www.trade.gov/privacy` | `https://www.trade.gov/privacy-program` |
| `readme.txt` (line 880) | `https://www.mailjet.com/legal/terms-of-use/` | `https://www.mailjet.com/legal/terms/` |

### Verification

- Both replacement URLs were confirmed to return HTTP 200 at the time of this change.
- A full audit of all Terms/Privacy URLs in readme.txt was performed — no other 404s found.

---

## R2-2. Undocumented Use of a Third-Party / External Service

**Issue:** The reviewer's automated tools flagged two instances:

1. **Auth0 API** — `includes/integrations/class-wp-mcp-ai-integration-auth0-github.php:321`
   makes requests to `https://{domain}/api/v2/users/{subject}`.
2. **GDACS API** — `includes/tools/class-wp-mcp-ai-tool-get-gdacs-events.php:114-120`
   makes requests to `https://www.gdacs.org/gdacsapi/api/events/geteventlist/MAP`.

### Resolution

Both services were **already documented** in the `== External Services ==` section of
`readme.txt`:

- **Auth0** → Service #21 (lines 780–786): includes service URL, data sent, when, terms
  of service, and privacy policy links.
- **GDACS** → Service #23 (lines 796–802): includes service URL, data sent, when, terms
  of service, and privacy policy links.

No readme changes were required for this item. The automated tools did not cross-reference
the existing documentation.

### Additional fix — GDACS capability flag

The GDACS tool's `get_capability_flags()` method incorrectly declared `'local-only'` despite
making external HTTP calls. This was corrected:

| File | Change |
|------|--------|
| `includes/tools/class-wp-mcp-ai-tool-get-gdacs-events.php` | `'local-only'` → `'external-api'` in `get_capability_flags()` |

### Additional fix — Comprehensive capability flag audit

A full audit revealed **12 additional base tools** that declared `'local-only'` but actually
make external HTTP requests via `wp_remote_get()` / `wp_remote_post()`. All were corrected:

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
| `class-wp-mcp-ai-tool-create-woo-product.php` | External brand lookup URLs | `'local-only'` → `'external-api'` |
| `class-wp-mcp-ai-tool-image-base.php` | External image URLs | `'local-only'` → `'external-api'` |

Two tools that use `wp_remote_*` for **loopback / internal** HTTP were verified correct and
kept as `'local-only'` with clarified comments:
- `class-wp-mcp-ai-tool-invoke-jetengine-route.php` — calls local REST API via wp_remote_request
- `class-wp-mcp-ai-tool-trigger-all-import.php` — calls home_url() trigger endpoint

All 12 corrected tools were already documented in the readme.txt `== External Services ==`
section; no new service disclosures were needed.

---

## R2-3. Saving Data in the Plugin Folder

**Issue:** The reviewer flagged:
`includes/cli/class-wp-mcp-ai-cli-assistant-command.php:443 file_put_contents($file, $json);`

The concern was that the CLI export command accepted a user-supplied file path that could
potentially write anywhere within the uploads directory.

### Changes made

| File | Change |
|------|--------|
| `includes/cli/class-wp-mcp-ai-cli-assistant-command.php` | **`--file` parameter** changed from accepting full paths to accepting only a bare filename. All exports are written to a plugin-specific uploads subdirectory: `wp-content/uploads/mcp-ai/exports/` |
| `includes/cli/class-wp-mcp-ai-cli-assistant-command.php` | Path separators are stripped via `sanitize_file_name( basename( $file ) )` |
| `includes/cli/class-wp-mcp-ai-cli-assistant-command.php` | The export directory is created via `wp_mkdir_p()` if it doesn't exist |
| `includes/cli/class-wp-mcp-ai-cli-assistant-command.php` | Updated PHPDoc: `[--file=<path>]` → `[--file=<filename>]` with docs explaining the restriction |

### Additional fix — sync-docs auto-fix

`includes/slash-commands/commands/class-wp-mcp-ai-slash-command-sync-docs.php` contained an
`elseif` branch that wrote auto-fixed content back to README files in plugin/theme directories
using `file_put_contents()`. Plugin directories are deleted on upgrade; writing to them violates
WordPress.org directory guidelines.

| File | Change |
|------|--------|
| `class-wp-mcp-ai-slash-command-sync-docs.php` | Removed the `elseif ('file' === $doc['type'])` branch. Auto-fix only applies to post-type docs (saved via `wp_update_post()`). Added explanatory comment. |

### Full audit of file write operations

All `file_put_contents()`, `fopen()`, and `fwrite()` calls in the base plugin (`includes/`)
were audited. Results:

| Location | Writes to | Status |
|----------|-----------|--------|
| Tools (image generation, charts, etc.) | `wp_upload_dir()['path']` | ✅ OK |
| Report generator | `wp_upload_dir()['basedir']/mcp-ai-reports/` | ✅ OK |
| Workflow commands | `wp_upload_dir()['basedir']/mcp-ai/workflows/` | ✅ OK |
| Skill registry | `wp_upload_dir()['basedir']/wp-mcp-ai-skills/` | ✅ OK |
| Custom tool loader | `wp_upload_dir()['basedir']/wp-mcp-ai-custom-tools/` | ✅ OK |
| Profession playbook seeder | `wp_upload_dir()['basedir']/wp-mcp-ai/profession-playbooks/` | ✅ OK |
| Temp file operations | `wp_tempnam()` / `sys_get_temp_dir()` | ✅ OK |
| CLI transport | `STDOUT` / `STDERR` | ✅ OK |
| Logger (error log truncation) | `ini_get('error_log')` (PHP error log path) | ✅ OK |
| CLI assistant export | `wp_upload_dir()['basedir']/mcp-ai/exports/` | ✅ Fixed |
| Sync-docs auto-fix | Plugin/theme directories | ✅ Removed |

**No file writes to the plugin directory were found.** All writes target the WordPress uploads
directory, temp directories, or standard output streams.

---

## R2 — Summary checklist

- [x] Two broken URLs in readme.txt corrected (Trade.gov, Mailjet)
- [x] All external services verified as documented in readme.txt (Auth0, GDACS)
- [x] GDACS tool capability flag corrected (`'local-only'` → `'external-api'`)
- [x] 12 additional tools had `'local-only'` capability flags corrected to `'external-api'`
- [x] CLI assistant export restricted to plugin-specific uploads subdirectory
- [x] sync-docs file write to plugin directories removed
- [x] Full audit of all file write operations — all write to uploads/temp/stdout

---
---

# Review 1 — March 2026

**Review ID:** AUTO nvdigital-open-operator-system-oos/vsamtani/25Dec25/T14 24Mar26/3.9A7 (P0TDX269399HGN)  
**Date received:** 2026-03-24  

---

## R1-1. Phoning Home / Collecting User Data Without Opt-In Consent

**Issue:** Activation/deactivation telemetry was sent to `nvdigitalsolutions.com` by default
(opt-out model). WordPress.org guideline 7 & 9 require tracking to be disabled by default
with explicit opt-in.

### Changes made

| File | Change |
|------|--------|
| `includes/class-wp-mcp-ai-activation-tracker.php` | `apply_filters('wp_mcp_ai_enable_usage_tracking', true)` → `apply_filters('wp_mcp_ai_enable_usage_tracking', $opted_in)` where `$opted_in` is `false` by default |
| `includes/class-wp-mcp-ai-activation-tracker.php` | Removed `disable_activation_tracking` check; replaced with `enable_activation_tracking` opt-in check in both `track_activation()` and `track_deactivation()` |
| `includes/admin/sections/class-wp-mcp-ai-section-general.php` | Renamed setting `disable_activation_tracking` → `enable_activation_tracking`; updated label to "Enable Activation Tracking"; set `default => false` |
| `readme.txt` | Updated service #26 description: "opt-out available" → "disabled by default — explicit opt-in required" |

### Verification

- `track_activation()` now reads `$settings['enable_activation_tracking']`; if the key is absent
  or falsy (the default for any fresh install), `$opted_in = false` and tracking is skipped.
- The filter `wp_mcp_ai_enable_usage_tracking` now receives `false` as its default, so developers
  who previously relied on the filter returning `true` must explicitly return `true` to enable tracking.
- `WP_MCP_AI_Activation_Tracker::track_activation()` still auto-skips local/dev environments
  (`localhost`, `.local`, `.test`, `.dev`, `WP_LOCAL_DEV`) regardless of the opt-in setting.

---

## R1-2. Invalid / 404 URLs in readme.txt

**Issue:** 15 URLs in readme.txt returned HTTP 404. WordPress.org requires all documented
links to be accessible.

### URL corrections

| Old (404) | New (working) |
|-----------|---------------|
| `https://reliefweb.int/privacy-policy` | `https://reliefweb.int/terms` |
| `https://www.remove.bg/terms-of-service` | `https://www.remove.bg/tos` |
| `https://plaid.com/legal/privacy-policy` | `https://plaid.com/legal/` |
| `https://mubert.com/corporate/terms` | `https://mubert.com/documents/mubert_website_tou.pdf` |
| `https://mubert.com/corporate/privacy` | `https://mubert.com/render/docs/privacy-policy` |
| `https://www.gdacs.org/About/privacy.aspx` | `https://www.gdacs.org/About/overview.aspx` |
| `https://nvdigitalsolutions.com/terms` (×2) | `https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/LICENSE` |
| `https://nvdigitalsolutions.com/api/licenses` | `https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases` |
| `https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/download` | `https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases` |
| `https://github.com/login/oauth/access_token` | `https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps` |
| `https://tavily.com/terms-of-use` (×2) | `https://www.tavily.com/terms` |
| `https://tavily.com/privacy-policy` (×2) | `https://docs.tavily.com/documentation/privacy` |
| `https://exa.ai/terms` (×2) | `https://trust.exa.ai/` |
| `https://exa.ai/privacy` (×4) | `https://exa.ai/privacy-policy` |
| `https://goqr.me/privacy/` (×3) | `https://goqr.me/privacy-safety-security/` |

---

## R1-3. Out-of-Date Libraries

**Issue:** `symfony/cache` and `symfony/validator` pinned to 6.4.34; 6.4.35 was available.

### Changes made

| File | Change |
|------|--------|
| `composer.json` | `"symfony/validator": "^6.4\|^7.0"` → `">=6.4.35 <7.0\|^7.0"` |
| `composer.json` | `"symfony/cache": "^6.4\|^7.0"` → `">=6.4.35 <7.0\|^7.0"` |

### Action required

Run `composer update symfony/cache symfony/validator` with network access to update
`composer.lock` and the installed packages. The version constraints in `composer.json` now
enforce the minimum of 6.4.35.

---

## R1-4. Undocumented Use of Third-Party / External Services

**Issue:** QuickBooks, Cloudways, and the NV Digital Solutions license server were not clearly
documented in readme.txt.

### Verification

These services were already documented in the `== External services ==` section of readme.txt
before this review (services #27–#30). The review has been addressed by:

- Ensuring the NV Digital Solutions license server URL is correct (item #27)
- Confirming QuickBooks (item #30) and Cloudways (item #29) each include service URL, data
  sent, when, and links to terms / privacy policy
- Confirming GitHub OAuth (item #28) references the correct GitHub OAuth documentation URL

No new code changes were required for this item beyond the URL fixes in item 2.

---

## R1-5. Sanitization for `register_setting()`

**Issue:** `sanitize_settings_callback()` returned the input array unchanged, providing no
sanitization at the `register_setting()` level.

### Changes made

| File | Change |
|------|--------|
| `includes/admin/class-wp-mcp-ai-settings-dashboard.php` | Replaced pass-through return with call to new `sanitize_settings_array_recursive()` private method |
| `includes/admin/class-wp-mcp-ai-settings-dashboard.php` | Added `sanitize_settings_array_recursive()`: type-safe recursion over the settings array |

### Design decisions (why these specific functions)

- **Keys are preserved exactly as-is.** `sanitize_key()` is intentionally NOT applied to array
  keys because the settings array contains mixed-case and camelCase keys (`backgroundColor`,
  `High`, `Low`, `Medium`) that `sanitize_key()`'s lowercase conversion would corrupt.

- **`sanitize_textarea_field()` is used for strings, not `sanitize_text_field()`.** The settings
  array contains multiline `textarea`-type fields (e.g. `ip_whitelist` stores newline-separated
  IP addresses; `chat_welcome_message` and prompt fields may contain newlines).
  `sanitize_text_field()` collapses all whitespace — including newlines — to spaces, which
  would corrupt these values. `sanitize_textarea_field()` strips HTML/PHP tags while preserving
  newlines, making it safe for both single-line values (API keys, model names) and multiline
  values.

- **`esc_url_raw()` is used for URL strings.** Values matching `^https?://` are passed through
  `esc_url_raw()` rather than `sanitize_textarea_field()`.

- **Booleans, integers, and floats are cast to their native types** to prevent type coercion
  issues from form submission values arriving as strings.

- **The context-aware merge still happens upstream.** `handle_save_settings()` continues to
  run full section-based sanitization (which is context-aware / subtab-aware) before calling
  `update_option()`. The `register_setting()` callback provides a second, general-purpose
  sanitization pass as a safety net for any code that calls `update_option()` directly.

---

## R1-6. Base Version vs Pro Addon — "Pro Only Adds New Functionality"

**Issue (WP.org):** The plugin marketed already-present capabilities as a Pro upgrade.  
**User requirement:** Make it clear and enforce in code that the Pro addon only adds
genuinely new functionality; the base plugin must not restrict any of its own included tools.

### Root cause

The tool registry's `is_base_version()` method contained a Pro-addon presence check:

```php
// If Pro addon is loaded, disable base version mode to enable all tools.
if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
    return false;
}
```

This caused the 50+ "extended" tools that physically reside in `includes/tools/` (WooCommerce,
JetEngine, Elementor, Yahoo Fantasy Football, etc.) to be silently skipped unless the Pro addon
was installed. These are base-plugin tools — gating them behind the Pro addon misrepresents the
plugin's capabilities.

Similarly, `wp_mcp_ai_should_load_integrations()` returned `false` in base mode unless Pro was
present, preventing the OAuth handler classes (GitHub, QuickBooks, Cloudways, etc.) from loading.

### PHP version as the real differentiator

- **Base plugin (PHP 7.4+):** All `includes/tools/` tools are available. Tools for optional
  third-party plugins (WooCommerce, JetEngine, Elementor, etc.) are registered and activate
  automatically when those plugins are detected via their `is_available()` guards.
- **Pro addon (PHP 8.1+ required):** Adds genuinely NEW tools using modern PHP 8.1+ language
  features (enums, readonly properties, named arguments, fibers). It does not unlock, gate, or
  restrict anything that is already in the base plugin.

### Changes made

| File | Change |
|------|--------|
| `includes/class-wp-mcp-ai-tool-registry.php` | Removed Pro-addon check from `is_base_version()`; the method now only respects the `WP_MCP_AI_BASE_VERSION` constant and filter |
| `includes/class-wp-mcp-ai-tool-registry.php` | `load_default_tools()`: changed `$is_base_version ? $base_tools : array_merge(...)` to unconditional `array_merge($base_tools, $extended_tools, $pro_tools)` — all tools in the plugin always attempt registration |
| `includes/bootstrap/helpers.php` | `wp_mcp_ai_should_load_integrations()` now always returns `true`; integration files are always loaded (they guard themselves against missing dependencies internally) |
| `includes/bootstrap/constants.php` | `WP_MCP_AI_BASE_VERSION` default changed from `true` to `false`; updated docblock to document PHP 7.4 vs PHP 8.1+ as the version differentiator |
| `readme.txt` | Rewrote "= Versions =" section to clearly explain PHP 7.4 base vs PHP 8.1+ Pro distinction |

### Safety verification

All extended tools that depend on third-party WordPress plugins have proper `is_available()`
guards (e.g. `return class_exists('WooCommerce')` for WooCommerce tools). If the dependency is
not installed, `is_available()` returns `false`, the tool is not registered, and an informational
message is stored. Tools that use only external APIs (Flowhub, PayHere, Vision API, etc.) have no
local plugin dependency and register safely on all installations.

All integration files (`class-wp-mcp-ai-jetengine-cct.php`, OAuth handlers, etc.) guard their
JetEngine / third-party plugin calls with `function_exists('jet_engine')` or equivalent checks
inside their methods — they are safe to load even when those plugins are absent.

The `mcp-ai-wpoos-base.php` entry point (used for WordPress.org distribution) still forces
`WP_MCP_AI_BASE_VERSION = true` before loading the main plugin, which is respected by the
`if (!defined(...))` guard in `constants.php`. This entry point is only used in the separate
WordPress.org build artefact.

---

## R1 — Summary checklist

- [x] Telemetry changed from opt-out to **opt-in** (disabled by default)
- [x] 15 broken URLs in readme.txt corrected
- [x] `composer.json` minimum versions enforced for symfony/cache and symfony/validator (≥6.4.35)
- [x] External services fully documented (was already done; URLs corrected)
- [x] `sanitize_settings_callback()` now performs real recursive sanitization using `sanitize_textarea_field()` + `esc_url_raw()` + type casts; keys preserved to avoid corruption
- [x] Base plugin tools are no longer gated behind the Pro addon; `is_base_version()` Pro-addon check removed; `wp_mcp_ai_should_load_integrations()` always returns `true`
- [x] PHP 7.4 (base) vs PHP 8.1+ (Pro) documented as the definitive version differentiator in `constants.php`, `readme.txt`, and this document
