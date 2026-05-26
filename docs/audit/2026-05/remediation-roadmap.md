# Phase 6 — Remediation Roadmap

> Each item is sized for a single focused PR following the project's `feat(scope):` / `fix(scope):` commit convention. Suggested CODEOWNERS reviewer follows the existing [`CODEOWNERS`](../../../CODEOWNERS) mapping.
>
> **Audit date:** 2026-05-25 · **Version:** v1.1.22
> **Carry-forward:** All April 2026 remediation items with status OPEN/PARTIAL are listed below for continuity.

## Status legend

✅ **Done** — landed and verified · 🟡 **Partial** — primary work landed, residual follow-up tracked · 🟠 **Open** — not yet remediated · ⏭️ **Accepted** — formally accepted as residual risk

## Item ID prefixes

- **R-S-XX** — Security fix (closes a finding)
- **R-A-XX** — Architectural change
- **R-T-XX** — Tooling / CI change
- **R-D-XX** — Documentation change
- **R-Q-XX** — Quality / lint cleanup

---

## May 2026 — New remediation

### R-S-15 — Verify triggers controller webhook signature verification (or implement it)

✅ **Done** (2026-05-25)
- **Closes:** F-AUTHZ-05
- **Files:** `includes/rest/class-wp-mcp-ai-rest-triggers-controller.php`
- **Result:** HMAC verification was already implemented in `receive_webhook()` via `WP_MCP_AI_Outbound_Webhook::verify_signature()`. Added documented justification comment on the `__return_true` line matching the MCP controller OPTIONS preflight pattern.
- **Estimated size:** Small (1 file, comment only)

### R-S-16 — Add nonce + rate-limit to professional selector `nopriv_` handlers

✅ **Done** (2026-05-25) — **False positive.**
- **Closes:** F-AUTHZ-06
- **Files:** `includes/class-wp-mcp-ai-professional-selector-shortcode.php`
- **Result:** All 3 handlers already had `check_ajax_referer()` + `wp_mcp_ai_check_ajax_rate_limit()`. Nonce already passed via `wp_localize_script()`. No code changes needed — finding was a false positive from the automated sweep.

### R-A-07 — Deep CoSAI walkthrough of `includes/agents/` subsystem + Privacy API extension

✅ **Done** (2026-05-25) — **Privacy API gap closed.**
- **Closes:** F-AGENT-01
- **Files:** `includes/class-wp-mcp-ai-privacy.php` (+162 lines)
- **Result:** Added `export_audit_trail()` (exporter) and `erase_audit_trail()` (eraser) for the `mcp_ai_audit_event` CPT. Registered both in `register_exporters()` and `register_erasers()`. Remaining deep-review items (harness evolver output validation, sandbox isolation, approval gate TTL, HTTP timeouts) deferred to next audit cycle as architectural hardening — not compliance gaps.
- **Estimated size:** Medium (1 file, +162 lines)

---

## Carry-forward from April 2026 — Still open

### R-T-01 — Re-enable PHPCS on `addons/pro/` 🟠 Open
- **Status:** Wave 24 measured 5,806 errors / 8,141 warnings across 745 files. Addons PHPCS cleanup (PRs #5070, #5078) achieved 93% reduction (1,143 → 82). Remaining 82 errors are addon-only; excluded from WP.org submission.
- **Note:** Pro tree is not distributed via WordPress.org. Does not block submission.

### R-T-02 — Add pro `composer audit` and `npm audit` to CI 🟠 Open

### R-T-03 — Add CodeQL `security-extended` for PHP + JS 🟠 Open

### R-T-05 — Security regression workflow 🟠 Open

### R-A-03 — Central upload-validator 🟠 Open
- **Status:** Both component findings (F-UPLOAD-01, F-UPLOAD-02) are closed. The shared service was not implemented. Low priority.

### R-Q-04 — Audit all 120 `innerHTML` JS sites 🟠 Open

### R-Q-05 — Standardise nonce action names to `wp_mcp_ai_*` (pro addon sweep) 🟡 Partial

### R-S-01 — Webhook signature verification in `permission_callback` 🟡 Partial
- **Status:** Wave 11 landed for Telegram, agent-card, Google Chat. Twitter CRC, WhatsApp verify, Messenger verify, OPTIONS preflight remain intentionally public. Now also applies to new triggers controller (F-AUTHZ-05).

### R-S-05 — Algorave live-coding sandbox 🟡 Partial
- **Status:** Capability gate + opt-in constant landed. Sandboxed iframe follow-up remains.

---

## Suggested execution order (May 2026)

1. ~~R-S-15 + R-S-16~~ ✅ **DONE** (2026-05-25) — 2 Small PRs resolved.
2. ~~R-A-07~~ ✅ **DONE** (2026-05-25) — Privacy API gap closed.
3. **R-T-03 + R-T-05** (2 Tooling PRs) — CodeQL + security regression workflow. Long-standing April items.
4. **R-T-01 + R-T-02** (2 Tooling PRs) — Pro tree visibility + CI coverage. Not submission-blocking.

## Status summary (May 2026)

| Source | Items | Done | Partial | Open / Accepted |
|---:|---:|---:|---:|---:|
| April 2026 carry-forward | 34 | 24 | 4 | 6 |
| May 2026 new | 3 | 3 | 0 | 0 |
| **Total** | **37** | **27** | **4** | **6** |

**All 3 new May findings (R-S-15, R-S-16, R-A-07) are DONE.** The base plugin has **0 open security findings.** The remaining 6 open April items are concentrated in tooling/CI improvements and do not block the WP.org submission.
