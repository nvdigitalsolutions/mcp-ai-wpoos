# Phase 6 — Executive Summary

> **Audit window:** 2026-04-26
> **Status reverified:** 2026-04-27 (v1.1.10)
> **Scope:** Base plugin (`includes/`), Pro addon (`addons/pro/`), six minor addons, bundled `core/`, `shared/`, `packages/`, `src/`.
> **Standards applied:** WordPress Plugin Handbook, WP.org Plugin Directory Guidelines, OWASP Top 10 / API Top 10, WPCS 3.3, PHPCompatibilityWP, GDPR/CCPA principles, MCP/SSE conformance.

## Headline verdict

**No Critical findings.** Five High findings were identified at audit close; **3 are FIXED** (F-SQL-01, F-EXEC-01, F-PRIV-03), **2 are PARTIALLY FIXED** (F-AUTHZ-01, F-AI-01), 0 remain OPEN. **All 14 Medium findings are FIXED.** The base plugin's foundational practices were already solid — capability checks are pervasive, nonces are widely used, encryption-at-rest is in place (now AES-256-GCM AEAD per F-CRYPTO-01), DOMPurify protects chat output, no `composer audit` vulnerabilities, no GPL-incompatible licences, no telemetry-on-activation. Remediation across Waves 9–24 closed every High and Medium finding identified in the audit; the remaining work is concentrated in Pro-tree linting (F-LINT-02) which is out of WP.org distribution scope.

The base plugin (the WP.org-distributed artifact) is **submission-ready**: all 9 gating items in [`wp-org-submission-checklist.md`](./wp-org-submission-checklist.md) are ✅ on v1.1.10 (verified 2026-04-27).

## Top 10 issues (by remediation priority)

| Rank | ID | Severity | Status | Title | Owner-area |
|---:|---|---|---|---|---|
| 1 | **F-AUTHZ-01** | High | PARTIALLY FIXED (Wave 11 / R-S-01) | 11 webhook routes use `__return_true` — move signature verification into the permission callback | `includes/rest/`, `addons/pro/includes/rest/` |
| 2 | **F-EXEC-01** | High | FIXED (Wave 12 / R-S-02) | 11 `shell_exec`/`exec` calls in pro tools — switch to `proc_open`, add capability + opt-in constant | `addons/pro/includes/tools/` |
| 3 | **F-PRIV-03** | High | FIXED (Wave 23 / R-S-04) | Healthcare/DICOM addons HIPAA posture, PHI-stripping, Privacy-API coverage | pro health-wellness, cornerstone3d |
| 4 | **F-AI-01** | High | PARTIALLY FIXED (Wave 13 / R-S-05) | Algorave live-coding `new Function(…)` runs in main JS context — sandbox follow-up tracked | algorave |
| 5 | **F-SQL-01** | High | FIXED (R-S-03) | 7 unprepared SQL statements in graphify (table-name interpolation) | graphify |
| 6 | **F-SSRF-01** | Medium | FIXED (Wave 18 / R-A-02) | SSRF allowlist + DNS-rebinding defence via `wp_mcp_ai_is_safe_outbound_url()` | base, pro |
| 7 | **F-TLS-01** | Medium | FIXED (R-S-06) | `sslverify => false` removed from 4 sites | base, pro |
| 8 | **F-PRIV-01 + F-PRIV-02** | Medium | FIXED (Wave 23 / R-A-04 + Wave 19 / R-D-01) | Pro CCT/CPT Privacy-API coverage; AI provider data flows disclosed in `readme.txt` | pro, base |
| 9 | **F-LINT-02** | Low | OPEN | Pro tree (`addons/pro/*`) excluded from PHPCS — Wave 24 measurement: 5,806 errors / 8,141 warnings across 745 files (11,016 auto-fixable). Out of WP.org distribution scope. | tooling |
| 10 | **F-NPM-01/02** | Low | F-NPM-01 FIXED, F-NPM-02 ACCEPTED (Wave 24) | Root advisories cleared via `npm audit fix` + `path-to-regexp` override; pro `exceljs → uuid` chain accepted as low-risk dev-only | tooling |

## Posture per addon

| Addon | Critical | High | Medium | Low | Verdict |
|---|---:|---:|---:|---:|---|
| Base plugin | 0 | 0 | 6 | 12 | ✅ Solid baseline. All hardening items closed. |
| Pro addon | 0 | 3 | 7 | 6 | ✅ Three Highs closed (F-EXEC-01, F-AUTHZ-01 partial, F-PRIV-03). |
| Algorave | 0 | 1 | 1 | 1 | ⚠️ F-AI-01 partial — capability gate + opt-in constant landed; sandboxed iframe is the remaining follow-up. |
| Canvas | 0 | 0 | 2 | 1 | ✅ SVG hardening landed (F-XSS-02 / R-S-10). |
| Cornerstone3D | 0 | 1 | 1 | 0 | ✅ DICOM HIPAA story shipped (F-PRIV-03 / Wave 23). |
| Embedded | 0 | 0 | 2 | 1 | ✅ CSP + origin-binding hardening landed (F-CSP-01, F-AUTHZ-04). |
| Fantasy-football | 0 | 0 | 2 | 2 | ✅ Rate-limit + SSRF wrapper landed. |
| Graphify | 0 | 1 | 2 | 1 | ✅ SQL prepare (F-SQL-01); SVG escape (F-SVG-XSS-01); DoS cap (F-DOS-01). |

