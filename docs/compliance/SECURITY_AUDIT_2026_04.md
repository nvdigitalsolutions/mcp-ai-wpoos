# Security & Compliance Audit Summary — April 2026

> **Plugin:** NV Digital Open Operator System (NV oOS)
> **Plugin version:** 1.1.10
> **Audit window:** 2026-04-26
> **Document published:** 2026-04-27
> **Document classification:** Internal — distributable to maintainers and security reviewers
> **Next review:** Quarterly (target: 2026-07)

This document is the **published summary** of the full April 2026 security and compliance code review of NV oOS. The complete deliverable set lives under [`docs/audit/2026-04/`](../audit/2026-04/) (9 files); this summary consolidates the headline verdict, top findings, and submission-readiness status into a single reference for maintainers and operators. It does **not** modify the underlying audit deliverables.

---

## 1. Scope

| Layer | Files (PHP) | Lint enforced | Tests | Notes |
|---|---:|---|---|---|
| Base plugin (`mcp-ai-wpoos.php`, `mcp-ai-wpoos-base.php`, `includes/`) | ~1,460 | ✅ WPCS via `composer run lint:base` | ✅ | WordPress.org distribution |
| Pro addon (`addons/pro/`) | 1,141 | ❌ excluded by `phpcs.xml.dist:24` | ✅ via `phpunit.xml.dist` | 76 MB vendor tree, 584 tool classes |
| `addons/algorave/` | 20 | ✅ | partial | WebAudio / live-coding surface |
| `addons/canvas/` | 2 | ✅ | none | Canvas/Fabric editor surface |
| `addons/cornerstone3d/` | 2 | ✅ | none | DICOM / medical imaging |
| `addons/embedded/` | 36 | ✅ | partial | iframe / embeddable surface |
| `addons/fantasy-football/` | 25 | ✅ | partial | Yahoo / ESPN OAuth |
| `addons/graphify/` | 22 | ✅ | partial | Graph DB and rendering |
| **Total (excl. vendor)** | **2,983** | — | — | — |

Tool classes counted at audit time: **231 base + 584 pro = 815 total** (the live `WP_MCP_AI_Tool_Registry::get_tools()` count is authoritative and may differ slightly).

## 2. Standards applied

