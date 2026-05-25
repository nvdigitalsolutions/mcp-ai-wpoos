# NV oOS Security & Compliance Audit — May 2026

> **Published summary:** [`docs/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_25.md`](../../compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_25.md) consolidates the deliverables in this directory into a single reference for maintainers and operators.

This directory contains the deliverables of the security and compliance code review of the **NV Digital Open Operator System (NV oOS)** WordPress plugin and its addons, executed against the 7-phase plan established in the April 2026 audit and carried forward for this May 2026 refresh.

## Scope at a glance

| Layer | Files (PHP) | Lint enforced today | Test target | Notes |
|---|---:|---|---|---|
| Base plugin (`mcp-ai-wpoos.php`, `includes/`) | **942** | ✅ WPCS via `composer run lint:base` | ✅ | WordPress.org distribution. Cleaner than April (~1,460 → 942). |
| Pro addon (`addons/pro/`) | 1,141 | ❌ excluded by `phpcs.xml.dist` line 24 | ✅ via `phpunit.xml.dist` | 76 MB vendor tree, 584 tool classes. |
| `addons/algorave/` | 20 | ✅ | partial | WebAudio / live-coding surface. |
| `addons/canvas/` | 2 | ✅ | none | Canvas/Fabric editor surface. |
| `addons/cornerstone3d/` | 2 | ✅ | none | DICOM / medical imaging. |
| `addons/embedded/` | 36 | ✅ | partial | iframe / embeddable surface. |
| `addons/fantasy-football/` | 25 | ✅ | partial | Yahoo / ESPN OAuth. |
| `addons/graphify/` | 22 | ✅ | partial | Graph DB and rendering. |
| `addons/docs-hub/` | — | ✅ | partial | Documentation hub (new since April). |
| `addons/chat-spa/`, `canvas-toolkit/`, `document-editor/`, `media-studio/`, `toolkit-shell/`, `saas-controller/`, `cloud-worker/` | various | ✅ | partial | New/minor addons surfaced since April. |
| **Total (excl. vendor)** | **~2,600** | — | — | — |

Tool classes: **247 base** + **584 pro** = **831 total** (live count from `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

## Standards applied

- WordPress Plugin Handbook security ([sanitize/validate/escape](https://developer.wordpress.org/plugins/security/securing-input/), [nonces](https://developer.wordpress.org/plugins/security/nonces/), [capabilities](https://developer.wordpress.org/plugins/security/checking-user-capabilities/))
- [WordPress.org Plugin Directory Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- WordPress Coding Standards (WPCS 3.3) + PHPCompatibilityWP
- [OWASP Top 10 (2021)](https://owasp.org/Top10/) + [OWASP API Security Top 10 (2023)](https://owasp.org/API-Security/editions/2023/en/0x00-toc/)
- GDPR / CCPA data-handling principles for AI provider integrations
- MCP / Server-Sent Events conformance for streaming security boundaries
- CoSAI Secure-by-Design Principles (P1–P3, MCP-T3/T5) for the new `includes/agents/` subsystem
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

1. **Baseline census** — Counted PHP files, tool classes, REST routes, AJAX handlers, cron jobs, shortcodes, and blocks across the full tree. See [`inventory.md`](./inventory.md).
2. **Pattern sweeps** — Targeted greps for `eval`, `shell_exec`/`exec`/`system`/`passthru`, `sslverify => false`, `__return_true` permission callbacks, `wp_ajax_nopriv_`, missing `ABSPATH` guards, unsanitised superglobals, and raw `<script>`/`<style>` tags.
3. **New surface review** — The `includes/agents/` subsystem (7,965 lines, 10 files, added May 22–23) was given a first-pass CoSAI compliance walkthrough. The new `triggers-controller` webhook route and `professional-selector-shortcode` `nopriv_` handlers were flagged for deep review.
4. **Carry-forward verification** — All 34 findings from the April 2026 audit were re-checked against v1.1.22 to confirm remediation held and no regressions were introduced by the ~1,400 commits between audits.
5. **Manual review** — Walked the A–L checklist against the agents subsystem and the new triggers controller.

> Phase 7 (remediation) is **out of scope of this audit** and tracked in [`remediation-roadmap.md`](./remediation-roadmap.md).

## Headline result

| Metric | April 2026 (v1.1.10) | May 2026 (v1.1.22) |
|---|---|---|
| Critical findings | 0 | **0** |
| High findings | 5 → all FIXED/PARTIAL | **0 new** (all April Highs remain closed) |
| Medium findings | 14 → all FIXED | **2 new** (F-AUTHZ-05, F-AUTHZ-06) |
| Low / Informational | 31 (21 Low + 10 Info) | **3 new** (F-AGENT-01, F-SSL-02, F-CPT-01) |
| Dependency CVEs (composer) | 0 | **0** |
| Dependency advisories (npm) | 13 moderate | TBD (re-audit needed) |
| WP.org submission readiness | ✅ Submission-ready | ✅ **Still submission-ready** (all 9 April gating items remain ✅) |

Per-finding status is authoritative in [`findings-register.md`](./findings-register.md). See [`executive-summary.md`](./executive-summary.md) for the narrative.

**Key improvements since April:**
- PHP file count reduced from ~1,460 to 942 (35% leaner base)
- Test files grew from ~365 to 1,077 (195% increase)
- `wp_ajax_nopriv_` handlers halved (6 → 3)
- REST route registrations reduced (190 → 127)
- All inline scripts/styles migrated to WP Enqueue APIs (144 `wp_print_inline_script_tag`/`wp_add_inline_style` calls)
- 0 `eval()` in product code
- 0 `shell_exec`/`exec` in base plugin
- All 942 files have ABSPATH guards

**New surface requiring attention:**
- `includes/agents/` — 7,965 lines of new CoSAI agent infrastructure (May 22–23)
- `includes/rest/class-wp-mcp-ai-rest-triggers-controller.php:122` — new webhook route with `__return_true`
- `includes/class-wp-mcp-ai-professional-selector-shortcode.php` — 3 new `wp_ajax_nopriv_` handlers

---

_Audit conducted: 2026-05-25. Status verified against branch `alpha-working` at version `1.1.22`. Auditor: automated agent walkthrough following the April 2026 methodology, with all numbers reproducible from the commands recorded in [`automated-scan-results.md`](./automated-scan-results.md)._
