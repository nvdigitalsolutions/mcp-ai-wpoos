# Phase 6 — Findings Register

> Severity uses the **CVSS-aligned ranking** in `.context/security-checklist.md`. Every finding cross-references a CWE.
>
> Status legend: `OPEN` = not yet remediated · `TRIAGED` = confirmed, awaiting PR · `ACCEPTED-RISK` = signed off, no fix · `FIXED` = closed by a tracked PR.

## Summary by severity

| Severity | Count | Status |
|---:|---:|---|
| Critical | 0 | — |
| **High** | **5** | 1 FIXED (F-SQL-01), 4 OPEN |
| Medium | 14 | 2 FIXED (F-TLS-01, F-SVG-XSS-01), 12 OPEN |
| Low | 21 | 1 CLOSED (F-CMP-02 re-verified false positive), 20 OPEN |
| Informational | 10 | — |
| **Total** | **50** | **4 closed, 46 open** |

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
| **Status** | OPEN |
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
| **Status** | OPEN |
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
| **Status** | OPEN |
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
| **Status** | OPEN |
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
| **Status** | OPEN |
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
| **Status** | TRIAGED |
| **Roadmap** | R-S-07 |

### F-AUTHZ-03 — Multisite super-admin gates not consistently applied

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-285 |
| **Description** | Federation, asset-inventory, and dependency-scan operations affect the network. They currently use `manage_options` (per-site admin) — should be `manage_network` on multisite. |
| **Recommendation** | Add a helper `wp_mcp_ai_user_can_manage_fleet()` that returns `is_multisite() ? current_user_can( 'manage_network' ) : current_user_can( 'manage_options' )`, and use it on all fleet-wide endpoints. |
| **Status** | OPEN |
| **Roadmap** | R-S-08 |

### F-AUTHZ-04 — Embedded guest-token issuance lacks origin binding

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-352, CWE-1021 |
| **Addon(s)** | base, embedded |
| **Description** | Guest tokens issued for embedded chat surfaces are not bound to the embed origin, so a token leaked from one origin can be replayed on another. |
| **Recommendation** | Bind the token to a single `origin` (or a configured allowlist) at issuance time and verify on use. |
| **Status** | OPEN |
| **Roadmap** | R-S-09 |

### F-XSS-01 — Pro shortcodes render user/AI data — confirm escaping

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-79 |
| **Description** | 24 shortcodes registered. Need a confirmation pass that every output uses `esc_html`/`wp_kses_post`/`esc_attr`. Spot-check passed; full pass needed. |
| **Recommendation** | Per-shortcode review item under R-A-01. |
| **Status** | OPEN |
| **Roadmap** | R-A-01 |

### F-XSS-02 — Canvas SVG upload pipeline must strip scripts

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-79 |
| **Addon(s)** | canvas |
| **Description** | Canvas editor accepts SVG. SVG can carry `<script>` and `<foreignObject>` elements. |
| **Recommendation** | Run all incoming SVG through an `wp_kses` profile that strips `<script>`, `<foreignObject>`, `xlink:href`, and event-handler attributes before persisting. |
| **Status** | OPEN |
| **Roadmap** | R-S-10 |

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
| **Status** | OPEN |
| **Roadmap** | R-S-12 |

### F-FS-01 — Document-generation temp files not centrally managed

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-377 |
| **Addon(s)** | pro |
| **Recommendation** | Centralise into `wp_upload_dir()['basedir'] . '/wp-mcp-ai-temp/'` with mode 0750 and `wp_unique_filename`; enforce cron cleanup. |
| **Status** | OPEN |
| **Roadmap** | R-S-13 |

### F-FS-02 — Path-traversal on user-supplied paths in tools |

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-22 |
| **Addon(s)** | base, pro |
| **Recommendation** | Centralise via `wp_mcp_ai_validate_path( $path, $allowed_root )` that resolves and rejects anything outside the allow-root. |
| **Status** | OPEN |
| **Roadmap** | R-A-05 |

### F-PRIV-01 — WP Privacy API doesn't cover Pro CCT/CPT data

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-359 |
| **Addon(s)** | pro |
| **Recommendation** | New "privacy registry" service (R-A-04). |
| **Status** | OPEN |
| **Roadmap** | R-A-04 |

### F-PRIV-02 — `readme.txt` doesn't enumerate AI provider data flows

| | |
|---|---|
| **Severity** | Medium (WP.org compliance) |
| **CWE** | (compliance — N/A) |
| **Description** | WP Plugin Directory requires plugins that send data to external services to disclose which data, where, and the relevant ToS URLs. The current `readme.txt` lists providers but not data fields/ToS. |
| **Recommendation** | Add a "External services and data sharing" section to `readme.txt` covering OpenAI, Gemini, Ollama, NVIDIA NIM, plus each integration's ToS / Privacy URL. |
| **Status** | OPEN |
| **Roadmap** | R-D-01 |

