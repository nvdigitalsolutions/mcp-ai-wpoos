# Phase 6 — Remediation Roadmap

> Each item is sized for a single focused PR following the project's `feat(scope):` / `fix(scope):` commit convention and the GSD × BMAD Phase 5 development flow. Suggested CODEOWNERS reviewer follows the existing [`CODEOWNERS`](../../../CODEOWNERS) mapping.
>
> **Status reverified:** 2026-04-27 (v1.1.10). Per-finding status is authoritative in [`findings-register.md`](./findings-register.md).

## Status legend

✅ **Done** — landed and verified · 🟡 **Partial** — primary work landed, residual follow-up tracked · 🟠 **Open** — not yet remediated · ⏭️ **Accepted** — formally accepted as residual risk

## Item ID prefixes

- **R-S-XX** — Security fix (closes a finding)
- **R-A-XX** — Architectural change (introduces a shared service)
- **R-T-XX** — Tooling / CI change
- **R-D-XX** — Documentation change
- **R-Q-XX** — Quality / lint cleanup

---

## Wave 1 — Tooling foundation (block nothing; ship first)

These changes make the rest of the audit reproducible and prevent regression while remediation is in flight.

### R-T-01 — Re-enable PHPCS on `addons/pro/`  🟠 Open
- **Closes:** F-LINT-02
- **Files:** `phpcs.xml.dist`, `composer.json` (`lint:base` script)
- **Measured baseline (Wave 24):** 5,806 errors, 8,141 warnings across 745 files (3,758 PHP files in tree); 11,016 auto-fixable by `phpcbf`.
- **Plan:** Remove the `<exclude-pattern>*/addons/pro/*</exclude-pattern>` line; run `composer run format` (PHPCBF auto-fix) on the pro tree; commit the auto-fixes; document remaining errors with targeted `phpcs:ignore` annotations only where unavoidable.
- **Acceptance:** `composer run lint` passes against the full tree (or fails only on a documented allow-listed set). CI green.
- **Note:** Out of WP.org distribution scope — Pro is not distributed via the Plugin Directory. Does not block submission.

### R-T-02 — Add pro `composer audit` and `npm audit` to CI  🟠 Open
- **Closes:** I-09 gap
- **Files:** `.github/workflows/security.yml`
- **Plan:** Add `composer audit` and `npm audit --omit=dev` steps that `cd addons/pro` before running.
- **Acceptance:** CI fails when a new advisory appears in pro deps.

### R-T-03 — Add CodeQL `security-extended` for PHP + JS  🟠 Open
- **Closes:** F-CODEQL-01 (implicit)
- **Files:** new `.github/workflows/codeql.yml`
- **Plan:** Use `github/codeql-action/init@v3` with `languages: javascript, php` and `queries: security-extended`. Schedule weekly + on PR.
- **Acceptance:** Scheduled workflow runs; results visible in Security tab.

