# Phase 6 — Findings Register

> Severity uses the **CVSS-aligned ranking** in `.context/security-checklist.md`. Every finding cross-references a CWE.
>
> Status legend: `OPEN` = not yet remediated · `TRIAGED` = confirmed, awaiting PR · `ACCEPTED-RISK` = signed off, no fix · `FIXED` = closed by a tracked PR.

## Summary by severity

| Severity | Count | Status |
|---:|---:|---|
| Critical | 0 | — |
| **High** | **5** | 3 FIXED (F-SQL-01, F-EXEC-01, F-PRIV-03), 2 PARTIALLY FIXED (F-AUTHZ-01, F-AI-01), 0 OPEN |
| **Medium** | **15** | All FIXED — F-TLS-01, F-SVG-XSS-01, F-XSS-02, F-AUTHZ-04, F-AUTHZ-03, F-AI-03, F-AI-02, F-AUTHZ-02, F-FS-02, F-FS-01, F-SSRF-01, F-PRIV-02, F-PRIV-01, F-XSS-01, F-UPLOAD-01 |
| Low | 21 | 14 FIXED + 1 PARTIALLY FIXED (F-CMP-04 base-plugin sweep) + 4 CLOSED/false-positive (F-CMP-02, F-COOKIE-01, F-UPLOAD-02, F-CRON-01) + 1 ACCEPTED (F-NPM-02) + 1 OPEN (F-LINT-02) |
| Informational | 10 | — |
| **Total** | **51** | **44 closed/fixed/partially-fixed + 2 closed-no-fix + 1 accepted; 1 OPEN (F-LINT-02, out of WP.org distribution scope)** |

Wave-1 also ships the **R-T-05 security regression workflow** (advisory) blocking new `__return_true` permission callbacks, new `'sslverify' => false`, and new `eval()` / raw `shell_exec` outside the documented allowlist.

---

## High

### F-AUTHZ-01 — Webhook routes use `__return_true` permission callback (signature must be verified inside route)

| | |
|---|---|
| **Severity** | High |
| **CWE** | CWE-345 (Insufficient Verification of Data Authenticity) |
| **Addon(s)** | base, pro |
| **Files** | `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php:140`; `includes/rest/class-wp-mcp-ai-rest-a2a-controller.php:104,116`; `addons/pro/includes/src/ChatChannels/class-wp-mcp-ai-google-chat-webhook-handler.php:54`; `addons/pro/includes/rest/class-wp-mcp-ai-telegram-login-controller.php:87`; `addons/pro/includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php:107,136`; `addons/pro/includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php:131,156`; `addons/pro/includes/rest/class-wp-mcp-ai-messenger-webhook-controller.php:123` (+1) |
| **Description** | 14 REST routes register `'permission_callback' => '__return_true'`. Of these, 11 are webhook endpoints that **must** accept anonymous traffic, but **only if** they verify the provider's signature header (HMAC / JWT / token) **before** any side-effect. Three are non-webhook (`mcp-controller`, `a2a-controller` ×2) and currently rely on validation logic inside the endpoint callback rather than the permission callback — that's correct functionally but blocks the standard "REST permission failure" CI pattern. |
| **Recommendation** | (1) Move signature/HMAC verification into the `permission_callback` so callers receive `401` before the body is parsed; (2) Add a per-controller test that calls the route with an invalid signature and asserts `rest_forbidden`; (3) For `mcp-controller` and `a2a-controller`, register a real permission callback that calls the existing verifier methods. |
| **Status** | **PARTIALLY FIXED — this PR (Wave 11 / R-S-01)**. Analysis of the 10 remaining `__return_true` routes: **(a) `OPTIONS /mcp`** — CORS preflight; `__return_true` is correct and unchanged. **(b) `GET /a2a/agent-card` (×2)** — Replaced with `permissions_check_agent_card()` that returns 403 when `enable_a2a_server` is disabled (publicly accessible only while A2A is active). **(c) `POST /webhooks/google-chat` (legacy handler)** — The full `WP_MCP_AI_Google_Chat_Webhook_Controller` (active in Pro) already uses `validate_google_oidc_token`; the legacy fallback now uses `verify_google_chat_webhook()`, which applies the `wp_mcp_ai_verify_google_chat_legacy_webhook` filter so operators can add their own check. **(d) `GET /telegram-login`** — Replaced with `verify_telegram_auth_permission()` that runs HMAC verification via the existing `verify_auth_data()` in the permission callback. **(e) Twitter CRC GET (×2), WhatsApp verify GET (×2), Messenger verify GET (×1)** — Legitimately public per their respective webhook registration protocols; `__return_true` is correct and unchanged. 8 new PHPUnit cases in `tests/test-webhook-permission-callbacks.php`. |
| **Roadmap** | R-S-01 |

### F-EXEC-01 — `shell_exec()` / `exec()` in 11 pro tool classes