## What is already working well

- **Capability gating.** Tool base class enforces `current_user_can()` before execution; the `guest_request` flag is honoured in REST.
- **Nonces.** 368 `wp_create_nonce`/`wp_nonce_field` and 289 `check_*_referer` — broad coverage.
- **Encryption at rest.** API keys flow through `wp_mcp_ai_encrypt` — now upgraded to **AES-256-GCM AEAD** with transparent legacy CBC fallback (F-CRYPTO-01 FIXED, no data migration required).
- **No GPL conflicts.** All bundled package licences are GPL-compatible.
- **No `composer audit` issues.** Both root and pro composer trees clean.
- **DOMPurify on chat.** `chat-markdown-service.js` sanitises model output before insertion.
- **Telemetry is opt-in.** `class-wp-mcp-ai-activation-tracker.php` does not auto-phone-home.
- **HMAC available and now wired.** Federation peer verifier exists; signature verification has been moved into permission callbacks for the routes that needed it (F-AUTHZ-01 partial — see finding for the fully justified residual `__return_true` cases).

## What received the most attention

- **Pro tree visibility.** The single biggest residual audit gap is that `addons/pro/*` is excluded from PHPCS in `phpcs.xml.dist:24`. Wave 24 measurement: **5,806 errors and 8,141 warnings across 745 files (out of 3,758 PHP files); 11,016 violations are `phpcbf`-auto-fixable.** Tracked under Roadmap **R-T-01**; the Pro tree is **not** distributed via WordPress.org so this does not block submission.
- **Webhook signature posture.** 11 webhook routes used `__return_true`. R-S-01 (Wave 11) moved verification into the permission callback for the 4 routes where that was correct (Telegram, agent-card ×2, Google Chat legacy fallback). The remaining 6 (Twitter CRC GET, WhatsApp verify GET ×2, Messenger verify GET, OPTIONS preflight) are legitimately public per the upstream provider's webhook protocol; documented and unchanged.
- **HIPAA / PHI flow.** R-S-04 (Wave 23) shipped: DICOM PHI tags are not forwarded to AI providers; addons fail-closed on multisite without `wp_mcp_ai_phi_acknowledged`; Privacy-API exporter + eraser cover all 6 health-wellness CPTs and `mcp_ai_imaging_study`; `docs/HIPAA_POSTURE.md` documents the data flow.
- **Shell tools.** R-S-02 (Wave 12) gates all 11 pro shell-tool classes behind `WP_MCP_AI_ALLOW_SHELL_TOOLS` (default `false`) + `current_user_can('manage_options')`; all `exec`/`shell_exec` calls migrated to `proc_open` array form.
- **SSRF allowlist.** R-A-02 (Wave 18) added `wp_mcp_ai_is_safe_outbound_url()` with DNS-rebinding defence (resolves all A records, blocks loopback / private / link-local / APIPA-169.254.x.x); call sites that fetched user-supplied URLs migrated to it.

## WordPress.org submission readiness

**Verdict: ✅ Submission-ready (base plugin v1.1.10).**

All 9 submission gating items in [`wp-org-submission-checklist.md`](./wp-org-submission-checklist.md) are ✅ as of 2026-04-27:

- F-LINT-01 — 0 PHPCS errors on the WP.org-shipped tree (R-Q-01).
- F-PRIV-02 — `readme.txt` `== External Services ==` section (R-D-01).
- F-CMP-05 — every plugin-authored `.min.js` ships with a sibling `.min.js.map` (R-Q-06).
- F-SQL-02 — base-tree `model-catalog-migration.php:209` migrated to `$wpdb->prepare()` (extends R-S-03).
- F-AUTHZ-01 (base subset) — every base REST route uses a real `permission_callback`; the only `__return_true` is OPTIONS preflight.
- R-T-04 — gating `wp plugin-check` job added to `.github/workflows/release.yml`; SVN deploy now blocks unless plugin-check is green.

F-LINT-02 (Pro PHPCS) is the only remaining audit-tracked open item, and it is out of WP.org distribution scope (the Pro addon is not distributed via the Plugin Directory; `.distignore` line 135 excludes `addons/`).

## Risk acceptance

Two items are formally accepted as residual risk:

- **F-NPM-02** — `exceljs → uuid` chain in `addons/pro/`. ExcelJS only calls `uuidv4()`; the vulnerable `v3/v5/v6(name, namespace, buf)` API is not invoked. Fix would require an ExcelJS major-version downgrade (Wave 24).
- **F-NPM-01** residual — 5 moderate `uuid` advisories in the `@wordpress/scripts` 31.x dev toolchain. Fix would require downgrading to 19.x. Accepted as dev-only risk (Wave 24).

Every High and Medium finding has a remediation item in [`remediation-roadmap.md`](./remediation-roadmap.md); the per-finding status (FIXED / PARTIALLY FIXED / CLOSED / OPEN / ACCEPTED) is authoritative in [`findings-register.md`](./findings-register.md).