- WordPress Plugin Handbook security ([sanitize / validate / escape](https://developer.wordpress.org/plugins/security/securing-input/), [nonces](https://developer.wordpress.org/plugins/security/nonces/), [capabilities](https://developer.wordpress.org/plugins/security/checking-user-capabilities/))
- [WordPress.org Plugin Directory Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) (all 18 rules)
- WordPress Coding Standards (WPCS 3.3) + PHPCompatibilityWP
- [OWASP Top 10 (2021)](https://owasp.org/Top10/) and [OWASP API Security Top 10 (2023)](https://owasp.org/API-Security/editions/2023/en/0x00-toc/)
- GDPR / CCPA data-handling principles for AI provider integrations
- MCP / Server-Sent Events conformance for streaming security boundaries
- Project-specific rules: [`.context/security-checklist.md`](../../.context/security-checklist.md), [`CLAUDE.md`](../../CLAUDE.md), [`CONTRIBUTING.md`](../../CONTRIBUTING.md)

## 3. Headline verdict

**No Critical findings. 5 High findings, 14 Medium, 21 Low, 10 Informational — 50 total.**

The base plugin's foundational practices are solid:

- **Capability gating** is pervasive — the tool base class enforces `current_user_can()` before execution; `guest_request` is honored in REST.
- **Nonces** — 368 `wp_create_nonce` / `wp_nonce_field` calls and 289 `check_*_referer` calls give broad coverage.
- **Encryption at rest** — API keys flow through `wp_mcp_ai_encrypt`.
- **No GPL conflicts** in any bundled package.
- **No `composer audit` issues** in either root or pro composer trees.
- **DOMPurify** sanitizes model output before DOM insertion in `chat-markdown-service.js`.
- **Telemetry is opt-in** — `class-wp-mcp-ai-activation-tracker.php` does not auto-phone-home.
- **HMAC verifier available** — `class-wp-mcp-ai-federation-peer-verifier.php` exists; needs wiring into REST permission callbacks (F-AUTHZ-01).

The audit's High-severity items are concentrated in **non-default code paths** (Pro shell-exec tools, healthcare addon, algorave live-coding) plus **two cross-cutting hardening items** (webhook signature placement, central SSRF wrapper). At the time the audit was concluded, **3 of the 5 Highs were already FIXED** (F-SQL-01, F-EXEC-01, F-PRIV-03) and **2 are PARTIALLY FIXED** (F-AUTHZ-01, F-AI-01) — see the [findings register](../audit/2026-04/findings-register.md) for the authoritative status.

## 4. Top 10 findings (by remediation priority)

| Rank | ID | Severity | Status | Title | Owner area |
|---:|---|---|---|---|---|
| 1 | [`F-AUTHZ-01`](../audit/2026-04/findings-register.md#f-authz-01--webhook-routes-use-__return_true-permission-callback-signature-must-be-verified-inside-route) | High | Partially fixed | 11 webhook routes use `__return_true` — move signature verification into the permission callback | `includes/rest/`, `addons/pro/includes/rest/` |
| 2 | [`F-EXEC-01`](../audit/2026-04/findings-register.md) | High | Fixed | 11 `shell_exec` / `exec` calls in pro tools — switch to `proc_open` array form, gate behind capability + opt-in constant | `addons/pro/includes/tools/` |
| 3 | [`F-PRIV-03`](../audit/2026-04/findings-register.md) | High | Fixed | Healthcare/DICOM addons need a documented HIPAA posture, PHI stripping, Privacy-API coverage | `addons/pro` health-wellness, `addons/cornerstone3d` |
| 4 | [`F-AI-01`](../audit/2026-04/findings-register.md) | High | Partially fixed | Algorave live-coding `new Function(…)` runs in main JS context — sandbox + CSP required | `addons/algorave` |
| 5 | [`F-SQL-01`](../audit/2026-04/findings-register.md) | High | Fixed | 7 unprepared SQL statements in graphify (table-name interpolation) | `addons/graphify` |
| 6 | [`F-SSRF-01`](../audit/2026-04/findings-register.md) | Medium | Fixed | No SSRF allowlist on tool-driven outbound HTTP — central wrapper added | base, pro |
| 7 | [`F-TLS-01`](../audit/2026-04/findings-register.md) | Medium | Fixed | `sslverify => false` in tool classes | base, pro |
| 8 | [`F-PRIV-01` / `F-PRIV-02`](../audit/2026-04/findings-register.md) | Medium | Fixed | Pro CCT/CPT not covered by Privacy API; AI-provider data flows undisclosed in `readme.txt` | base, pro |
| 9 | [`F-LINT-02`](../audit/2026-04/findings-register.md) | Low | Open | Pro tree (`addons/pro/*`) excluded from PHPCS — Wave 24 measurement: 5,806 errors / 8,141 warnings across 745 files (11,016 auto-fixable) | tooling |
| 10 | [`F-NPM-01` / `F-NPM-02`](../audit/2026-04/findings-register.md) | Low | Open | 13 moderate npm advisories (root + pro), all auto-fixable via `npm audit fix` | tooling |

Severity counts taken from the executive summary; granular per-finding status (`OPEN` / `TRIAGED` / `FIXED` / `PARTIALLY FIXED`) is tracked in [`findings-register.md`](../audit/2026-04/findings-register.md).

## 5. Posture per addon

| Addon | Critical | High | Medium | Low | Verdict |
|---|---:|---:|---:|---:|---|
| Base plugin | 0 | 0 | 6 | 12 | ✅ Solid baseline. Hardening items only. |
| Pro addon | 0 | 3 | 7 | 6 | ⚠️ Three Highs (now Fixed / Partially Fixed). Highest-effort remediation. |
| Algorave | 0 | 1 | 1 | 1 | ⚠️ Live-coding sandbox required (F-AI-01). |
| Canvas | 0 | 0 | 2 | 1 | OK; SVG hardening required. |
| Cornerstone3D | 0 | 1 | 1 | 0 | ⚠️ DICOM HIPAA story addressed under F-PRIV-03. |
| Embedded | 0 | 0 | 2 | 1 | OK; CSP / origin-binding hardening required. |
| Fantasy-football | 0 | 0 | 2 | 2 | OK; rate-limit + SSRF wrapper. |
| Graphify | 0 | 1 | 2 | 1 | ⚠️ SQL prepare (F-SQL-01); SVG escape; DoS cap. |

## 6. WordPress.org submission readiness

**Verdict:** Not yet ready for an unconditional WP.org submission update. The base plugin has issues to clear before re-submission, tracked in detail in [`wp-org-submission-checklist.md`](../audit/2026-04/wp-org-submission-checklist.md):

| Item | Status |
|---|---|
| **F-LINT-02** — Pro tree excluded from PHPCS (build pipeline must be lint-clean) | Open |
| **F-PRIV-02** — `readme.txt` external-services disclosure | Fixed |
| **F-CMP-05** — minified-only files without source / source maps | Open (R-Q-06) |
| **F-LINT-01** — 330 PHPCS errors (auto-fix 168, hand-fix 162) | Open |
| Run `wp plugin-check` against the built ZIP | Pending (R-T-04) |

All 13 prior WP.org Plugin Directory Guideline items audited in [`WORDPRESS_ORG_COMPLIANCE_2026_04_15.md`](WORDPRESS_ORG_COMPLIANCE_2026_04_15.md) remain compliant on the v1.1.10 codebase.

## 7. What needs the most attention

- **Pro tree visibility.** The single biggest audit gap is that `addons/pro/*` is excluded from PHPCS in `phpcs.xml.dist:24`. Wave 24 measurement: **5,806 errors / 8,141 warnings across 745 files (out of 3,758 PHP files); 11,016 violations are `phpcbf`-auto-fixable.** Remediation in roadmap **R-T-01**.
- **Webhook signature posture.** 11 webhook routes use `__return_true`; verification logic exists in each callback but is not called *before* the route body parses, so a malformed payload can still trigger expensive code paths. Roadmap **R-S-01**. Status: partially fixed.
- **HIPAA / PHI flow.** The healthcare and DICOM addons handle clinical data; documented data flow, PHI stripping, and Privacy-API coverage now in place. Roadmap **R-S-04**. Status: fixed.
- **Shell tools.** 11 pro tool classes invoked `exec` / `shell_exec`. Migrated to `proc_open` array form, gated behind capability + opt-in constant. Roadmap **R-S-02**. Status: fixed.
- **SSRF allowlist.** 507 `wp_remote_*` calls; central wrapper now guards against private-IP / link-local destinations. Roadmap **R-A-02**. Status: fixed.

## 8. Test-coverage gaps

Summary of [`test-coverage-gaps.md`](../audit/2026-04/test-coverage-gaps.md) (Phase 5):

- ~365 `test-*.php` files across `tests/` (~210), `addons/pro/tests/` (~140), and minor addons.
- REST `permission_callback` tests cover most of the 190 routes but not every controller has a negative permission test.
- Three minor addons (`graphify`, `canvas`, `cornerstone3d`) have no PHPUnit suite.
- PHPUnit was **not** executed during the audit (requires `composer run test:install`); coverage figures above come from manual inspection of test file names.

## 9. Remediation status & effort

The five High findings were estimated at **5–8 focused PRs**. At publication of this summary, **3 Highs are FIXED** and **2 are PARTIALLY FIXED**; remediation has materially exceeded the original estimate. The detailed roadmap remains in [`remediation-roadmap.md`](../audit/2026-04/remediation-roadmap.md).

No findings have been formally accepted as residual risk.

## 10. Scope statistics & methodology

- **Lint commands referenced during the audit:** `composer run lint`, `composer run lint:base`, `composer run lint:compat`, `npm run lint:js`.
- **Test commands referenced:** `composer run test`, `composer run test:install`, `npm test`.
- **Security tooling referenced:** `composer audit`, `npm audit`, regression workflow R-T-05 (advisory) blocking new `__return_true` permission callbacks, new `'sslverify' => false`, and new `eval()` / raw `shell_exec` outside the documented allowlist.

## 11. Cross-reference index — full audit deliverables

All nine deliverables under [`docs/audit/2026-04/`](../audit/2026-04/):

1. [`README.md`](../audit/2026-04/README.md) — scope, standards, deliverable map.
2. [`inventory.md`](../audit/2026-04/inventory.md) — file, tool, license, and dependency inventory.
3. [`automated-scan-results.md`](../audit/2026-04/automated-scan-results.md) — PHPCS / PHPCompatibilityWP / `composer audit` / `npm audit` raw results.
4. [`manual-review-checklist.md`](../audit/2026-04/manual-review-checklist.md) — manual review against WP, OWASP, GDPR / CCPA, MCP / SSE checklists.
5. [`findings-register.md`](../audit/2026-04/findings-register.md) — full 50-finding register with CWE mapping and per-finding status.
6. [`addon-deep-dives.md`](../audit/2026-04/addon-deep-dives.md) — per-addon analysis (algorave, canvas, cornerstone3d, embedded, fantasy-football, graphify, pro).
7. [`test-coverage-gaps.md`](../audit/2026-04/test-coverage-gaps.md) — Phase 5 coverage report.
8. [`remediation-roadmap.md`](../audit/2026-04/remediation-roadmap.md) — sequenced remediation PRs (R-S-*, R-A-*, R-T-*, R-D-*, R-Q-*).
9. [`wp-org-submission-checklist.md`](../audit/2026-04/wp-org-submission-checklist.md) — WP.org Plugin Directory Guideline-by-guideline status.
10. [`executive-summary.md`](../audit/2026-04/executive-summary.md) — Phase 6 executive summary (source for sections 3–7 above).

Related compliance documents:

- [`WORDPRESS_ORG_COMPLIANCE_2026_04_15.md`](WORDPRESS_ORG_COMPLIANCE_2026_04_15.md) — most recent WP.org guideline-by-guideline re-audit.
- [`WORDPRESS_ORG_COMPLIANCE_COMPLETE.md`](WORDPRESS_ORG_COMPLIANCE_COMPLETE.md) — executive summary across all WP.org compliance work.
- [`WORDPRESS_ORG_COMPLIANCE_REPORT.md`](WORDPRESS_ORG_COMPLIANCE_REPORT.md) — detailed technical compliance report.
- [`MULTI-FRAMEWORK-COMPLIANCE-SUMMARY.md`](MULTI-FRAMEWORK-COMPLIANCE-SUMMARY.md) — HIPAA / ISO 27001 / SOC 2 implementation summary.

## 12. Sign-off

| Role | Action | Date |
|---|---|---|
| Audit lead | Phase 1–7 deliverables produced under [`docs/audit/2026-04/`](../audit/2026-04/) | 2026-04-26 |
| Maintainers | Published summary committed to `docs/compliance/` for v1.1.10 | 2026-04-27 |
| Next review | Quarterly cadence — target window | 2026-07 |

---

**This document is a summary.** For any decision that depends on finding-level precision (severity, CWE, file paths, status), consult [`findings-register.md`](../audit/2026-04/findings-register.md) directly — it is the authoritative record.