### R-T-04 — Add `wp plugin-check` to release build pipeline  ✅ Done
- **Closes:** WP.org checklist gap
- **Landed:** `b0e95fc` (PR #4737) — gating `plugin-check` job in `.github/workflows/release.yml`. Both `release` and `deploy-wporg` jobs now `needs: [build, plugin-check, ...]`, so the SVN deploy cannot run unless plugin-check is green.

### R-T-05 — Security regression workflow  🟠 Open
- **Closes:** F-AUTHZ-01 future regressions
- **Files:** new `.github/workflows/security-regression.yml`
- **Plan:** Per [`test-coverage-gaps.md`](./test-coverage-gaps.md) §4 — block new `__return_true` permission callbacks, new `sslverify => false`, new `eval(`/`shell_exec(` outside the explicit pro shell-tool allowlist, plus run the negative-permission and nonce-failure parametric test files.
- **Acceptance:** Workflow blocks at least one synthetic regression PR introducing each forbidden pattern.

### R-Q-01 — `composer run format` on base tree  ✅ Done
- **Closes:** F-LINT-01 (full closure, not partial — Wave 24 hand-fixed the residuals)
- **Result:** WP.org-shipped tree reports 0 errors / 0 warnings on 796 files.

### R-Q-02 — `npm audit fix` on root and pro  🟡 Partial / ⏭️ Accepted
- **Closes:** F-NPM-01 (Done — `path-to-regexp` override + `npm audit fix`); F-NPM-02 (Accepted — `exceljs → uuid` chain not exploitable, fix would be breaking)

---

## Wave 2 — High-severity remediation (one PR each)

### R-S-01 — Move webhook signature verification into `permission_callback`  🟡 Partial
- **Closes:** F-AUTHZ-01
- **Status:** Wave 11 landed permission-callback verification for the 4 routes where it was correct (Telegram login, agent-card ×2, Google Chat legacy fallback). Twitter CRC GET, WhatsApp verify GET ×2, Messenger verify GET, and OPTIONS preflight are legitimately public per their respective webhook protocols and remain `__return_true` with documented justification. 8 new PHPUnit cases in `tests/test-webhook-permission-callbacks.php`.

### R-S-02 — Migrate pro shell tools to `proc_open` + opt-in constant + capability gate  ✅ Done
- **Closes:** F-EXEC-01
- **Status:** Wave 12. All 11 pro shell-tool classes now gated behind `WP_MCP_AI_ALLOW_SHELL_TOOLS` (default `false`) + `current_user_can('manage_options')`. All `exec()`/`shell_exec()` calls migrated to `proc_open` via the new `wp_mcp_ai_run_process()` (array-form) and `wp_mcp_ai_run_shell()` helpers in `includes/bootstrap/helpers.php`.

### R-S-03 — Convert graphify SQL to `$wpdb->prepare()` with `%i`  ✅ Done
- **Closes:** F-SQL-01 (graphify) + F-SQL-02 (base `model-catalog-migration.php:209`)
- **Status:** Done. Remaining graphify interpolated queries with `phpcs:ignore` are tracked under R-A-01 follow-up.

### R-S-04 — HIPAA posture for healthcare + DICOM addons  ✅ Done
- **Closes:** F-PRIV-03, F-UPLOAD-01
- **Status:** Wave 23. All 5 recommendation items shipped: PHI never forwarded to AI providers; multisite guard requires `wp_mcp_ai_phi_acknowledged`; `WP_MCP_AI_Pro_Privacy` exporter + eraser cover all 6 health-wellness CPTs and `mcp_ai_imaging_study`; `docs/HIPAA_POSTURE.md` documents the data flow; `wp_mcp_ai_health_cpt_read_audit()` logs every health CPT read.

### R-S-05 — Algorave live-coding sandbox  🟡 Partial
- **Closes:** F-AI-01
- **Status:** Wave 13. Two layered defences landed: shortcode now refuses to render below `edit_posts`; `new Function('Tone', code)` gated behind `WP_MCP_AI_ALLOW_TONEJS_EVAL` (default `false`). Strudel engine remains the safe default. Sandboxed iframe + strict CSP is the remaining follow-up.

---

## Wave 3 — Cross-cutting architecture

### R-A-02 — Central HTTP wrapper with SSRF allowlist  ✅ Done
- **Closes:** F-SSRF-01, F-TLS-01 (in part)
- **Status:** Wave 18. New helper `wp_mcp_ai_is_safe_outbound_url()` in `includes/bootstrap/helpers.php` resolves all DNS A records, blocks loopback / private / link-local / multicast / APIPA-169.254.x.x. `is_private_ipv4_address()` extended to cover the AWS/GCP instance-metadata range. Filter `wp_mcp_ai_http_allowed_host` exposed for operator overrides. Migrated tool sites: `scrape-product`, `responsive-image-validator`. The other 507 `wp_remote_*` callers were audited and confirmed to use hardcoded provider endpoints (no user-supplied URL reaches them).

### R-A-03 — Central upload-validator  🟠 Open
- **Closes:** F-UPLOAD-01 (already addressed by R-S-04 / Wave 23 for DICOM), F-UPLOAD-02 (Closed — false positive)
- **Status:** Both component findings closed. Open status retained because the originally-planned shared `WP_MCP_AI_Upload_Validator` service was not implemented; the existing per-tool validation was deemed sufficient.

### R-A-04 — Privacy registry / Privacy-API auto-wiring  ✅ Done
- **Closes:** F-PRIV-01
- **Status:** Wave 23. `WP_MCP_AI_Pro_Privacy` service registers WP Privacy API exporters and hard-delete erasers for all six health-wellness CPTs and `mcp_ai_imaging_study`.

### R-A-05 — Path validator  ✅ Done
- **Closes:** F-FS-02
- **Status:** Wave 17. New helper `wp_mcp_ai_validate_path( $path, $allowed_root )` added; all path-taking tools audited and migrated where they did not already implement equivalent `realpath()` + bounds checking.

### R-A-06 — Tool-result truncator + delimiter neutraliser  ✅ Done
- **Closes:** F-AI-02
- **Status:** Wave 10. `WP_MCP_AI_REST_Validator::truncate_tool_result_content()` + `neutralise_tool_result_delimiters()` cap tool results at 64 KB and strip ChatML / Llama / XML tool-call markers. 11 new PHPUnit cases.

---

## Wave 4 — Medium / Low items

### R-S-06 — Remove `sslverify => false` (4 sites)  ✅ Done
- **Closes:** F-TLS-01

### R-S-07 — Audit the 6 `wp_ajax_nopriv_*` handlers  ✅ Done
- **Closes:** F-AUTHZ-02
- **Status:** Wave 16. Per-handler audit complete; new helper `wp_mcp_ai_check_ajax_rate_limit()` added to `includes/bootstrap/helpers.php`; rate limits applied (10–20 req/min/IP); dead `wp_ajax_nopriv_` registration removed from `wp_mcp_ai_execute_quick_action`.

### R-S-08 — Multisite super-admin gates  ✅ Done
- **Closes:** F-AUTHZ-03
- **Status:** Helpers `wp_mcp_ai_user_can_manage_fleet()` and `wp_mcp_ai_fleet_capability()` added; federation/asset-inventory/dependency-scan code migrated.

### R-S-09 — Embedded guest-token origin binding  ✅ Done
- **Closes:** F-AUTHZ-04
- **Status:** Token now persists issuance origin and validates request `Origin` (or `Referer` host fallback) against bound host or `wp_mcp_ai_guest_token_allowed_origins` filter. 9 new PHPUnit cases.

### R-S-10 — Canvas SVG sanitisation  ✅ Done
- **Closes:** F-XSS-02
- **Status:** Quick-Actions handler now refuses SVG upload below `upload_files` and routes accepted SVGs through `sanitize_svg_contents()` (DOMDocument + LIBXML_NONET). 8 new PHPUnit cases.

### R-S-11 — Graphify SVG label escaping  ✅ Done
- **Closes:** F-SVG-XSS-01
- **Status:** Standalone HTML export uses `createElement` + `textContent`; admin and frontend JS validate `n.url` against `^https?://`.

### R-S-12 — DICOM upload validator  ✅ Done
- **Closes:** F-UPLOAD-01 (folded into R-S-04 / Wave 23)

### R-S-13 — Document-generation temp-file centralisation  ✅ Done
- **Closes:** F-FS-01
- **Status:** Wave 22. New `wp_mcp_ai_get_temp_dir()` and `wp_mcp_ai_tempnam()` helpers; hourly cleanup cron event registered; all 10 document-generation tools migrated.

### R-S-14 — MCP server allowlist  ✅ Done
- **Closes:** F-AI-03
- **Status:** `WP_MCP_AI_MCP_App_Registry::is_url_allowed()` validates every `server_url` before save / discovery / test. Allowlist via `WP_MCP_AI_MCP_APP_ALLOWED_HOSTS` constant or `wp_mcp_ai_mcp_app_allowed_hosts` filter. 12 new PHPUnit cases.

---

## Wave 5 — Hygiene / documentation

### R-D-01 — `readme.txt` external-services disclosure  ✅ Done
- **Closes:** F-PRIV-02
- **Status:** Wave 19. `== External Services ==` section in `readme.txt` lists every provider with Purpose / Data Sent / When / ToS / Privacy URL.

### R-D-02 — `docs/HIPAA_POSTURE.md`  ✅ Done
- Covered by R-S-04.

### R-D-03 — Update CHANGELOG.md, `Tested up to`, `Stable tag` per release (process)  ✅ Done
- **Closes:** F-CMP-03
- **Status:** Wave 21. All four version declarations now match (plugin headers, `readme.txt`, `WP_MCP_AI_VERSION` constant, both `package.json` files).

### R-D-04 — Replace stale "519 tools" string with live count  ✅ Done
- **Closes:** F-DOC-01

### R-Q-03 — Add `ABSPATH` guard to 4 missing files  ✅ Closed (false positive)
- **Closes:** F-CMP-02
- **Status:** Re-verified — `addons/embedded/uninstall.php` correctly uses `WP_UNINSTALL_PLUGIN`; vault classes use `WPINC` (functionally equivalent); `workflow-builder.asset.php` is a build artifact that must `return array(...)`. No fix needed.

### R-Q-04 — Audit all 120 `innerHTML` JS sites for non-icon usage  🟠 Open
- **Closes:** F-XSS-01 (frontend portion)
- **Note:** F-XSS-01 PHP portion FIXED in Wave 20 (all 22 shortcodes audited).

### R-Q-05 — Standardise nonce action names to `wp_mcp_ai_*`  🟡 Partial
- **Closes:** F-CMP-04
- **Status:** Wave 15 standardised base-plugin action strings; pro-addon legacy strings remain on a follow-up sweep.

### R-Q-06 — Ship sources for all `.min.js` files (or source maps)  ✅ Done
- **Closes:** F-CMP-05
- **Status:** Wave 21. Every plugin-authored `.min.js` has a sibling `.min.js.map`; the only exception is third-party `vendor/chart.min.js` (Chart.js v4.5.1 MIT, source documented in `assets/js/vendor/README.md`).

### R-A-01 — Per-shortcode XSS verification pass (rolling)  ✅ Done
- **Closes:** F-XSS-01 (PHP portion)
- **Status:** Wave 20. Exhaustive audit of all 22 registered shortcodes; no unescaped dynamic output found.

---

## Suggested execution order

1. **Wave 1 (5 tooling PRs)** — establish visibility and regression gates first.
2. **Wave 2 (5 High PRs)** — sequential because each touches a different subsystem.
3. **Wave 3 (5 architecture PRs)** — once visibility is in place, ship the shared services.
4. **Wave 4 + 5 (concurrent, smaller PRs)** — many are mechanical and parallelisable.

Total: **~30 focused PRs** to clear the audit backlog. None individually large; each can be handled by a single GSD × BMAD Developer sprint cycle.

## Status summary (2026-04-27)

| Wave | Items | Done | Partial | Open / Accepted |
|---|---:|---:|---:|---:|
| Wave 1 — Tooling foundation | 7 | 2 | 1 | 4 (R-T-01, R-T-02, R-T-03, R-T-05) |
| Wave 2 — High-severity | 5 | 3 | 2 | 0 |
| Wave 3 — Architecture | 5 | 4 | 0 | 1 (R-A-03) |
| Wave 4 — Medium / Low | 9 | 9 | 0 | 0 |
| Wave 5 — Hygiene / docs | 8 | 6 | 1 | 1 (R-Q-04) |
| **Total** | **34** | **24** | **4** | **6** |

The 6 Open items are concentrated in non-distribution surfaces (R-T-01 / Pro PHPCS) or are tooling/process improvements that do not block the WP.org submission.
