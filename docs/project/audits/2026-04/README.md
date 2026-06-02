# NV oOS Security & Compliance Audit — April 2026

> **Published summary:** [`docs/compliance/SECURITY_AUDIT_2026_04.md`](../../compliance/SECURITY_AUDIT_2026_04.md) consolidates the deliverables in this directory into a single reference for maintainers and operators.

This directory contains the deliverables of the security and compliance code review of the **NV Digital Open Operator System (NV oOS)** WordPress plugin and its addons, executed against the 7-phase plan recorded in the audit kickoff PR.

## Scope at a glance

| Layer | Files (PHP) | Lint enforced today | Test target | Notes |
|---|---:|---|---|---|
| Base plugin (`mcp-ai-wpoos.php`, `mcp-ai-wpoos-base.php`, `includes/`) | ~1,460 | ✅ WPCS via `composer run lint:base` | ✅ | WordPress.org distribution. |
| Pro addon (`addons/pro/`) | 1,141 | ❌ excluded by `phpcs.xml.dist` line 24 | ✅ via `phpunit.xml.dist` | 76 MB vendor tree, 584 tool classes. |
| `addons/algorave/` | 20 | ✅ | partial | WebAudio / live-coding surface. |
| `addons/canvas/` | 2 | ✅ | none | Canvas/Fabric editor surface. |
| `addons/cornerstone3d/` | 2 | ✅ | none | DICOM / medical imaging. |
| `addons/embedded/` | 36 | ✅ | partial | iframe / embeddable surface. |
| `addons/fantasy-football/` | 25 | ✅ | partial | Yahoo / ESPN OAuth. |
| `addons/graphify/` | 22 | ✅ | partial | Graph DB and rendering. |
| **Total (excl. vendor)** | **2,983** | — | — | — |

Tool classes: **231 base** + **584 pro** = **815 total** (the README's "519" is out-of-date; see [`inventory.md`](./inventory.md)).

## Standards applied

- WordPress Plugin Handbook security ([sanitize/validate/escape](https://developer.wordpress.org/plugins/security/securing-input/), [nonces](https://developer.wordpress.org/plugins/security/nonces/), [capabilities](https://developer.wordpress.org/plugins/security/checking-user-capabilities/))
- [WordPress.org Plugin Directory Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- WordPress Coding Standards (WPCS 3.3) + PHPCompatibilityWP
- [OWASP Top 10 (2021)](https://owasp.org/Top10/) + [OWASP API Security Top 10 (2023)](https://owasp.org/API-Security/editions/2023/en/0x00-toc/)
- GDPR / CCPA data-handling principles for AI provider integrations
- MCP / Server-Sent Events conformance for streaming security boundaries
- Project-specific rules in [`.context/security-checklist.md`](../../../.context/security-checklist.md), [`CLAUDE.md`](../../../CLAUDE.md), [`CONTRIBUTING.md`](../../../CONTRIBUTING.md)

## Deliverables in this folder

| File | Purpose |
|---|---|
| [`inventory.md`](./inventory.md) | Phase 1 — entry points, REST routes, AJAX handlers, CLI commands, cron, shortcodes, blocks, tool classes, dependency SBOM. |
| [`automated-scan-results.md`](./automated-scan-results.md) | Phase 2 — raw output of PHPCS, `composer audit`, `npm audit`, manual pattern sweeps. |
| [`manual-review-checklist.md`](./manual-review-checklist.md) | Phase 3 — A–L checklist walkthroughs with pass/fail per addon. |
| [`addon-deep-dives.md`](./addon-deep-dives.md) | Phase 4 — risk profile per addon. |
| [`test-coverage-gaps.md`](./test-coverage-gaps.md) | Phase 5 — security test coverage gaps and required new tests. |
| [`findings-register.md`](./findings-register.md) | Phase 6 — every finding with severity, file:line, CWE, recommendation, owner. |
| [`executive-summary.md`](./executive-summary.md) | Phase 6 — top-line verdict, top-10 issues, WP.org submission readiness. |
| [`remediation-roadmap.md`](./remediation-roadmap.md) | Phase 6 — PR-sized work items grouped by severity. |
| [`wp-org-submission-checklist.md`](./wp-org-submission-checklist.md) | WordPress.org Plugin Directory pre-submission checklist for the base plugin. |

## How this audit was conducted

1. **Baseline tooling** — `composer install`, then ran `composer audit` (root + pro), `npm audit --omit=dev` (root + pro), and `vendor/bin/phpcs --error-severity=1 --warning-severity=8` against the base tree (902 files, 73 with errors).
2. **Pro tree** — temporarily lifted the `addons/pro/*` exclusion from `phpcs.xml.dist` for the duration of this audit branch (see [`automated-scan-results.md`](./automated-scan-results.md)). The exclusion has not been removed from `main` yet — that is roadmap item **R-T-01**.
3. **Pattern sweeps** — targeted greps for `eval`, `shell_exec`/`exec`/`system`/`passthru`, `sslverify => false`, `__return_true` permission callbacks, missing `ABSPATH` guards, and unsanitised superglobals.
4. **License scan** — composer license metadata pulled per package; no GPL-incompatible licences found in dependencies of either the base or pro tree.
5. **Manual review** — walked the A–L checklist against representative samples of each addon and the base plugin's REST/AJAX/tool execution paths.

> Phase 7 (remediation) is **out of scope of this audit** and tracked in [`remediation-roadmap.md`](./remediation-roadmap.md). Each high-severity item is sized for a focused single-issue PR.

## Headline result

| Metric | At audit close (2026-04-26) | Current status (2026-04-27) |
|---|---|---|
| Critical findings | **0** | **0** |
| High findings | **5** | 3 FIXED, 2 PARTIALLY FIXED, 0 OPEN |
| Medium findings | **14** | All FIXED |
| Low / Informational | **31** (21 Low + 10 Info) | 14 Low FIXED + 1 PARTIALLY FIXED + 4 CLOSED (no fix needed) + 2 ACCEPTED; 10 Informational |
| Dependency CVEs (composer) | **0** | **0** |
| Dependency advisories (npm, prod) | 13 moderate (10 root, 3 pro) — all auto-fixable | Reduced via `npm audit fix`; remaining items are dev-only or in `.distignore`-excluded `optionalDependencies` (F-NPM-01 FIXED, F-NPM-02 ACCEPTED) |
| WP.org submission readiness (base plugin) | **Not yet** | ✅ **Submission-ready** — all 9 gating items in [`wp-org-submission-checklist.md`](./wp-org-submission-checklist.md) are ✅ as of 2026-04-27 (v1.1.10) |

Per-finding status is authoritative in [`findings-register.md`](./findings-register.md). See [`executive-summary.md`](./executive-summary.md) for the narrative and [`remediation-roadmap.md`](./remediation-roadmap.md) for the wave-by-wave roadmap.

---

_Audit conducted: 2026-04-26. Status table reverified 2026-04-27 against branch `copilot/prepare-plugin-for-resubmission` at version `1.1.10`. Auditor: automated agent walkthrough of the planned methodology, with all numbers reproducible from the commands recorded in [`automated-scan-results.md`](./automated-scan-results.md)._
