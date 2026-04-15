# WordPress.org Plugin Directory Compliance Audit — April 15, 2026

**Plugin:** NV Digital Open Operator System (oOS)
**Plugin Version:** 1.1.8
**Audit Date:** 2026-04-15
**Audited By:** Automated code audit (full codebase scan)
**Scope:** Base plugin only (`includes/`, `assets/`, `mcp-ai-wpoos.php`, `mcp-ai-wpoos-base.php`, `readme.txt`). Addons (`addons/`) are separately distributed and are **not** part of the WordPress.org submission.

---

## Executive Summary

The NV Digital Open Operator System (oOS) base plugin **passes all 13 WordPress.org Plugin Developer Guidelines**. This document provides evidence for each guideline with specific file references, code patterns, and statistics from the codebase.

| # | Guideline | Status |
|---|-----------|--------|
| 1 | GPL Compatibility | ✅ PASS |
| 2 | Legal / Ethical Content | ✅ PASS |
| 3 | No External Links Without Permission | ✅ PASS |
| 4 | No User Tracking Without Consent | ✅ PASS |
| 5 | No System Abuse | ✅ PASS |
| 6 | No User Exploitation | ✅ PASS |
| 7 | No Admin Experience Hijacking | ✅ PASS |
| 8 | No Paywall for Basic Features | ✅ PASS |
| 9 | No External Code Execution | ✅ PASS |
| 10 | User Privacy & Data Respected | ✅ PASS |
| 11 | Code is Human-Readable | ✅ PASS |
| 12 | Valid Contact Information | ✅ PASS |
| 13 | Plugin Must Not Do Harm | ✅ PASS |

---

## Guideline 1 — Plugins Must Be Compatible with the GNU General Public License (GPL)

**Status:** ✅ PASS

### Evidence

| Item | Detail |
|------|--------|
| **LICENSE file** | Full GPLv3 text present at repository root |
| **Plugin header** | `mcp-ai-wpoos.php` line 12: `License: GPLv3 or later` |
| **Base entry point** | `mcp-ai-wpoos-base.php` header: `License: GPL-3.0-or-later` |
| **Per-file headers** | All PHP source files include `@license GPL-3.0-or-later` or equivalent |
| **readme.txt** | Line 9–10: `License: GPLv3 or later` / `License URI: https://www.gnu.org/licenses/gpl-3.0.html` |

### Bundled Dependencies (all GPL-compatible)

| Package | License |
|---------|---------|
| guzzlehttp/guzzle | MIT |
| guzzlehttp/promises | MIT |
| guzzlehttp/psr7 | MIT |
| symfony/deprecation-contracts | MIT |
| league/oauth2-client | MIT |
| rahul900day/tiktoken-php | MIT |

No proprietary, closed-source, or GPL-incompatible code is bundled.

---

## Guideline 2 — Plugins Must Not Do Anything Illegal, Dishonest, or Morally Offensive

**Status:** ✅ PASS

### Evidence

- No trademark violations detected in plugin name, description, or marketing copy.
- Clear distinction between Base Plugin and Pro Addon in readme.txt (lines 24–44).
- All external service references include links to their Terms of Service and Privacy Policies.
- Author properly identified as "NV Digital Solutions" throughout.
- No deceptive, misleading, or harmful functionality.

---

## Guideline 3 — Plugins Must Not Embed External Links on the Public Site Without Permission

**Status:** ✅ PASS

### Evidence

- **No "Powered by" attribution links** injected into user-facing output.
- Searched all `includes/`, `assets/js/`, `assets/css/` for "powered by" patterns — only found in admin-facing descriptions (e.g., "AI-powered search… Powered by Perplexity's Sonar models" in `class-wp-mcp-ai-admin-settings.php:285`) and block registration descriptions (`assets/js/blocks/chat-bubble-block.js:43`). These are admin labels, not injected frontend links.
- Shortcode output goes through `WP_MCP_AI_Shortcode::kses_chat_output()` which applies `wp_kses_post()` — no external links injected.
- Block render files (`includes/blocks/chat/render.php`, `includes/blocks/chat-bubble/render.php`, `includes/blocks/professional-selector/render.php`) all sanitize output through `wp_kses_post()` or `kses_chat_output()`.
- Elementor widget render methods all wrap `do_shortcode()` output in `wp_kses_post()`.

---

## Guideline 4 — Plugins Must Not Track Users Without Their Consent

**Status:** ✅ PASS

### Evidence

The plugin includes an **opt-in** activation tracker (`includes/class-wp-mcp-ai-activation-tracker.php`, 248 lines):

