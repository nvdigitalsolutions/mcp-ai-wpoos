# Phase 6 — Executive Summary

> **Audit window:** 2026-04-26
> **Scope:** Base plugin (`includes/`), Pro addon (`addons/pro/`), six minor addons, bundled `core/`, `shared/`, `packages/`, `src/`.
> **Standards applied:** WordPress Plugin Handbook, WP.org Plugin Directory Guidelines, OWASP Top 10 / API Top 10, WPCS 3.3, PHPCompatibilityWP, GDPR/CCPA principles, MCP/SSE conformance.

## Headline verdict

**No Critical findings. Five High findings.** The base plugin's foundational practices are solid — capability checks are pervasive, nonces are widely used, encryption-at-rest is in place, DOMPurify protects chat output, no `composer audit` vulnerabilities, no GPL-incompatible licences, no obvious telemetry-on-activation. The audit's High-severity items are concentrated in **non-default code paths** (pro shell-exec tools, healthcare addon, algorave live-coding) plus **two cross-cutting hardening items** (webhook signature placement, central SSRF wrapper).

The plugin is **not yet ready** for an unconditional WP.org submission update — see [`wp-org-submission-checklist.md`](./wp-org-submission-checklist.md) — but the five Highs are all tractable; none requires architectural rework. We estimate **5–8 focused PRs** to clear the High findings.

## Top 10 issues (by remediation priority)

| Rank | ID | Severity | Title | Owner-area |
|---:|---|---|---|---|
| 1 | **F-AUTHZ-01** | High | 11 webhook routes use `__return_true` — move signature verification into the permission callback | `includes/rest/`, `addons/pro/includes/rest/` |
| 2 | **F-EXEC-01** | High | 11 `shell_exec`/`exec` calls in pro tools — switch to `proc_open` array form, add capability + opt-in constant | `addons/pro/includes/tools/` |
| 3 | **F-PRIV-03** | High | Healthcare/DICOM addons have no documented HIPAA posture, PHI-stripping, or Privacy-API coverage | pro health-wellness, cornerstone3d |
| 4 | **F-AI-01** | High | Algorave live-coding `new Function(…)` runs in main JS context — must move to sandboxed iframe + CSP | algorave |
| 5 | **F-SQL-01** | High | 7 unprepared SQL statements in graphify (table-name interpolation) | graphify |
| 6 | **F-SSRF-01** | Medium | No SSRF allowlist on tool-driven outbound HTTP — central wrapper needed | base, pro |
| 7 | **F-TLS-01** | Medium | `sslverify => false` in 4 tool classes | base, pro |
| 8 | **F-PRIV-01 + F-PRIV-02** | Medium | Pro CCT/CPT data not covered by Privacy API; AI provider data flows not disclosed in `readme.txt` | pro, base |
| 9 | **F-LINT-02** | Low | Pro tree (`addons/pro/*`) excluded from PHPCS — single biggest visibility gap | tooling |
| 10 | **F-NPM-01/02** | Low | 13 moderate npm advisories (root + pro), all auto-fixable via `npm audit fix` | tooling |

## Posture per addon

| Addon | Critical | High | Medium | Low | Verdict |
|---|---:|---:|---:|---:|---|
| Base plugin | 0 | 0 | 6 | 12 | ✅ Solid baseline. Hardening items only. |
| Pro addon | 0 | 3 | 7 | 6 | ⚠️ Three Highs (F-EXEC-01, F-AUTHZ-01, F-PRIV-03). Highest-effort remediation. |
| Algorave | 0 | 1 | 1 | 1 | ⚠️ Live-coding sandbox required (F-AI-01). |
| Canvas | 0 | 0 | 2 | 1 | OK; SVG hardening needed. |
| Cornerstone3D | 0 | 1 | 1 | 0 | ⚠️ DICOM HIPAA story needed (F-PRIV-03). |
| Embedded | 0 | 0 | 2 | 1 | OK; CSP/origin-binding hardening needed. |
| Fantasy-football | 0 | 0 | 2 | 2 | OK; rate-limit + SSRF wrapper. |
| Graphify | 0 | 1 | 2 | 1 | ⚠️ SQL prepare (F-SQL-01); SVG escape; DoS cap. |

## What is already working well

- **Capability gating.** Tool base class enforces `current_user_can()` before execution; the `guest_request` flag is honoured in REST.
- **Nonces.** 368 `wp_create_nonce`/`wp_nonce_field` and 289 `check_*_referer` — broad coverage.
- **Encryption at rest.** API keys flow through `wp_mcp_ai_encrypt` (subject to F-CRYPTO-01 verification).
- **No GPL conflicts.** All bundled package licences are GPL-compatible.
- **No `composer audit` issues.** Both root and pro composer trees clean.
- **DOMPurify on chat.** `chat-markdown-service.js` sanitises model output before insertion.
- **Telemetry is opt-in.** `class-wp-mcp-ai-activation-tracker.php` does not auto-phone-home.
- **HMAC available.** Federation peer verifier exists (`includes/class-wp-mcp-ai-federation-peer-verifier.php`); needs wiring into REST permission callbacks (F-AUTHZ-01).

## What needs the most attention

- **Pro tree visibility.** The single biggest audit gap is that `addons/pro/*` is excluded from PHPCS in `phpcs.xml.dist:24`. Re-enabling will surface a backlog comparable to the base tree. Roadmap **R-T-01**.
- **Webhook signature posture.** 11 webhook routes use `__return_true` — verification logic exists in each callback but is not called *before* the route body parses, so a malformed payload can still trigger expensive code paths. Roadmap **R-S-01**.
- **HIPAA / PHI flow.** The healthcare and DICOM addons handle clinical data but do not document the data flow, do not strip PHI before AI provider calls, and are not covered by the WP Privacy API. Roadmap **R-S-04**.
- **Shell tools.** 11 pro tool classes invoke `exec`/`shell_exec`. Each should be gated behind both a capability check and an opt-in constant, and each should migrate to `proc_open` array form. Roadmap **R-S-02**.
- **SSRF allowlist.** 507 `wp_remote_*` calls; no central guard against private-IP / link-local destinations. Roadmap **R-A-02**.

## WordPress.org submission readiness

**Verdict: Not yet.** The base plugin must clear:

- F-LINT-02 (pro tree is shipped via separate ZIP, but the build pipeline must be lint-clean)
- F-PRIV-02 (`readme.txt` external-services disclosure)
- F-CMP-05 (minified-only files without source)
- F-LINT-01 (330 PHPCS errors — auto-fix 168, hand-fix 162)

…and run `wp plugin-check` against the built ZIP (R-T-04). Full checklist in [`wp-org-submission-checklist.md`](./wp-org-submission-checklist.md).

## Risk acceptance

No findings have been formally accepted as residual risk. Every High finding has a remediation item in [`remediation-roadmap.md`](./remediation-roadmap.md) sized for a focused, single-issue PR.
