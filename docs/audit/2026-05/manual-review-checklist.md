# Phase 3 — Manual Security Review Checklist

> Walkthrough of OWASP- and WP-aligned checklist items A–L from the audit plan. Each row is **Pass / Partial / Fail / N/A**, with file:line evidence and a forward link to the findings register where remediation is needed.
>
> This checklist carries forward all April 2026 results that remain unchanged and adds new columns for the `includes/agents/` subsystem and the new triggers controller.

| | Base (carry-forward) | `includes/agents/` (new) | Triggers Controller (new) | Pro Shortcode (new) | Finding |
|---|---|---|---|---|---|
| **A.1** Superglobals wrapped with `wp_unslash()` + sanitiser | ✅ | ✅ (first pass) | n/a | ⚠️ | — |
| **A.2** `json_decode` payloads schema-validated | ✅ | ⚠️ (harness evolver) | ⚠️ | ⚠️ | F-AGENT-01 |
| **A.3** File uploads validate MIME via `wp_check_filetype_and_ext` | ✅ | n/a | n/a | n/a | — |
| **B.1** Echo / template uses correct escaper | ✅ | ✅ (first pass) | ⚠️ | ⚠️ | — |
| **B.2** No raw HTML concat from AI response | ✅ DOMPurify | ✅ | n/a | ⚠️ (chat response in shortcode) | — |
| **B.3** SSE event payloads escape user-controlled fields | ✅ | n/a | n/a | n/a | — |
| **C.1** Every REST `permission_callback` is real | ✅ (except OPTIONS preflight) | n/a | ❌ `__return_true` on webhook | n/a | **F-AUTHZ-05** |
| **C.2** AJAX handlers check capability + nonce | ✅ | n/a | n/a | ⚠️ 3 `nopriv_` handlers | **F-AUTHZ-06** |
| **C.3** `wp_ajax_nopriv_*` reviewed individually | ✅ (0 remaining from April) | n/a | n/a | ⚠️ 3 new handlers | **F-AUTHZ-06** |
| **C.4** Bearer-token validation is constant-time + hashed | ✅ | ✅ (audit trail) | ⚠️ | n/a | — |
| **C.5** Auth0 / guest-token paths cannot escalate | ✅ | n/a | n/a | ⚠️ | — |
| **C.6** Multisite super-admin gates | ✅ | ✅ (approval gate) | n/a | n/a | — |
| **D.1** State-changing forms / AJAX have nonces | ✅ | n/a | n/a | ⚠️ | **F-AUTHZ-06** |
| **D.2** Nonce action names follow `wp_mcp_ai_{ctx}_{action}` | ✅ | N/A (no forms) | n/a | ⚠️ | — |
| **E.1** All `$wpdb` calls use `prepare()` with placeholders | ✅ | ⚠️ (audit trail inserts) | n/a | n/a | F-AGENT-01 |
| **E.2** `meta_query`/`tax_query` inputs typed/whitelisted | ✅ | n/a | n/a | n/a | — |
| **E.3** Custom tables follow `dbDelta` + uninstall cleanup | ✅ | ⚠️ (audit trail CPT) | n/a | n/a | F-AGENT-01 |
| **F.1** No `eval`, dynamic `include`/`require` from user input | ✅ | ✅ (sandbox uses `proc_open`) | n/a | n/a | — |
| **F.2** No `shell_exec`/`exec`/`system`/`passthru` | ✅ | ✅ | n/a | n/a | — |
| **F.3** Filesystem writes go through `WP_Filesystem`/`wp_upload_dir` | ✅ | ⚠️ (sandbox temp dir) | n/a | n/a | F-AGENT-01 |
| **F.4** Path traversal: user paths validated against allowlist | ✅ | ⚠️ (harness evolver) | n/a | n/a | F-AGENT-01 |
| **G.1** All outbound HTTP via `wp_remote_*` | ✅ | ⚠️ Not exhaustively verified | ⚠️ | n/a | F-AGENT-01 |
| **G.2** SSRF allowlist + private IP block | ✅ | ⚠️ | n/a | n/a | F-AGENT-01 |
| **G.3** TLS verification not disabled | ✅ (2 loopback-gated accepted) | ✅ (first pass) | n/a | n/a | **F-SSL-02** (ACCEPTED) |
| **H.1** API keys encrypted at rest | ✅ | n/a | n/a | n/a | — |
| **H.2** No keys in JS / error messages / logs | ✅ | ✅ (first pass) | n/a | ⚠️ | — |
| **H.3** `.env`, build artefacts free of credentials | ✅ | ✅ | n/a | n/a | — |
| **I.1** Tool execution requires capability check | ✅ | ✅ (approval gate) | n/a | n/a | — |
| **I.2** Tool results length-limited and escaped before re-entering prompt | ✅ | ⚠️ | n/a | n/a | — |
| **I.3** Agentic loop bounded | ✅ | ⚠️ (harness evolver) | n/a | n/a | F-AGENT-01 |
| **I.4** MCP server allowlist | ✅ | n/a | n/a | n/a | — |
| **I.5** A2A signature/HMAC verification on inbound | ✅ | n/a | n/a | n/a | — |
| **J.1** AI provider data-sharing disclosed in `readme.txt` | ✅ | ✅ (Baseten added) | n/a | n/a | — |
| **J.2** WP Privacy API exporters/erasers cover all PII | ✅ | ❌ Audit trail CPT not covered | n/a | n/a | F-AGENT-01 |
| **J.3** Healthcare addon HIPAA posture | ✅ | n/a | n/a | n/a | — |
| **J.4** Logging redacts PII and tokens | ✅ | ⚠️ (audit trail stores action data) | n/a | n/a | F-AGENT-01 |
| **K.1** No tracking/telemetry without explicit opt-in | ✅ | ✅ | n/a | n/a | — |
| **K.2** No "phone home" on activation | ✅ | ✅ | n/a | n/a | — |
| **K.3** No external script/CSS at runtime | ✅ | ✅ | n/a | n/a | — |
| **K.4** Bundled-code licences GPL-compatible | ✅ | ✅ | n/a | n/a | — |
| **K.5** No trademark conflicts in plugin name | ✅ | n/a | n/a | n/a | — |
| **K.6** `readme.txt` headers accurate | ✅ (1.1.22 / WP 6.9) | n/a | n/a | n/a | — |
| **K.7** No minified-only source without source map | ✅ | n/a | n/a | n/a | — |
| **K.8** ABSPATH guard on every PHP file | ✅ (942/942) | ✅ (10/10) | ✅ | ✅ | — |
| **L.1** `wp.i18n` for all strings | ✅ | ✅ | ⚠️ | ⚠️ | — |
| **L.2** No `eval` / `new Function` in product JS | ✅ | ✅ | n/a | n/a | — |
| **L.3** Chat output through DOMPurify | ✅ | n/a | n/a | ⚠️ | — |
| **L.4** localStorage doesn't store credentials | ✅ | n/a | n/a | ⚠️ | — |
| **L.5** No inline event handlers from user data | ✅ | n/a | n/a | n/a | — |

## Legend

- ✅ Pass — verified from prior audit and confirmed unchanged
- ⚠️ Review — needs attention; see linked finding
- ❌ Fail — confirmed gap; see linked finding
- n/a — feature not present in this component

## Focus areas for this audit

### New surface: `includes/agents/` (F-AGENT-01)
The 7,965-line agents subsystem passed all first-pass checks (ABSPATH guards, no dangerous functions, capability checks, CoSAI documentation). Deeper review needed for: Privacy API coverage, harness evolver output validation, sandbox isolation boundaries, HTTP timeout enforcement, and SQL prepare compliance.

### New surface: Triggers controller (F-AUTHZ-05)
The new webhook route at `mcp-ai/v1/triggers/webhook/(?P<id>\d+)` uses `__return_true`. The `receive_webhook()` method must be audited for provider signature verification.

### New surface: Professional selector shortcode (F-AUTHZ-06)
Three new `wp_ajax_nopriv_` handlers need nonce verification, rate limiting, and CSRF protection review.

### Carry-forward: April 2026 checklist
All items marked ✅ in April remain ✅ on v1.1.22. No regressions detected. The full April checklist is reproduced in [`docs/audit/2026-04/manual-review-checklist.md`](../2026-04/manual-review-checklist.md).