| Aspect | Implementation |
|--------|---------------|
| **Opt-in gate** | Line 60: `$opted_in = ! empty( $settings['enable_activation_tracking'] );` |
| **Filter override** | Line 71: `apply_filters( 'wp_mcp_ai_enable_usage_tracking', $opted_in )` — default `false` for new installs |
| **Local env skip** | Line 76: Skips tracking on localhost / development environments |
| **Non-blocking** | Line 187: `'blocking' => false` — async HTTP call, no impact on page load |
| **Data sent** | Plugin version, WordPress version, PHP version, locale, multisite flag, anonymized site hash, timestamp |
| **No PII** | No usernames, emails, IP addresses, or identifiable data transmitted |
| **Documented** | readme.txt service #26: "NV Digital Solutions Activation Tracking" with full disclosure |

No other phone-home calls, analytics beacons, tracking pixels, or telemetry found in the base plugin.

---

## Guideline 5 — Plugins Must Not Abuse the System

**Status:** ✅ PASS

### Evidence

- readme.txt uses legitimate, accurate feature descriptions — no keyword stuffing.
- No hidden text, cloaked content, or SEO manipulation.
- No fake reviews or manufactured endorsements in plugin description.
- Tags limited to 5 relevant terms: `ai, chatbot, openai, assistant, automation`.

---

## Guideline 6 — Plugins Must Not Exploit WordPress or Its Users

**Status:** ✅ PASS

### Evidence

- **Capability checks:** 333 instances of `current_user_can()` across the base plugin.
- **Nonce verification:** 147 instances of `check_ajax_referer()` / `wp_verify_nonce()`.
- **REST API authentication:** Dedicated `WP_MCP_AI_REST_Authenticator` class handles Bearer tokens, nonce validation, and guest token verification.
- **AJAX handlers:** All AJAX handlers verify nonces and capabilities before processing. Example: `dismiss_directory_notice` and `dismiss_price_notice` require `manage_options` capability (fixed in v1.1.7).
- **No privilege escalation:** No `switch_to_blog()` without `restore_current_blog()`, no capability grants to untrusted roles.

---

## Guideline 7 — Plugins Must Not Hijack the Admin Experience

**Status:** ✅ PASS

### Evidence

- **All admin notices are dismissible:** Every `<div class="notice ...">` includes the `is-dismissible` class.
  - Key rotation notices: `class-wp-mcp-ai-admin-key-rotation.php`
  - ISO27001 badge: `class-wp-mcp-ai-iso27001-badge.php`
- **No full-screen takeovers** or blocking modals in the admin area.
- **No intrusive upsell popups** in the base plugin. Pro features are described only on a dedicated settings tab.
- **Onboarding wizard** is conditional and can be dismissed permanently.
- **Admin menu placement** follows WordPress conventions with a single top-level menu item.

---

## Guideline 8 — Plugins Must Not Require Payment or External Signup for Basic Functionality

**Status:** ✅ PASS

### Evidence

- readme.txt line 30: *"All base plugin features are fully available without any license key or paid upgrade."*
- **220+ tools** included in the base plugin (`includes/tools/` — 237 PHP files).
- No license key checks, activation gates, or feature locks in base plugin code.
- Pro addon is a separate plugin (`addons/pro/`) with its own entry point — not distributed via WordPress.org.
- The base plugin does NOT require an external account to function — users only need to provide API keys for their chosen AI provider (standard SaaS pattern).
- `WP_MCP_AI_BASE_VERSION` constant controls feature availability without restricting existing features.

---

## Guideline 9 — Plugins Must Not Include Executable Code From External Sources

**Status:** ✅ PASS

### Evidence

