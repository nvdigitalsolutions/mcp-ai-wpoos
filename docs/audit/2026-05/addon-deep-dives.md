# Phase 4 — Per-Addon Deep Dives

> This audit focuses on **new surface** since the April 2026 audit. For the addons `algorave`, `canvas`, `cornerstone3d`, `embedded`, `fantasy-football`, and `graphify`, the April 2026 deep dives are carried forward unchanged. See [`docs/audit/2026-04/addon-deep-dives.md`](../2026-04/addon-deep-dives.md) for full coverage.
>
> New this month: `includes/agents/` (base plugin), `triggers-controller` (base), `professional-selector-shortcode` (base), and several new addons surfaced since April.

## 1. `includes/agents/` — CoSAI Secure-by-Design Agent Infrastructure

**Risk profile.** ⚠️ Low-Medium (informational at this stage). 7,965 lines of new code across 10 classes. The subsystem is provider-agnostic and follows documented CoSAI principles.

### Surface

| File | Lines | CoSAI Principle | Risk |
|---|---|---|---|
| `class-wp-mcp-ai-agent-capability-boundary.php` | 638 | P2 — Bounded & Resilient | Low |
| `class-wp-mcp-ai-agent-audit-trail.php` | 1,664 | P3 — Transparent & Verifiable | Low (needs Privacy API coverage) |
| `class-wp-mcp-ai-agent-approval-gate.php` | 494 | P1 — Human-Governed | Low |
| `class-wp-mcp-ai-agent-code-sandbox.php` | 696 | MCP-T3/T5 — Sandbox | Medium (proc_open surface) |
| `class-wp-mcp-ai-agent-harness-bootstrap.php` | 788 | — | Low |
| `class-wp-mcp-ai-agent-harness-evolver.php` | 1,964 | — | Medium (AI-generated code deployment) |
| `class-wp-mcp-ai-agent-role-base.php` | 221 | — | Low |
| `class-wp-mcp-ai-agent-role-critic.php` | 281 | — | Low |
| `class-wp-mcp-ai-agent-role-executor.php` | 975 | — | Low |
| `class-wp-mcp-ai-agent-role-planner.php` | 244 | — | Low |

### First-pass findings

- ✅ All 10 files have ABSPATH guards
- ✅ No `eval()` / `shell_exec()` / `exec()` — code sandbox uses `proc_open` array-form
- ✅ Capability checks before privileged operations
- ✅ PHPDoc cites CoSAI principle per class
- ✅ Audit trail CPT registered with `map_meta_cap=false`
- ✅ No raw `<script>`/`<style>` inline

### Open items (F-AGENT-01)

1. **Privacy API gap.** The audit trail CPT (`mcp_ai_audit_event`) stores agent action logs — does `includes/class-wp-mcp-ai-privacy.php` cover it? If a user requests data export/deletion, their audit trail entries must be included.
2. **Harness evolver safety.** The 1,964-line evolver uses AI-driven code generation. Does it validate output before deploying evolved roles? Is there a human approval gate?
3. **Sandbox boundaries.** Code sandbox uses `proc_open` with timeout + output caps. What are the default limits? Are they configurable? Does the sandbox prevent filesystem writes outside the temp directory?
4. **Approval gate cleanup.** Pending approvals — is there a TTL? Are stale approvals cleaned up via cron?
5. **HTTP calls.** Do all HTTP calls in agent classes use `wp_remote_*` with explicit timeouts?

## 2. Triggers Controller — New Webhook Route (F-AUTHZ-05)

**Risk profile.** ⚠️ Medium. New webhook endpoint at `mcp-ai/v1/triggers/webhook/(?P<id>\d+)` with `permission_callback => '__return_true'`.

### Surface

- `includes/rest/class-wp-mcp-ai-rest-triggers-controller.php:122` — `receive_webhook()` method
- Route is publicly accessible without authentication
- Must verify provider signature inside the callback before processing

### Finding

- **F-AUTHZ-05 (Medium).** The route is publicly accessible. The April 2026 audit's F-AUTHZ-01 established the pattern for webhook routes: either move signature verification into the `permission_callback`, or document why the route is legitimately public (as done for OPTIONS preflight at line 138).
- **Required action.** Audit `receive_webhook()` to determine: (a) what provider/webhook system this listens for, (b) what signature scheme is used, (c) whether signature verification is already implemented inside the callback. If yes, document it. If no, implement it.

## 3. Professional Selector Shortcode — New `wp_ajax_nopriv_` Handlers (F-AUTHZ-06)

**Risk profile.** Medium. Three new unauthenticated AJAX handlers for a public-facing shortcode.

### Surface

- `includes/class-wp-mcp-ai-professional-selector-shortcode.php`
- Shortcode: `mcp_ai_professional_selector`
- 3 `wp_ajax_nopriv_` handlers:
  - `wp_mcp_ai_get_professional_config`
  - `wp_mcp_ai_get_models_for_provider`
  - `wp_mcp_ai_render_professional_chat`

### Finding

- **F-AUTHZ-06 (Medium).** Unauthenticated AJAX handlers must use nonce verification + rate limiting. The April 2026 audit's R-S-07 (Wave 16) established `wp_mcp_ai_check_ajax_rate_limit()` as the standard helper. These 3 handlers must be verified to call `check_ajax_referer()` and apply rate limiting.
- **Chat rendering risk.** `handle_render_professional_chat` triggers AI model calls — this could be abused for model-usage DoS if not rate-limited.

## 4. Addons surfaced since April 2026

These addons were not present in the April 2026 audit and are inventoried for completeness:

| Addon | Path | Risk profile | Status |
|---|---|---|---|
| **docs-hub** | `addons/docs-hub/` | Low | Documentation hub — recent PHPUnit 11 fixes, autocomplete warnings fixed |
| **chat-spa** | `addons/chat-spa/` | Low-Medium | Single-page app chat surface |
| **canvas-toolkit** | `addons/canvas-toolkit/` | Low | Canvas editing toolkit |
| **document-editor** | `addons/document-editor/` | Low | Document editing surface |
| **media-studio** | `addons/media-studio/` | Low | Media editing/processing |
| **toolkit-shell** | `addons/toolkit-shell/` | Low-Medium | Shell/CLI toolkit |
| **saas-controller** | `addons/saas-controller/` | Low-Medium | SaaS management — UUID bounds fix (PR #5074), addon-only |
| **cloud-worker** | `addons/cloud-worker/` | Low | Cloud worker orchestration |

All addons are excluded from the WordPress.org submission ZIP (`.distignore` line 135). None have been given a full security pass in this audit — they are inventoried for the next addon-focused audit cycle.

## 5. Cross-cutting recommendations (unchanged from April)

1. **Central HTTP client wrapper** — R-A-02 shipped in April (Wave 18). SSRF allowlist + DNS-rebinding defence.
2. **Central upload-validator** — R-A-03 remains open. Both component findings (F-UPLOAD-01, F-UPLOAD-02) are closed; the shared service was not implemented.
3. **Privacy registry** — R-A-04 shipped in April (Wave 23). Now needs extension to cover `mcp_ai_audit_event` CPT (F-AGENT-01).