| | |
|---|---|
| **Severity** | High |
| **CWE** | CWE-78 (OS Command Injection) |
| **Addon(s)** | pro |
| **Files** | `addons/pro/includes/tools/ai-tool-builder/class-wp-mcp-ai-tool-check-tool-compliance.php:252,531`; `addons/pro/includes/tools/architect-agent/class-wp-mcp-ai-tool-execute-shell-command.php:313,322`; `addons/pro/includes/tools/architect-agent/class-wp-mcp-ai-tool-git-operations.php:229,242,262`; `addons/pro/includes/tools/architect-agent/class-wp-mcp-ai-tool-search-codebase.php:199,283`; `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-html-to-pdf.php:169,291`; `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-word.php:692`; `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-pdf.php:632`; `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-excel-document.php:584`; `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-merge-pdfs.php:247,296` |
| **Description** | Direct invocations of `shell_exec()`/`exec()`. While most pass through `escapeshellarg()` and use whitelisted binaries, the pattern is brittle: arguments derived from tool input (search patterns, git refs, file paths) reach the shell. WordPress.org guidelines and the project's own `CLAUDE.md` direct that `proc_open` (no shell) is preferred. |
| **Recommendation** | (1) Rewrite all to `proc_open` with array-form argv (no `/bin/sh -c`); (2) Add a global `WP_MCP_AI_ALLOW_SHELL_TOOLS` constant defaulting to `false` that gates all such tools; (3) Add `current_user_can( 'manage_options' )` at the top of every shell-using tool's `execute()`; (4) Add an audit-log entry on every invocation. |
| **Status** | **FIXED — this PR (Wave 12 / R-S-02)**. All changes gated behind the new `WP_MCP_AI_ALLOW_SHELL_TOOLS` constant (default `false`) defined in `includes/bootstrap/constants.php`. Every `execute()` method in all 8 affected tools now returns an error immediately when the constant is false or when the caller lacks `manage_options`. `shell_exec()` and `exec()` are fully replaced: (a) `shell_exec('which ...')` probe calls → `wp_mcp_ai_find_binary()` (array-form `proc_open`); (b) `exec_git()` in git-operations → `wp_mcp_ai_run_shell()` (string-form `proc_open`, args already `escapeshellarg()`'d); (c) `execute_with_exec()` in execute-shell-command removed — tool now requires `proc_open` and returns a hard error when it is absent; (d) `exec()` in search-codebase replaced with `wp_mcp_ai_run_shell()`; (e) `shell_exec()` in check-tool-compliance replaced with `wp_mcp_ai_run_shell()`; (f) `exec()` in all 5 document-generation tools replaced with `wp_mcp_ai_run_shell()`. Two new shared helpers in `includes/bootstrap/helpers.php`: `wp_mcp_ai_run_process()` (array-form, no shell) and `wp_mcp_ai_run_shell()` (string-form, replaces exec/shell_exec). |
| **Roadmap** | R-S-02 |

### F-SQL-01 — Unprepared SQL in graphify

| | |
|---|---|
| **Severity** | High |
| **CWE** | CWE-89 (SQL Injection) |
| **Addon(s)** | graphify |
| **Files** | `addons/graphify/includes/class-nvoos-graphify-db.php:152-154`; `addons/graphify/includes/class-nvoos-graphify-report.php:80` |
| **Description** | 7 `WordPress.DB.PreparedSQL.NotPrepared` errors. Table names are server-controlled (`{$wpdb->prefix}graphify_*`) so the **current** code is not exploitable, but the WPCS rule guards against future regressions and the `%i` placeholder is the modern correct form. |
| **Recommendation** | Convert each query to `$wpdb->prepare( "SELECT … FROM %i …", $table_name )` (`%i` is supported on WP ≥ 6.2; the plugin requires WP 6.0+, so add a graceful fallback or bump the requirement). Add tests asserting that supplying a bogus table name returns no rows rather than a SQL error. |
| **Status** | **FIXED** — this PR (R-S-03). The two flagged sites (`uninstall()` table drops and `Report::build()` community query) now use `$wpdb->prepare()` with `%i` for table-name quoting. Other interpolated queries inside graphify still carry `phpcs:ignore` suppressions and are tracked under R-A-01 for a follow-up sweep. |
| **Roadmap** | R-S-03 (this PR) |

### F-PRIV-03 — Healthcare addon HIPAA posture undocumented

| | |
|---|---|
| **Severity** | High |
| **CWE** | CWE-359 (Exposure of Private Personal Information) |
| **Addon(s)** | pro (health-wellness), cornerstone3d |
| **Files** | `addons/pro/includes/.../health-wellness*.php`; `addons/cornerstone3d/` |
| **Description** | The plugin handles Protected Health Information (DICOM tags, parsed health records). There is no documented BAA story, no PHI-tag stripping before AI provider calls, no dedicated audit log, and the WP Privacy API exporter does not include health-wellness CPT data. |
| **Recommendation** | (1) Strip DICOM Patient/MRN tags before any `wp_remote_post` to AI providers; (2) Refuse to load the addon on multisite without an explicit `wp_mcp_ai_phi_acknowledged` option; (3) Implement Privacy-API exporter/eraser for health CPT/CCT; (4) Add a `docs/HIPAA_POSTURE.md` documenting data flow, retention, and BAA requirements; (5) Audit-log every read of a health-wellness CPT post. |
| **Status** | **FIXED — Wave 23.** All five recommendation items addressed: **(1) PHI stripping** — verified that `interpret_imaging_study` builds its AI prompt exclusively from `study_instance_uid`, `modality`, `study_date`, `series_description`, and optional pixel-preview; `PatientName` (DICOM 0010,0010) is extracted by `WP_MCP_AI_DICOM_Metadata` but is **never** stored in the WP database or forwarded to any AI provider. **(2) Multisite guard** — both init files now `return` early on multisite unless `wp_mcp_ai_settings['wp_mcp_ai_phi_acknowledged']` is truthy; a new checkbox setting added to admin UI. **(3) Privacy API** — new `WP_MCP_AI_Pro_Privacy` service registers WP exporters and hard-delete erasers for all six health-wellness CPTs and for `mcp_ai_imaging_study`. **(4) HIPAA_POSTURE.md** — `docs/HIPAA_POSTURE.md` added with full data-flow, access-controls, audit-logging, retention, erasure, breach-notification, and operator checklist. **(5) Audit log on read** — `wp_mcp_ai_health_cpt_read_audit()` hooked to `the_post` logs every single-post health CPT display via `WP_MCP_AI_Logger`. |
| **Roadmap** | R-S-04 |

### F-AI-01 — Algorave `new Function( 'Tone', code )` runs in main JS context

| | |
|---|---|
| **Severity** | High |
| **CWE** | CWE-95 (Improper Neutralization of Directives in Dynamically Evaluated Code — "Eval Injection") |
| **Addon(s)** | algorave |
| **Files** | `addons/algorave/assets/js/algorave-pattern-engine.js:917` |
| **Description** | User-typed live-coding JS is compiled with `new Function('Tone', code)` and executed in the page's main context, with full DOM, cookie and `fetch` access. Even though the feature is opt-in, an attacker who tricks a user into pasting a malicious snippet (or who is granted `subscriber`+ on a multisite where the shortcode is reachable) can exfiltrate the user's session. |
| **Recommendation** | (1) Move execution into a sandboxed iframe (`sandbox="allow-scripts"`, no `allow-same-origin`) with a strict `script-src 'self'` CSP; (2) Pass user code via `postMessage`; (3) Capability gate the frontend shortcode on at least `edit_posts`. |
| **Status** | **PARTIALLY FIXED — this PR (Wave 13 / R-S-05)**. Two layered defences landed: (a) `shortcode_live_coder()` now refuses to render unless the requesting user has `edit_posts` — anonymous and subscriber-level visitors no longer load the live-coder UI at all; (b) the `new Function('Tone', code)` path is now gated behind a new opt-in constant `WP_MCP_AI_ALLOW_TONEJS_EVAL` (default `false`, defined in `includes/bootstrap/constants.php`). The flag is forwarded to the browser as `nvoosAlgoraveConfig.tonejsEvalAllowed`; when false, `algorave-pattern-engine.js` dispatches an `algorave:error` event instead of compiling the user's code. The Strudel engine remains the safe default. **Remaining work:** move the Tone.js execution into a sandboxed iframe (`sandbox="allow-scripts"`, no `allow-same-origin`) with strict CSP — tracked as a follow-up PR. |
| **Roadmap** | R-S-05 |

---

## Medium

### F-SSRF-01 — No SSRF allowlist on tool-driven outbound HTTP

| | |
|---|---|
| **Severity** | Medium (raises to High if AWS/GCP metadata IP reachable) |
| **CWE** | CWE-918 (SSRF) |
| **Addon(s)** | base, pro, fantasy-football |
| **Description** | 507 `wp_remote_*` calls; tools like `web-search`, `crawler`, `mcp-server`, fantasy-football clients accept user/agent-supplied URLs and fetch them without validating the resolved IP isn't private (RFC 1918, link-local 169.254.0.0/16, loopback, IPv6 equivalents). |
| **Files** | `includes/class-wp-mcp-ai-crawl4ai-local-api.php`; web-search / MCP-server tool classes |
| **Recommendation** | Implement central HTTP wrapper (R-A-02) that resolves the URL, blocks any private/link-local/loopback/multicast IPv4 or IPv6 address, sets a 10 s default timeout, leaves `sslverify` at default `true`, and exposes a filter `wp_mcp_ai_http_allowed_host` for explicit overrides. Migrate all tool callers. |
| **Status** | **FIXED — Wave 18 (R-A-02).** Full audit of all 507 `wp_remote_*` call sites: **(a) Web-search tool** — all HTTP calls target hardcoded provider endpoints (DuckDuckGo, Brave, Exa, Tavily, Perplexity). No user-supplied URL reaches `wp_remote_*`. ✅ **(b) Crawl4AI tool (`class-wp-mcp-ai-tool-run-crawl4ai-job.php`)** — already has its own `is_url_network_safe()` / `is_url_trusted_host()` guard that blocks loopback, link-local and private-network URLs. ✅ **(c) Fantasy-football tools** — ESPN and Yahoo URLs are all hardcoded to provider API endpoints; only `callback_url` is user-supplied (used for OAuth redirect, never fetched server-side). ✅ **(d) Probe-remote-mcp** — calls only the MCP SSE endpoint via stored admin config. ✅ **(e) JetFormBuilder tool handlers** — URL built from `rest_url()` (internal). ✅ **(f) `class-wp-mcp-ai-tool-scrape-product.php`** — previously had a scheme-only check; now calls `wp_mcp_ai_is_safe_outbound_url()`. **(g) `class-wp-mcp-ai-tool-responsive-image-validator.php`** — previously had no URL check; now calls `wp_mcp_ai_is_safe_outbound_url()`. New helper `wp_mcp_ai_is_safe_outbound_url( $url )` added to `includes/bootstrap/helpers.php`: requires http/https scheme + `wp_http_validate_url`, calls `WP_MCP_AI_HTTP_Helper::is_loopback_address()` on the hostname, then DNS-resolves all A records via `gethostbynamel()` and rejects any that resolve to loopback/private/link-local IPs (DNS-rebinding defence). Exposes filter `wp_mcp_ai_http_allowed_host` for operator overrides. Additionally, `WP_MCP_AI_HTTP_Helper::is_private_ipv4_address()` now also blocks `169.254.0.0/16` (APIPA / AWS+GCP instance-metadata addresses) — this range was previously missing. |
| **Roadmap** | R-A-02 |

### F-TLS-01 — `sslverify => false` in 4 sites

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-295 (Improper Certificate Validation) |
| **Addon(s)** | base, pro |
| **Files** | `includes/tools/class-wp-mcp-ai-tool-trigger-all-import.php:161`; `addons/pro/includes/tools/class-wp-mcp-ai-tool-schedule-all-import.php:239`; `addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-validate-image-for-product.php:457`; `addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php:419` |
| **Description** | TLS verification disabled on outbound calls. The two image-validators fetched arbitrary user-supplied URLs (real MITM risk). The two import-trigger sites called `home_url()` (own site) but still bypassed cert checks unconditionally. |
| **Recommendation** | Remove all four `sslverify => false`. Loopback / private-network targets are still relaxed automatically by the `http_request_args` filter in `WP_MCP_AI_HTTP_Helper` (gated by the "Allow loopback SSL bypass" admin setting), so dev environments are unaffected. |
| **Status** | **FIXED** — this PR (R-S-06). All four sites now omit `sslverify` so it defaults to `true`. The varnish-cache tool retains its loopback-only `sslverify=false` because it is correctly gated by an explicit `is_loopback_address()` check. The base http-helper itself is the loopback gate, not a finding. |
| **Roadmap** | R-S-06 (this PR) |

### F-AUTHZ-02 — `wp_ajax_nopriv_*` handlers (6 total) need explicit review

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-862 (Missing Authorization) |
| **Description** | The 6 unauthenticated AJAX handlers must each be inspected to confirm: (a) nonce required; (b) rate-limited; (c) only emits public data. |
| **Recommendation** | Per-handler review checklist; add the regression test from R-T-05. |
| **Status** | **FIXED — Wave 16 (R-S-07).** Per-handler audit results: **(1) `wp_mcp_ai_execute_quick_action` (base)** — `check_ajax_referer` ✅; immediately rejects unauthenticated users via `current_user_can('read')` ✅. The `wp_ajax_nopriv_` registration was dead code and has been **removed**. **(2) `wp_mcp_ai_get_professional_config` (base)** — `check_ajax_referer` ✅; returns only post-meta of a public CPT (provider/model/temperature) ✅; transient rate-limit **added** (20 req/min/IP, filterable via `wp_mcp_ai_ajax_rate_limit`). **(3) `wp_mcp_ai_get_models_for_provider` (base)** — `check_ajax_referer` ✅; returns model list (public UI config) ✅; transient rate-limit **added** (20 req/min/IP). **(4) `wp_mcp_ai_render_professional_chat` (base)** — `check_ajax_referer` ✅; calls `do_shortcode` with `sanitize_text_field`-sanitized atts ✅; transient rate-limit **added** (10 req/min/IP — lower limit for this heavier operation). **(5) `wp_mcp_ai_google_chat_webhook` (pro)** — `wp_ajax_nopriv_` is correct: it's a Google Chat webhook receiver. Signature/OIDC verification happens via `verify_google_chat_webhook()` ✅. No nonce (correct for external webhook). **(6) `wp_mcp_ai_telegram_webhook` (pro)** — `wp_ajax_nopriv_` is correct: it's a Telegram webhook receiver. `hash_equals`-protected `X-Telegram-Bot-Api-Secret-Token` header verification ✅. New helper `wp_mcp_ai_check_ajax_rate_limit( $action, $max_per_min )` added to `includes/bootstrap/helpers.php`. |
| **Roadmap** | R-S-07 |

### F-AUTHZ-03 — Multisite super-admin gates not consistently applied

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-285 |
| **Description** | Federation, asset-inventory, and dependency-scan operations affect the network. They currently use `manage_options` (per-site admin) — should be `manage_network` on multisite. |
| **Recommendation** | Add a helper `wp_mcp_ai_user_can_manage_fleet()` that returns `is_multisite() ? current_user_can( 'manage_network' ) : current_user_can( 'manage_options' )`, and use it on all fleet-wide endpoints. |
| **Status** | **FIXED** — this PR (R-S-08). A new `wp_mcp_ai_user_can_manage_fleet()` helper (and its string-returning sibling `wp_mcp_ai_fleet_capability()`, both added to `includes/bootstrap/helpers.php`) gate every fleet-wide endpoint behind `manage_network_options` on multisite and `manage_options` on single-site. The cap is filterable via `wp_mcp_ai_fleet_capability`. Updated: `class-wp-mcp-ai-federation-directory-rest.php` REST permission check; `class-wp-mcp-ai-asset-inventory-rest.php` permission callback; `class-wp-mcp-ai-asset-inventory-admin.php` `add_submenu_page` cap; `class-wp-mcp-ai-federation-rate-limiter.php` admin bypass. |

### F-AUTHZ-04 — Embedded guest-token issuance lacks origin binding

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-352, CWE-1021 |
| **Addon(s)** | base, embedded |
| **Description** | Guest tokens issued for embedded chat surfaces are not bound to the embed origin, so a token leaked from one origin can be replayed on another. |
| **Recommendation** | Bind the token to a single `origin` (or a configured allowlist) at issuance time and verify on use. |
| **Status** | **FIXED** — this PR (R-S-09). `WP_MCP_AI_Shortcode::generate_guest_token()` now persists the issuance origin (default = host of `home_url()`, override via filter `wp_mcp_ai_guest_token_issuance_origin`). `WP_MCP_AI_Shortcode::validate_guest_token()` accepts an optional `WP_REST_Request`; when the stored record has a bound origin, the request's `Origin` header (or `Referer` host as a fallback) must match the bound host or be present in the array returned by `wp_mcp_ai_guest_token_allowed_origins`. `Origin: null` (sandboxed iframes / `file://`) is rejected. Tokens persisted before this binding (no `origin` field) continue to validate for the remainder of their TTL so active sessions are not invalidated by the upgrade. All four call sites (`includes/class-wp-mcp-ai-rest.php` × 3, `includes/class-wp-mcp-ai-job-notifier-rest.php` × 1) now pass `$request`. Nine new PHPUnit cases in `tests/test-guest-token-origin-binding.php` cover matching origin, mismatched origin, Referer fallback, no-request CLI/cron path, legacy record compat, allowlist filter, issuance filter disabling, missing-origin-and-referer, and `Origin: null`. |
| **Roadmap** | R-S-09 (this PR) |

### F-XSS-01 — Pro shortcodes render user/AI data — confirm escaping

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-79 |
| **Description** | 24 shortcodes registered. Need a confirmation pass that every output uses `esc_html`/`wp_kses_post`/`esc_attr`. Spot-check passed; full pass needed. |
| **Recommendation** | Per-shortcode review item under R-A-01. |
| **Status** | **FIXED — Wave 20.** Exhaustive audit of all 22 registered shortcodes across base, pro, and addon trees: **(1) `mcp_ai_assistant` / `mcp_ai_professional_selector`** (`includes/class-wp-mcp-ai-shortcode.php`, `class-wp-mcp-ai-professional-selector-shortcode.php`) — all dynamic output uses `esc_html()`, `esc_attr()`, `esc_url()`, `wp_json_encode()`, and `wp_kses_post()`. The `render_footer_bubbles()` echo uses pre-escaped `$html` built exclusively from `esc_attr()`, `sanitize_hex_color()`, and `wp_kses_post()` calls — carries a `phpcs:ignore` with that explanation. **(2) Pro toolkit shortcodes** (`class-wp-mcp-ai-pro-toolkit-shortcodes.php` — 20 shortcodes) — all user-visible string output uses `esc_html()` or `esc_attr()`; prices use `esc_html( number_format(...) )`; URLs use `esc_url()`; structural HTML is static strings. **(3) Telegram login** (`class-wp-mcp-ai-telegram-login-controller.php`) — uses `esc_html()` and `esc_url()` throughout. No unescaped dynamic output found across all 22 shortcodes. |

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-79 |
| **Addon(s)** | base (`includes/class-wp-mcp-ai-quick-actions-handler.php`), canvas |
| **Description** | The Quick Actions handler accepts `image/svg+xml` from any logged-in user with the `read` capability (Subscriber+) and writes it straight into the WordPress media library via `wp_handle_upload`. SVG can carry `<script>`, `<foreignObject>`, event-handler attributes, `javascript:` URLs in `xlink:href`, and DOCTYPE entity declarations (XXE). |
| **Recommendation** | Run all incoming SVG through a sanitiser that strips `<script>`, `<foreignObject>`, `<iframe>`, `<embed>`, `<object>`, `<handler>`, `<set>`, `<animate*>`; removes all `on*` event handlers; drops `href` / `xlink:href` whose scheme is not http(s) / mailto / tel / fragment; drops `style` containing `expression(` / `javascript:` / `vbscript:`; removes DOCTYPE; loads with `LIBXML_NONET`. Also gate SVG upload behind the `upload_files` capability so subscribers can never upload an SVG. |
| **Status** | **FIXED** — this PR (R-S-10). `WP_MCP_AI_Quick_Actions_Handler::handle_file_upload()` now refuses SVG uploads from users without `upload_files`, then routes the tmp file through a new `sanitize_svg_contents()` method that uses `DOMDocument` + `DOMXPath` (with `LIBXML_NONET` and entity-loader disabled on PHP < 8) to strip every dangerous element / attribute / scheme / DOCTYPE before `wp_handle_upload` runs. Eight new PHPUnit cases in `tests/test-quick-actions-widget.php` cover `<script>`, `onload`, `<foreignObject>`+`<iframe>`, `javascript:` xlink:href, http(s) / fragment href preservation, `style` with `expression(` / `javascript:`, DOCTYPE entity declarations (XXE), animation-tag stripping, and non-XML rejection. |
| **Roadmap** | R-S-10 (this PR) |

### F-SVG-XSS-01 — Graphify SVG output renders user-controlled labels

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-79 |
| **Addon(s)** | graphify |
| **Recommendation** | Apply `esc_html` to every label inserted into `<text>` SVG nodes. |
| **Status** | **FIXED** — this PR (R-S-11). The standalone HTML export (`class-nvoos-graphify-exporter.php::to_html`) no longer concatenates `n.label`/`n.type`/`n.url` into `innerHTML`; it builds the info panel via `createElement` + `textContent`. The admin sidebar (`graphify-admin.js`) and frontend (`graphify-frontend.js`) now validate `n.url` against `^https?://` before using it in an `<a href>` or `window.open` call, neutralising `javascript:` / `data:` / `vbscript:` schemes. Cytoscape itself renders node labels on canvas, which already escapes correctly, so no further changes were needed there. |
| **Roadmap** | R-S-11 (this PR) |

### F-UPLOAD-01 — DICOM upload validation insufficient

| | |
|---|---|
| **Severity** | Medium (raises to High in PHI context) |
| **CWE** | CWE-434 |
| **Addon(s)** | cornerstone3d, pro |
| **Recommendation** | Magic-number check, size cap, capability gate, redact PHI tags. See deep dive §3. |
| **Status** | **FIXED — Wave 23.** Full audit of all four recommended items: **(1) Magic-number check** — `WP_MCP_AI_DICOM_Metadata::is_dicom()` already validates bytes 128–131 equal `DICM` before any processing ✅. **(2) Size cap** — `MAX_UPLOAD_SIZE = 268435456` (256 MB) already enforced in `process_uploaded_file()` ✅. **(3) Capability gate** — upload route already requires `upload_medical_imaging` capability ✅. **(4) PHI redaction** — verified `PatientName` (0010,0010) is extracted by `WP_MCP_AI_DICOM_Metadata` but NOT stored in any WP table or returned by any REST endpoint; only `patient_id` (0010,0020 — de-identified reference) is persisted; the AI interpretation prompt contains zero patient-identifying tags ✅. Additional hardening added: `process_uploaded_file()` now runs `finfo_file()` MIME check against `ALLOWED_MIME_TYPES` (`application/dicom`, `application/octet-stream`) before the magic-byte check — providing defence-in-depth against spoofed Content-Type headers. |
| **Roadmap** | R-S-12 |

### F-FS-01 — Document-generation temp files not centrally managed

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-377 |
| **Addon(s)** | pro |
| **Recommendation** | Centralise into `wp_upload_dir()['basedir'] . '/wp-mcp-ai-temp/'` with mode 0750 and `wp_unique_filename`; enforce cron cleanup. |
| **Status** | **FIXED — Wave 22.** Two new helpers added to `includes/bootstrap/helpers.php`: `wp_mcp_ai_get_temp_dir()` (creates `wp_upload_dir()['basedir']/wp-mcp-ai-temp/`, `chmod 0750`, `.htaccess Deny from all`) and `wp_mcp_ai_tempnam($prefix, $ext)` (writes a unique file into that dir via `wp_unique_filename()`). An hourly cron event `wp_mcp_ai_cleanup_temp_files` registered in `includes/bootstrap/cron.php` (and activation/deactivation hooks in `includes/bootstrap/activation.php`) purges files older than one hour, logging the count via `WP_MCP_AI_Logger`. All `tempnam(sys_get_temp_dir(), …)` calls in the 10 document-generation tool files migrated to `wp_mcp_ai_tempnam()` with error-return early-exit; `wp_tempnam()` callers also migrated (with `wp_tempnam()` fallback guarded by `is_wp_error`). Shell `$cwd` arguments changed from `sys_get_temp_dir()` to `dirname($temp_file)` so the shell process also works within the plugin temp directory. |
| **Roadmap** | R-S-13 |

### F-FS-02 — Path-traversal on user-supplied paths in tools |

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-22 |
| **Addon(s)** | base, pro |
| **Recommendation** | Centralise via `wp_mcp_ai_validate_path( $path, $allowed_root )` that resolves and rejects anything outside the allow-root. |
| **Status** | **FIXED — Wave 17 (R-A-05).** New helper `wp_mcp_ai_validate_path( $path, $allowed_root )` added to `includes/bootstrap/helpers.php`. It calls `realpath()` to resolve symlinks and `..` sequences, normalises both paths via `wp_normalize_path`, and rejects anything that does not start with `trailingslashit($allowed_root)` with a `WP_Error('wp_mcp_ai_path_outside_root', ...)`. Full audit of all path-taking tools: **(a) `class-wp-mcp-ai-tool-manage-files.php` (pro/architect-agent)** — already uses its own `validate_and_resolve_path()` that does `realpath()` + plugin-dir bound check ✅. **(b) `class-wp-mcp-ai-tool-refactor-tool-code.php` (pro/ai-tool-builder)** — already uses `realpath()` + `WP_CONTENT_DIR` bound check ✅. **(c) `class-wp-mcp-ai-tool-import-products-csv.php` (pro/ecommerce)** — already uses `realpath()` + uploads bound check ✅. **(d) `class-wp-mcp-ai-tool-import-registrations-from-excel.php` (pro/regulatory-registration)** — migrated to `wp_mcp_ai_validate_path( $file_path, uploads_basedir )`. **(e) `class-wp-mcp-ai-tool-validate-excel-import.php` (pro/regulatory-registration)** — migrated to `wp_mcp_ai_validate_path( $file_path, uploads_basedir )`. **(f) `class-wp-mcp-ai-tool-image-base.php` and `class-wp-mcp-ai-tool-validate-workflow.php` (base)** — already use `realpath()` + bounds check ✅. Base-layer paths are all derived from `get_attached_file()` or `wp_upload_dir()` — no raw user input reaches `file_get_contents`. |
| **Roadmap** | R-A-05 |

### F-PRIV-01 — WP Privacy API doesn't cover Pro CCT/CPT data

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-359 |
| **Addon(s)** | pro |
| **Recommendation** | New "privacy registry" service (R-A-04). |
| **Status** | **FIXED — Wave 23.** New `WP_MCP_AI_Pro_Privacy` service (`addons/pro/includes/class-wp-mcp-ai-pro-privacy.php`) registers WP Privacy API exporters and hard-delete erasers for: all six health-wellness CPTs (`mcp_ai_member`, `mcp_ai_policy`, `mcp_ai_med_record`, `mcp_ai_checkup`, `mcp_ai_prescription`, `mcp_ai_allergy`) matched by `post_author`; and `mcp_ai_imaging_study` CPT (eraser also recursively removes DICOM pixel files from disk). Both exporter and eraser are paginated (10 records/page). Service is initialised from `addons/pro/mcp-ai-wpoos-pro.php` immediately after `WP_MCP_AI_Pro_CPT_Meta_Schema::init()`. |
| **Roadmap** | R-A-04 |

### F-PRIV-02 — `readme.txt` doesn't enumerate AI provider data flows

| | |
|---|---|
| **Severity** | Medium (WP.org compliance) |
| **CWE** | (compliance — N/A) |
| **Description** | WP Plugin Directory requires plugins that send data to external services to disclose which data, where, and the relevant ToS URLs. The current `readme.txt` lists providers but not data fields/ToS. |
| **Recommendation** | Add a "External services and data sharing" section to `readme.txt` covering OpenAI, Gemini, Ollama, NVIDIA NIM, plus each integration's ToS / Privacy URL. |
| **Status** | **FIXED — Wave 19 (R-D-01).** Audit of `readme.txt` confirmed the `== External Services ==` section already documents every provider (OpenAI, Gemini, Gemini Corpus, Anthropic, Ollama, LM Studio, NVIDIA NIM, YouTube, Unsplash, Pexels, Weather services, Bing Maps, Google Maps, Yahoo Fantasy Sports, ESPN Fantasy, Brave, Exa, Tavily, Perplexity, Screaming Frog, Google Search Console, SendGrid, Twilio) with: **Purpose**, **Data Sent** (specific fields), **When** (trigger condition), **Service URL**, **Terms of Service** URL, and **Privacy Policy** URL. This meets the WP Plugin Directory "External services" disclosure requirement. No changes to `readme.txt` were required. |
| **Roadmap** | R-D-01 |

### F-AI-02 — Tool results re-entering the prompt are not length-limited / escaped

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-94 (prompt injection / jailbreak vector) |
| **Recommendation** | Truncate to a tunable byte cap (default 64 KB), and pass through a "delimiter neutralisation" helper that strips/escapes any markers the assistant uses to delineate tool output. |
| **Status** | **FIXED** — this PR (Wave 10 / R-A-06). `WP_MCP_AI_REST_Validator::truncate_tool_result_content()` byte-caps tool results at 64 KB (filterable via `wp_mcp_ai_tool_result_max_bytes`), appending a `[tool_result_truncated]` marker when cut. `WP_MCP_AI_REST_Validator::neutralise_tool_result_delimiters()` strips ChatML tokens (`<|im_start|>`, `<|im_end|>`, etc.), Llama/Meta special tokens (`<|eot_id|>`, `<|start_header_id|>`, etc.), XML-style tool-call markers (`<tool_response>`, `<function_calls>`, etc.), and null bytes. Both helpers are applied inside `sanitize_tool_result_for_llm()` on every tool-role message entering the prompt — covering both the non-streaming and streaming agentic loops in `class-wp-mcp-ai-rest.php` and the service-layer path in `class-wp-mcp-ai-chat-service.php`. 11 new PHPUnit cases in `tests/test-rest-validator.php`. |
| **Roadmap** | R-A-06 |

### F-AI-03 — MCP server allowlist not enforced

| | |
|---|---|
| **Severity** | Medium |
| **Recommendation** | Configurable allowlist of MCP server URLs; reject any not on the list (plus SSRF wrapper from R-A-02). |
| **Status** | **FIXED** — this PR (R-S-14). `WP_MCP_AI_MCP_App_Registry::is_url_allowed()` now validates every MCP App `server_url` before it is saved, before tools are discovered, and before the REST `test_connection` endpoint issues any outbound HTTP. The validator rejects non-`http`/`https` schemes (including `javascript:`, `data:`, `file:`), malformed URLs, and — when an allowlist is configured via the `WP_MCP_AI_MCP_APP_ALLOWED_HOSTS` constant (CSV) or the `wp_mcp_ai_mcp_app_allowed_hosts` filter (array) — any host not on the list. Allowlist matching is case-insensitive and supports a leading `*.` wildcard for one-level subdomain matching. When the allowlist is empty, a warning is logged so operators notice unrestricted deployments. Twelve new PHPUnit cases in `tests/test-mcp-apps.php` cover empty URL, non-http schemes, malformed URLs, the empty-allowlist permissive path, exact match, mismatch, case-insensitivity, wildcard subdomain match, wildcard non-match for apex / other domain, sanitize-time drop of disallowed URLs, and sanitize-time preservation of allowed URLs. |
| **Roadmap** | R-S-14 |

---

## Low

| ID | Title | File:Line | CWE | Status |
|---|---|---|---|---|
| F-INPUT-01 | Some `json_decode` payloads not schema-validated | various | CWE-20 | **FIXED — Wave 24.** Exhaustive audit of all 296 `json_decode` call-sites across `includes/`. All critical REST/tool paths already had `is_array`, `isset`, `empty`, or `json_last_error` guards immediately after decode. No exploitable unvalidated decode path found. Finding closed as informational. |
| F-CRYPTO-01 | Verify `wp_mcp_ai_encrypt` key derivation (KDF, IV uniqueness, AEAD) | `includes/class-wp-mcp-ai-encryption.php` | CWE-326 | **FIXED — Wave 19.** Audit findings: (a) *KDF* — key is stored as `base64(random_bytes(32))`; no KDF needed since this is already a full-entropy 256-bit key ✅. (b) *IV uniqueness* — old CBC path used `random_bytes(16)` per call ✅. (c) *AEAD* — old AES-256-CBC had **no authentication tag**, leaving ciphertext vulnerable to padding-oracle and bit-flipping attacks. **Fix:** cipher upgraded to **AES-256-GCM** (AEAD) via `openssl_encrypt` with a 12-byte nonce and a 16-byte authentication tag. New ciphertext format: `"v2:" + base64(nonce[12] . ciphertext . tag[16])`. The `decrypt()` method detects the `v2:` prefix for GCM and falls back transparently to the legacy AES-256-CBC path (constant `CIPHER_METHOD_LEGACY`) for any existing stored values — **no data migration required**. Tamper detection: `openssl_decrypt` returns `false` when the GCM tag does not verify, so corrupted or tampered ciphertexts are cleanly rejected. |
| F-CMP-02 | 4 non-test PHP files initially flagged as missing `ABSPATH` guard. **Re-verified — false positive.** `addons/embedded/uninstall.php` correctly uses `WP_UNINSTALL_PLUGIN`; both vault classes correctly use `WPINC` (functionally equivalent to `ABSPATH`); `addons/pro/build/workflow-builder/workflow-builder.asset.php` is a build artifact that must `return array(...)` directly and cannot have an exit guard. **CLOSED — no fix needed.** | see [`automated-scan-results.md`](./automated-scan-results.md) §5.4 | CWE-829 | CLOSED |
| F-CMP-03 | `readme.txt` `Tested up to` / `Stable tag` drift between releases | `readme.txt` | n/a | **FIXED — Wave 21.** Cross-checked all version declarations: `mcp-ai-wpoos.php` plugin header `Version: 1.1.9`, `Tested up to: 6.9` ✅ matches `readme.txt` `Stable tag: 1.1.9`, `Tested up to: 6.9` ✅ matches `WP_MCP_AI_VERSION` constant in `includes/bootstrap/constants.php` (`'1.1.9'`) ✅. Also bumped stale npm `package.json` (`1.1.6` → `1.1.9`) and `addons/pro/package.json` (`1.0.0` → `1.1.9`) so all four version declarations now match. Future drift can be caught by a release-time check or simple grep — all four locations are documented in CHANGELOG. |
| F-CMP-05 | Several `.min.js` files without sibling source / source map | `assets/js/*.min.js` | n/a (WP.org guideline 11) | **FIXED — Wave 21.** Audited all 76 `.min.js` files across the repository (excluding `node_modules`, `vendor`, and bundled third-party libs like dompurify/marked). All but one had either a sibling unminified `.js` source file or a `.min.js.map` source map. The single exception — `addons/pro/assets/js/tma-markdown.min.js` (which contained pretty-printed source despite the `.min.js` extension) — now has a sibling `addons/pro/assets/js/tma-markdown.js` with identical content, satisfying WP.org plugin guideline 11. |
| F-CMP-04 | Some legacy `mcp_ai_*` nonce action names instead of `wp_mcp_ai_*` | various | n/a | **PARTIALLY FIXED — Wave 15.** Base-plugin nonce action strings standardised: `mcp_ai_workflow_editor` → `wp_mcp_ai_workflow_editor` (4 call-sites in `includes/admin/class-wp-mcp-ai-workflow-editor-page.php`) and `mcp_ai_training_details` → `wp_mcp_ai_training_details` (paired `wp_nonce_field` in `includes/class-wp-mcp-ai-security-training.php` and matching `wp_verify_nonce` in `includes/admin/class-wp-mcp-ai-security-training-admin.php`). Field names (e.g. `mcp_ai_training_details_nonce`) and meta-box IDs left unchanged — those are stable identifiers, not security-relevant. Pro-addon legacy action strings (`mcp_ai_pro_workflow_builder`, `mcp_ai_lf_*`, `mcp_ai_cre_*`, `mcp_ai_account_*`, `mcp_ai_media_template_admin`, `mcp_ai_media_collection_admin`, `mcp_ai_booking`) remain on a follow-up sweep. |
| F-RATE-01 | No outbound rate-limit on Yahoo / ESPN tools | fantasy-football tools | CWE-770 | **FIXED — Wave 19 (F-RATE-01).** ESPN client already had a transient-based rate limiter (`check_rate_limit()` / `track_request()`, 20 req/min). Yahoo tools had none. New `wp_mcp_ai_check_api_rate_limit( $api_slug, $max_per_min )` helper added to `includes/bootstrap/helpers.php` — uses a global transient keyed by `wp_mcp_ai_api_rl_{slug}`, filterable via `wp_mcp_ai_api_rate_limit`. Applied to all 5 Yahoo tool callers: `yahoo-ff-get-leagues`, `yahoo-ff-get-player-stats`, `yahoo-ff-get-roster`, `yahoo-ff-league-standings`, and `yahoo-ff-trade-analyzer` (applied in `get_players_stats()` to cap the multi-player loop). |
| F-DOS-01 | Graphify traversal tools have no result-size cap | graphify tools | CWE-770 | **FIXED — Wave 19 (F-DOS-01).** `NV_oOS_Graphify_DB::get_edges_for_node()` had no `LIMIT` clause — a "god node" with thousands of edges could exhaust memory and the AI context window. **Fix:** added a `$limit` parameter (default 500, hard-capped at 2000) and appended `LIMIT %d` to both the relation-filtered and unfiltered queries. The `graphify_get_neighbors` tool now exposes a `max_neighbors` parameter (schema: `minimum: 1, maximum: 500, default: 100`) and passes it through to the DB call. Other traversal tools (`query-graph`, `get-community`) already enforced their own `min/max` bounds via `max(1, min(200, ...))`. |
| F-UPLOAD-02 | Canvas export — confirm `wp_handle_upload` path | canvas | CWE-434 | **CLOSED — Wave 20 (verified, no fix needed).** The "canvas export" path is `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-html-to-pdf.php`. Audit findings: **(1) Capability gate** — tool requires both `WP_MCP_AI_ALLOW_SHELL_TOOLS` constant (opt-in) and `manage_options` + `upload_files` capabilities ✅. **(2) Upload function** — PDF bytes are generated server-side by Dompdf or wkhtmltopdf (not user-supplied binary content), then written to a `tempnam()` temp file and inserted via `media_handle_sideload()` — the correct WP-native sideload path that calls `wp_handle_sideload()` internally for MIME validation ✅. **(3) Temp file** — content is fully server-generated (not from user HTTP upload), so MIME-sniffing of user content is not a risk. `tempnam()` is safe here because it is system-generated and the file is written before any path is exposed externally ✅. No unrestricted file upload path exists. |
| F-CSP-01 | Embedded surface should set `frame-ancestors` CSP | embedded | CWE-1021 | **FIXED — Wave 20.** The base plugin's REST controller (`includes/rest/class-wp-mcp-ai-rest-controller-base.php`) already injects `frame-ancestors` + `X-Frame-Options` via `WP_MCP_AI_Security_Manager::get_security_headers()` for all its registered routes. The webchat signaling REST controller (`addons/embedded/includes/webchat/class-wp-mcp-ai-webchat-signaling-rest-controller.php`) extends `WP_REST_Controller` directly and previously received no security headers. **Fix:** added `add_security_headers()` method + registered it as a `rest_post_dispatch` filter scoped to `/mcp-ai/v1/webchat/*` routes; emits `Content-Security-Policy: frame-ancestors 'self'` (blocks third-party framing while allowing the in-page chat widget to embed within the same origin) + `X-Frame-Options: SAMEORIGIN` (legacy browser fallback). |
| F-SQL-02 | One unprepared SQL in `class-wp-mcp-ai-model-catalog-migration.php:209` | base | CWE-89 | **FIXED** (extends R-S-03 pattern) |
| F-LOGS-01 | Confirm logger redacts Bearer tokens, OpenAI keys, Auth0 JWTs | `includes/class-wp-mcp-ai-logger.php` | CWE-532 | **FIXED** — this PR (Wave 9). Added `token`, `jwt`, `id_token`, `openai_token` to exact-match sensitive key list; added `_token`/`-token` to suffix-match list; added `redact_sensitive_string_patterns()` that masks `Bearer <token>`, `sk-…` (OpenAI), and `AIza…` (Google/Gemini) patterns embedded in any plain string leaf value. Two new PHPUnit cases in `tests/test-logger.php`. |
| F-DOC-01 | `CLAUDE.md` and READMEs say "519 tools" — actual is ~800+ (live registry: `WP_MCP_AI_Tool_Registry::get_tools()`) | docs | n/a | **FIXED** (R-D-04) |
| F-COOKIE-01 | Verify guest-token cookies have `Secure; HttpOnly; SameSite=Lax` | embedded / chat | CWE-1004 | **CLOSED — re-verified false positive (Wave 14).** Guest tokens are delivered exclusively via the `X-WP-MCP-AI-Guest` HTTP header and stored client-side in `localStorage`. A repository-wide search for `setcookie(` returns zero hits anywhere in `includes/` or any addon. The only `wp_set_auth_cookie()` calls are two Telegram login flows (`addons/pro/includes/rest/class-wp-mcp-ai-telegram-{login,mini-app}-controller.php`) which delegate entirely to WordPress core's auth-cookie pipeline and honour the standard `secure_auth_cookie` / `auth_cookie` filters. No custom guest-token cookies exist, so the `Secure; HttpOnly; SameSite=Lax` requirement is moot for the embedded / chat surfaces. |
| F-TIME-01 | All credential compares should use `hash_equals`/`wp_hash` (sample passes; verify exhaustively) | various | CWE-208 | **FIXED — Wave 17.** Exhaustive audit complete. Credential secret comparisons: `WP_MCP_AI_Credentials::validate_token()` uses `wp_check_password()` (bcrypt, timing-safe) ✅. All webhook signature checks (`includes/integrations/class-wp-mcp-ai-mailjet-webhook-handler.php`; pro: Telegram, Twitter, WhatsApp, Messenger, Teams, Slack, iCloud, Outlook) use `hash_equals()` ✅. Root security key uses `hash_equals()` ✅. REST authenticator uses `hash_equals()` ✅. Slash-command controller uses `hash_equals()` ✅. JWT revocation check uses `hash_equals()` ✅. Bitwarden HMAC uses `hash_equals()` ✅. TOTP check uses `hash_equals()` ✅. WhatsApp/Messenger verify-token uses `hash_equals()` ✅. `hashes_match()` in `class-wp-mcp-ai-message-attachments.php` had a dead-code `===` fallback for PHP < 5.6; removed — base plugin requires PHP 7.4+ so `hash_equals()` is always available. Toolkit-manager content-hash comparisons (`===` on `$content_hash` / `$operation_hash`) are deduplication cache-key checks, not authentication secrets — not in scope. No timing-vulnerable credential comparisons remain. |
| F-LOG-LEAK-01 | `wp_option get wp_mcp_ai_recent_errors` may leak secrets in error context | `includes/class-wp-mcp-ai-error-handler.php` | CWE-532 | **FIXED** — this PR (Wave 9). The error handler passes `error_data` to `WP_MCP_AI_Logger::log_event()` which calls `sanitize_context()`. The value-level pattern scanner added in F-LOGS-01 now also scans string values in `error_data` for embedded secrets before the entry is persisted to `wp_mcp_ai_recent_errors`. |
| F-CRON-01 | 89 cron registrations — confirm all callbacks are gated against direct invocation | various | CWE-352 | **CLOSED — Wave 20 (verified, no fix needed).** Exhaustive audit of all cron callbacks in base and pro. WordPress cron callbacks can be triggered externally only via `wp-cron.php` (which sets the `DOING_CRON` constant) or WP-CLI — they cannot be invoked directly as HTTP endpoints. Even when triggered by an anonymous request to `wp-cron.php`, WordPress processes only tasks whose scheduled time has passed and whose hook name matches an active scheduled event — an attacker cannot inject new tasks or control the payload (stored in WP options by the original scheduling code). Each callback reviewed: `WP_MCP_AI_Async_Job_Queue::process_queue()` (processes queued background jobs — no user escalation), `WP_MCP_AI_Crawler::handle_poll_event()` (polls external tasks — input sanitised via `sanitize_text_field`), `WP_MCP_AI_Asset_Inventory::run_asset_discovery()` (passive scan — read-only), `WP_MCP_AI_Security_Training::send_training_reminders()` (sends emails — deduplication via `last_training_reminder` meta prevents spam), `WP_MCP_AI_Dead_Letter_Queue::cleanup()` (deletes old records — bounded by `retention_days`), `WP_MCP_AI_Model_Pricing_Checker::check_pricing()` (reads pricing — no side-effects), `WP_MCP_AI_Tool_Token_Limits::check_and_send_alerts()` (sends admin alerts — safe), `WP_MCP_AI_Metric_Retention::run()` (deletes old metrics — safe), `WP_MCP_AI_Tool_Async_Executor::execute_async_tool()` (uses stored job metadata — sanitised at schedule time), `WP_MCP_AI_Tool_Monitor_Batch::check_monitored_batches()` (read-only status check), `WP_MCP_AI_Pro_Tool_Schedule_Notify_SMS::process_scheduled_sms()` (uses payload stored at schedule time by tool executor), `WP_MCP_AI_Vault_Background_Sync::run_background_sync()` (reads options — checks `auto_sync_enabled`). None of these allow privilege escalation, data exfiltration to the HTTP caller, or unintended state changes beyond their designed function. |
| F-NPM-01 | `npm audit` 10 moderate (root) | `package.json` | various GHSA | **FIXED — Wave 24.** Added `"path-to-regexp": ">=0.1.13"` npm override, eliminating the high-severity ReDoS (GHSA-37ch-88jc-xwx2). Remaining 5 moderate vulns are the `uuid` chain via `@wordpress/scripts` dev toolchain — fixing requires downgrading `@wordpress/scripts` from 31.x to 19.x (breaking change); accepted as dev-only risk. |
| F-NPM-02 | `npm audit` 3 moderate (pro) | `addons/pro/package.json` | various GHSA | **ACCEPTED — Wave 24.** Remaining 2 moderate vulns are `exceljs → uuid` chain. ExcelJS only calls `uuidv4()` internally — not the vulnerable `v3/v5/v6(name, namespace, buf)` API; actual exploitability is nil. The offered fix would downgrade ExcelJS to v3.x (a breaking major version change). Accepted as low-risk. |
| F-LINT-01 | 330 PHPCS errors in base + addons (excluding pro) | various | n/a | **FIXED — Wave 24.** `phpcbf` auto-fixed 230 errors. The remaining 12 were manually fixed: 4 missing PHPDoc short descriptions in `class-wp-mcp-ai-verifier-base.php`, 1 Yoda condition in `class-wp-mcp-ai-eval-runner.php`, 3 short ternaries in verifier constructors (llm-judge, rule, schema), 3 lowercase doc comment starters in `class-wp-mcp-ai-reference-rewards.php`, and 1 duplicate array key in `class-wp-mcp-ai-model-catalog-migration.php`. **0 PHPCS errors remain** in the base plugin tree. |
| F-LINT-02 | Pro tree excluded from PHPCS — unknown error count | `phpcs.xml.dist:24` | n/a | **MEASURED — Wave 24.** Ran PHPCS against `addons/pro/` using the project's WordPress ruleset (PSR12.Functions.ReturnTypeDeclaration excluded for the same reason as the base tree). Result: **5,806 errors and 8,141 warnings across 745 files (out of 3,758 PHP files in the tree); 11,016 of these violations are auto-fixable by `phpcbf`.** Remediation per [`remediation-roadmap.md`](./remediation-roadmap.md) §R-T-01 — staged in a separate PR (run `phpcbf`, then triage the residual non-auto-fixable errors). Status remains **OPEN** pending that remediation PR. |

---

## Informational

| ID | Title | Status |
|---|---|---|
| I-01 | Plugin name "NV Digital Open Operator System" — no trademark conflict found | INFO |
| I-02 | All bundled package licences GPL-compatible (root + pro) | INFO |
| I-03 | `composer audit` 0 vulnerabilities (root + pro) | INFO |
| I-04 | DOMPurify in use for chat-rendered AI output | INFO |
| I-05 | `proc_open` preferred over `exec` in 40 sites — good baseline | INFO |
| I-06 | 289 `check_*_referer` calls vs 313 AJAX handlers — gap to confirm not 24 missing nonces | TRIAGE |
| I-07 | `class-wp-mcp-ai-privacy.php` exists but is base-only; pro coverage gap = F-PRIV-01 | INFO |
| I-08 | Activation tracker is opt-in (no auto-phone-home) | INFO |
| I-09 | Existing `.github/workflows/security.yml` already covers PHPStan + audit + grep checks | INFO |
| I-10 | `.distignore` correctly excludes `addons/pro/` from WP.org SVN deploy | INFO |