### F-AI-02 — Tool results re-entering the prompt are not length-limited / escaped

| | |
|---|---|
| **Severity** | Medium |
| **CWE** | CWE-94 (prompt injection / jailbreak vector) |
| **Recommendation** | Truncate to a tunable byte cap (default 64 KB), and pass through a "delimiter neutralisation" helper that strips/escapes any markers the assistant uses to delineate tool output. |
| **Status** | OPEN |
| **Roadmap** | R-A-06 |

### F-AI-03 — MCP server allowlist not enforced

| | |
|---|---|
| **Severity** | Medium |
| **Recommendation** | Configurable allowlist of MCP server URLs; reject any not on the list (plus SSRF wrapper from R-A-02). |
| **Status** | OPEN |
| **Roadmap** | R-S-14 |

---

## Low

| ID | Title | File:Line | CWE | Status |
|---|---|---|---|---|
| F-INPUT-01 | Some `json_decode` payloads not schema-validated | various | CWE-20 | OPEN |
| F-CRYPTO-01 | Verify `wp_mcp_ai_encrypt` key derivation (KDF, IV uniqueness, AEAD) | `includes/class-wp-mcp-ai-encryption.php` | CWE-326 | OPEN |
| F-CMP-02 | 4 non-test PHP files initially flagged as missing `ABSPATH` guard. **Re-verified — false positive.** `addons/embedded/uninstall.php` correctly uses `WP_UNINSTALL_PLUGIN`; both vault classes correctly use `WPINC` (functionally equivalent to `ABSPATH`); `addons/pro/build/workflow-builder/workflow-builder.asset.php` is a build artifact that must `return array(...)` directly and cannot have an exit guard. **CLOSED — no fix needed.** | see [`automated-scan-results.md`](./automated-scan-results.md) §5.4 | CWE-829 | CLOSED |
| F-CMP-03 | `readme.txt` `Tested up to` / `Stable tag` drift between releases | `readme.txt` | n/a | OPEN |
| F-CMP-04 | Some legacy `mcp_ai_*` nonce action names instead of `wp_mcp_ai_*` | various | n/a | OPEN |
| F-CMP-05 | Several `.min.js` files without sibling source / source map | `assets/js/*.min.js` | n/a (WP.org guideline 11) | OPEN |
| F-RATE-01 | No outbound rate-limit on Yahoo / ESPN tools | fantasy-football tools | CWE-770 | OPEN |
| F-DOS-01 | Graphify traversal tools have no result-size cap | graphify tools | CWE-770 | OPEN |
| F-UPLOAD-02 | Canvas export — confirm `wp_handle_upload` path | canvas | CWE-434 | OPEN |
| F-CSP-01 | Embedded surface should set `frame-ancestors` CSP | embedded | CWE-1021 | OPEN |
| F-SQL-02 | One unprepared SQL in `class-wp-mcp-ai-model-catalog-migration.php:209` | base | CWE-89 | OPEN |
| F-LOGS-01 | Confirm logger redacts Bearer tokens, OpenAI keys, Auth0 JWTs | `includes/class-wp-mcp-ai-logger.php` | CWE-532 | OPEN |
| F-DOC-01 | `CLAUDE.md` and READMEs say "519 tools" — actual is ~845 | docs | n/a | OPEN |
| F-COOKIE-01 | Verify guest-token cookies have `Secure; HttpOnly; SameSite=Lax` | embedded / chat | CWE-1004 | OPEN |
| F-TIME-01 | All credential compares should use `hash_equals`/`wp_hash` (sample passes; verify exhaustively) | various | CWE-208 | OPEN |
| F-LOG-LEAK-01 | `wp_option get wp_mcp_ai_recent_errors` may leak secrets in error context | `includes/class-wp-mcp-ai-error-handler.php` | CWE-532 | OPEN |
| F-CRON-01 | 89 cron registrations — confirm all callbacks are gated against direct invocation | various | CWE-352 | OPEN |
| F-NPM-01 | `npm audit` 10 moderate (root) | `package.json` | various GHSA | OPEN |
| F-NPM-02 | `npm audit` 3 moderate (pro) | `addons/pro/package.json` | various GHSA | OPEN |
| F-LINT-01 | 330 PHPCS errors in base + addons (excluding pro) | various | n/a | OPEN |
| F-LINT-02 | Pro tree excluded from PHPCS — unknown error count | `phpcs.xml.dist:24` | n/a | OPEN |

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