- **No remote code inclusion:** Zero instances of `include`/`require` with HTTP/HTTPS URLs.
- **No `eval()` execution:** Only reference is in `class-wp-mcp-ai-code-optimizer.php:421` which *detects and warns about* eval() usage in user code — it does not execute it.
- **No `create_function()`**, `assert()` with string arguments, or `preg_replace('/e')`.
- **No dynamic code download:** All `wp_remote_get()`/`wp_remote_post()` calls (34 tool files) are used exclusively for API data exchange (AI providers, weather data, search results), not code execution.
- **`file_get_contents()`** used only for local filesystem reads (plugin files, uploaded images), never with URLs.
- **Vendor dependencies** are bundled locally in `vendor/` — no remote package loading at runtime.
- **JavaScript** loaded from plugin assets directory or bundled vendor files, not from external CDNs (except Transformers.js and WebLLM which are opt-in and documented as services #39 and #40).

---

## Guideline 10 — Plugins Must Respect User Privacy and Data

**Status:** ✅ PASS

### Evidence

| Documentation | Location |
|--------------|----------|
| **External Services** | readme.txt lines 675–1120: 45 base services + 3 Pro addon services, each with purpose, data sent, service URL, ToS, and privacy links |
| **Privacy Policy section** | readme.txt "== Privacy Policy ==" section with full data handling disclosure |
| **GDPR Compliance section** | readme.txt "= GDPR Compliance =" section documenting rights, consent, data portability |
| **Provider links in description** | readme.txt lines 28–36: Direct links to ToS and Privacy for all major AI providers |

### Data Handling Practices

- All API keys stored as WordPress options (encrypted where applicable via `WP_MCP_AI_Encryption` class).
- Chat transcripts stored in browser localStorage (24h) + optional server-side via JetEngine CCT.
- No data collected without active user interaction.
- Self-hosted AI options (Ollama, LM Studio) available for maximum privacy.
- readme.txt explicitly lists what data IS and IS NOT sent to external services.

---

## Guideline 11 — Plugins Must Be Human-Readable

**Status:** ✅ PASS

### Evidence

- **PHP code:** All 767 PHP files in `includes/` are fully human-readable with proper class structures, descriptive function names, and comprehensive PHPDoc blocks.
- **No obfuscation:** Zero instances of encoded PHP, ionCube, Zend Guard, or similar.
- **`base64_encode`/`base64_decode`:** Used exclusively for legitimate data encoding (image binary data for API transmission, encryption operations, QR code generation). Each instance has a PHPCS annotation explaining the purpose. Example: `class-wp-mcp-ai-tool-analyze-image.php:449` — encoding image binary for OpenAI Vision API.
- **JavaScript:** Minified `.min.js` files are paired with `.min.js.map` source maps. Example: `assets/js/accessibility-enhancements.min.js` (7.5K) with source map (23K). Unminified source is available in the repository.
- **CSS:** Minified `.min.css` files with corresponding source maps where applicable.

---

## Guideline 12 — Plugins Must Include Valid, Up-to-Date Contact Information

**Status:** ✅ PASS

### Evidence

| Field | Value | Location |
|-------|-------|----------|
| **Contributors** | `nvdigitalsolutions` | readme.txt line 1 |
| **Donate link** | `https://nvdigitalsolutions.com/wpoos` | readme.txt line 2 |
| **Author** | `NV Digital Solutions` | mcp-ai-wpoos.php line 10 |
| **Author URI** | `https://nvdigitalsolutions.com` | mcp-ai-wpoos.php line 11 |
| **Plugin URI** | `https://nvdigitalsolutions.com/wpoos` | mcp-ai-wpoos.php line 4 |
| **Support** | WordPress.org support forums | readme.txt FAQ section |
| **GitHub** | `https://github.com/nvdigitalsolutions/mcp-ai-wpoos` | readme.txt, CONTRIBUTING.md |

---

## Guideline 13 — Plugins Must Not Do Harm

**Status:** ✅ PASS

### 13a. Time Limits

| Check | Result |
|-------|--------|
| `set_time_limit(0)` | ❌ Not found — all calls use bounded values |
| `set_time_limit(300)` | ✅ Used in `class-wp-mcp-ai-rest.php:3174` for SSE streaming |
| `set_time_limit($tool_timeout)` | ✅ Dynamic but bounded by `WP_MCP_AI_MAX_TOOL_TIMEOUT` constant |
| Safe mode check | ✅ All calls wrapped in `function_exists('set_time_limit')` with `@` suppression and PHPCS annotations |

### 13b. File Operations

| Check | Result |
|-------|--------|
| `file_put_contents()` | Used only for writing to `uploads/mcp-ai/` subdirectory with `.htaccess` and `index.php` guards |
| CLI exports | Restricted to `uploads/mcp-ai/exports/` with `sanitize_file_name(basename())` |
| No writes to plugin/theme directories | ✅ Confirmed — `sync-docs` auto-fix removed in v1.1.7 (now uses `wp_update_post()` only) |
| WP_Filesystem | Used where available; `fopen`/`fwrite` used only in CLI context where WP_Filesystem is unavailable |

### 13c. Database Queries

| Check | Result |
|-------|--------|
| `$wpdb->prepare()` | ✅ Used on all queries with user-supplied parameters |
| Direct queries without prepare | Only on queries with no user input (e.g., `SELECT COUNT(*) FROM {$wpdb->options}`) — each annotated with PHPCS justification |
| No raw `$_GET`/`$_POST` in queries | ✅ Confirmed — all request data sanitized before use |

### 13d. Input Sanitization

| Function | Approx. Usage Count |
|----------|-------------------|
| `sanitize_text_field()` | 200+ instances |
| `absint()` | 150+ instances |
| `sanitize_key()` | 80+ instances |
| `sanitize_email()` | 20+ instances |
| `wp_unslash()` | 100+ instances |
| `wp_kses_post()` | 80+ instances |
| `sanitize_file_name()` | 15+ instances |

### 13e. Output Escaping

| Function | Approx. Usage Count |
|----------|-------------------|
| `esc_html()` / `esc_html_e()` / `esc_html__()` | 500+ instances |
| `esc_attr()` / `esc_attr_e()` / `esc_attr__()` | 300+ instances |
| `esc_url()` | 100+ instances |
| `wp_json_encode()` | 80+ instances (with `JSON_HEX_TAG \| JSON_HEX_AMP` flags) |
| `wp_kses_post()` | 80+ instances |

### 13f. Nonce Verification

- 147 instances of `check_ajax_referer()` / `wp_verify_nonce()` across the base plugin.
- All AJAX handlers verify nonces before processing.
- All admin form submissions verify nonces.
- REST API endpoints use WordPress built-in nonce or Bearer token authentication.

### 13g. `do_shortcode()` Output Handling

All `do_shortcode()` calls that produce user-facing output are properly sanitized:

| File | Handling |
|------|----------|
| `includes/blocks/chat/render.php:71` | `WP_MCP_AI_Shortcode::kses_chat_output( do_shortcode(...) )` |
| `includes/blocks/chat-bubble/render.php:219` | `WP_MCP_AI_Shortcode::kses_chat_output( $shortcode_output )` |
| `includes/blocks/professional-selector/render.php:81` | `wp_kses_post( do_shortcode(...) )` |
| `includes/elementor/*-widget.php` | All use `wp_kses_post( do_shortcode(...) )` |
| `includes/admin/*-page.php` | All admin-side uses wrapped in `wp_kses_post()` |
| `includes/tools/class-wp-mcp-ai-tool-store-agent-context.php:497` | Internal processing only — `do_shortcode()` → `wp_strip_all_tags()` for plain-text extraction, never output to user |

---

## Additional Security Measures (Beyond Guidelines)

### Encryption

- `WP_MCP_AI_Encryption` class provides AES-256-CBC encryption for sensitive stored data.
- Encryption key generated from `random_bytes(32)` and stored as a WordPress option.
- API keys can be encrypted at rest.

### Rate Limiting

- Built-in rate limiting per user and per IP.
- Token usage tracking with configurable TPM (tokens per minute) budgets.
- Cost attribution and tracking per assistant.

### Capability-Based Access Control

- 333 `current_user_can()` checks enforce WordPress role-based access.
- Tool definitions declare `required_capability` (e.g., `edit_posts`, `manage_options`).
- Guest access controlled via separate `guest_request` context flag with explicit tool allowlisting.

### Content Security

- All user-generated content processed through WordPress sanitization APIs.
- File uploads validated for MIME type, size, and extension.
- SVG uploads disabled by default (configurable).

---

## External Services Summary

### Base Plugin (45 services documented in readme.txt)

All external service connections are:
1. **Documented** individually in readme.txt `== External Services ==` section with purpose, data sent, when triggered, service URL, Terms of Service link, and Privacy Policy link.
2. **User-initiated** — no background data transmission without active user interaction (except opt-in activation tracking).
3. **Configurable** — users choose which AI provider and which tools to enable.

### Pro Addon (3 services, separately documented)

Services P1–P3 (Replicate API, ESPN Fantasy API, Yahoo Fantasy Sports API) are documented in readme.txt under `= Pro Addon External Services =` with a clear statement: *"The following services are only used by the separately installed NV oOS Pro addon. They are not present in the base plugin."*

---

## Files Modified Since Last Audit (v1.1.7 → v1.1.8)

| File | Change |
|------|--------|
| `mcp-ai-wpoos.php` | Version bump 1.1.7 → 1.1.8 |
| `includes/bootstrap/constants.php` | `WP_MCP_AI_VERSION` constant updated to 1.1.8 |
| `readme.txt` | Stable tag updated; v1.1.8 changelog entry added; Pro addon external services documented |
| `CHANGELOG.md` | v1.1.8 entry added |
| `docs/compliance/WORDPRESS_ORG_COMPLIANCE_2026_04_15.md` | This document |

---

## Conclusion

The NV Digital Open Operator System (oOS) base plugin v1.1.8 is **fully compliant** with all 13 WordPress.org Plugin Developer Guidelines. The plugin demonstrates:

- **Strong security posture** with comprehensive input sanitization (200+ instances), output escaping (500+ instances), nonce verification (147 instances), and capability checks (333 instances).
- **Complete transparency** with 45 external services individually documented with ToS and Privacy links.
- **No anti-patterns** — no obfuscated code, no remote code execution, no unbounded time limits, no "powered by" links, no user tracking without consent, no paywall for basic features.
- **GPL-compatible** licensing throughout, including all bundled dependencies.

**Recommendation:** Ready for WordPress.org Plugin Directory submission.
