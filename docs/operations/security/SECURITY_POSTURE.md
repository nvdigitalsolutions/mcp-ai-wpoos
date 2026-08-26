# Security Posture — Current State

> **Last Updated:** August 26, 2026
> **Based on:** April 2026 Security Audit ([SECURITY_AUDIT_2026_04.md](compliance/SECURITY_AUDIT_2026_04.md)) + subsequent remediation through v1.1.64 (closure of F-AUTHZ-01, F-AI-01, F-CMP-04)
> **Audit scope:** Base plugin + Pro addon + 6 minor addons = 2,983 PHP files, ~1,500 tools

---

## Executive Summary

The base plugin (the part that would ship to WordPress.org) has **0 Critical, 0 High, 6 Medium (all Fixed), and 12 Low** findings. The remaining open items are concentrated in addons and tooling — not in the base plugin's security surface. As of v1.1.64 the last three partially-fixed findings from the April 2026 audit (F-AUTHZ-01, F-AI-01, F-CMP-04) are closed or explicitly accepted.

---

## Finding Summary (April 2026)

| Severity | Total | Fixed | Partial | Open | Accepted |
|---|---|---|---|---|---|
| **Critical** | 0 | — | — | — | — |
| **High** | 5 | 4 | 0 | 0 | 1 |
| **Medium** | 14 | 14 | 0 | 0 | 0 |
| **Low** | 21 | 20 | 0 | 0 | 1 |
| **Informational** | 10 | — | — | — | — |
| **Total** | **50** | | | | |

Low Fixed count includes 4 findings closed as false-positive in the April 2026 audit. The single accepted High is F-AI-01 (Algorave sandboxed iframe — see rationale below); the single accepted Low is F-NPM-02.


---

## Previously Open / Partially-Fixed Findings (all closed as of v1.1.64)

### F-AUTHZ-01 — Webhook `__return_true` Permission Callbacks
| Field | Value |
|---|---|
| Severity | **High** |
| Status | ✅ **Fixed** (closed 2026-08-26, v1.1.64) |
| What | 11 webhook REST routes used `__return_true` as their `permission_callback` |
| Fixed | 4 routes moved to proper signature verification (Telegram login, agent-card ×2, Google Chat legacy fallback). Final sweep: every remaining legitimately-public route now carries an **inline justification comment** on its `__return_true` — Twitter CRC GET ×2, WhatsApp verify GET ×2, Messenger verify GET ×1, Telegram Mini App page/validate ×4. |
| Remaining | None. The OPTIONS preflight route in `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php` already carried a justification comment. |
| Risk | Low — the public routes return static challenges, verify their own HMAC signature inside the callback, or are pages opened by Telegram's in-app browser |
| Test coverage | 8 PHPUnit cases in `tests/test-webhook-permission-callbacks.php` |

### F-AI-01 — Algorave Live-Coding Sandbox
| Field | Value |
|---|---|
| Severity | **High** |
| Status | ✅ **Accepted with rationale** (decision recorded 2026-08-26, v1.1.64) |
| What | Algorave addon uses `new Function('Tone', code)` in the browser's main JS context |
| Fixed | (1) Shortcode refuses to render below `edit_posts` capability. (2) `new Function()` gated behind `WP_MCP_AI_ALLOW_TONEJS_EVAL` (default `false`). Strudel engine is the safe default. (3) New: visible raw-eval warning banner in the live-coder UI when the Tone.js engine is selected, plus a one-time per-session confirm-on-execute before compiling pasted code. |
| Rationale | The sandboxed-iframe recommendation is **deferred, not lost**. Residual exposure requires three compounding conditions: an explicit operator opt-in (`WP_MCP_AI_ALLOW_TONEJS_EVAL`), an `edit_posts`-capable actor, and a victim who pastes untrusted code — and the addon is excluded from the WP.org tree via `.distignore`. An opaque-origin sandboxed iframe would additionally require a CORS-enabled sample-serving endpoint and would conflict with the optional Strudel CDN setting, making the cost disproportionate to the residual risk. |
| Re-open trigger | The iframe becomes **mandatory** if the addon ever ships on WordPress.org or Guest Access turns the live coder into a public paste-and-share surface. |
| Risk | Low when `WP_MCP_AI_ALLOW_TONEJS_EVAL` is `false` (default). Strudel engine does not use `new Function()`. |

### F-LINT-02 — Pro Tree PHPCS (RESOLVED ✅)
| Field | Value |
|---|---|
| Severity | **Low** |
| Status | ✅ **Resolved** (May 2026, PRs #5070, #5078) |
| What | `addons/pro/*` was blanket-excluded from PHPCS in `phpcs.xml.dist` |
| Resolution | Blanket exclusion removed. 93% error reduction across all addons (1,143 → 82). Remaining errors are: (a) 8 files with PHP 8.3 parse errors in the PHPCS tokenizer, not code bugs; (b) naming convention exemptions (addons use `NVOOS_*` naming) which are documented and intentional. |
| Risk | Resolved — Pro addon is not distributed via WordPress.org. `.distignore` excludes `addons/`. |

### F-CMP-04 — Minified JS Without Source Maps
| Field | Value |
|---|---|
| Severity | **Low** |
| Status | ✅ **Fixed** (closed 2026-08-26, v1.1.64) |
| What | Minified JS shipped without source maps |
| Fixed | Final sweep of every `.min.js` under `addons/` (excluding `node_modules`): all plugin-authored minified bundles committed to the repo now ship sibling `.map` files. The last tracked one was `addons/pro/assets/js/tma-markdown.min.js`, regenerated from `tma-markdown.js` with a map (API surface verified identical). The page-agent `esbuild.config.js` now always emits external maps for its bundles, which are gitignored local build artifacts. |
| Exempt | Third-party vendor bundles are explicitly exempt per the R-Q-06 Chart.js precedent: cytoscape, currency.js, exceljs ×2, i18next, regression, subtitle, tesseract.js (all under `*/vendor/`). |
| Risk | Low |

---

## What Was Already Fixed (Highlights)

| ID | What | How |
|---|---|---|
| F-EXEC-01 | 11 `shell_exec`/`exec` calls in Pro tools | Migrated to `proc_open` array form. Gated behind `WP_MCP_AI_ALLOW_SHELL_TOOLS` (default `false`) + `manage_options` capability. |
| F-SQL-01 | 7 unprepared SQL statements in Graphify | Converted to `$wpdb->prepare()` with `%i` placeholders. |
| F-PRIV-03 | Missing HIPAA posture for healthcare/DICOM addons | PHI never reaches AI providers. Multisite guard added. Privacy API exporter + eraser cover all health CPTs. `docs/HIPAA_POSTURE.md` documents data flow. |
| F-SSRF-01 | No SSRF allowlist on tool-driven outbound HTTP | Central `wp_mcp_ai_is_safe_outbound_url()` helper. Resolves DNS, blocks loopback/private/link-local/multicast/IPA. |
| F-TLS-01 | `sslverify => false` in tool classes | Removed. All outbound requests now verify TLS. |
| F-PRIV-01/02 | Pro CCT/CPT not covered by Privacy API; AI-provider data flows undisclosed | Privacy API auto-wiring implemented. `EXTERNAL_SERVICES.md` documents all 45 base + 3 Pro external services. |

---

## Residual Risks (Accepted)

| ID | What | Why Accepted |
|---|---|---|
| F-NPM-02 | Pro `exceljs → uuid` chain (5 moderate advisories) | ExcelJS does not invoke the vulnerable `uuid` API. Fix would require a major-version downgrade of ExcelJS. |
| F-NPM-01 residual | 5 `uuid` advisories in `@wordpress/scripts` 31.x | Dev-only risk. Not shipped to end users. |

---

## Security Architecture (What's In Place)

| Layer | Implementation |
|---|---|
| **Capability gating** | Every tool's `execute()` checks `current_user_can()` against the tool's declared capability. Base class enforces this before execution. |
| **Nonces** | 368 `wp_create_nonce` / `wp_nonce_field` calls; 289 `check_*_referer` calls across the codebase. |
| **Encryption at rest** | All API keys stored via `wp_mcp_ai_encrypt()` (OpenSSL AES-256-CBC). |
| **Input sanitization** | Two-gate rule: sanitize `$arguments[...]` at entry, escape every value at exit. Enforced by custom PHPCS sniffs. |
| **Output escaping** | `esc_html()`, `esc_url()`, `esc_attr()`, `wp_kses_post()` applied throughout. |
| **DOMPurify** | Client-side sanitization of AI model output before DOM insertion (`chat-markdown-service.js`). |
| **SSRF protection** | Central `wp_mcp_ai_is_safe_outbound_url()` wrapper. Blocks loopback/private/link-local/multicast/APIPA. |
| **Telemetry** | Opt-in only. `class-wp-mcp-ai-activation-tracker.php` does not auto-phone-home. |
| **SQL preparation** | `$wpdb->prepare()` used throughout. Graphify previously had 7 unprepared statements — all fixed. |
| **File writes** | Restricted to `uploads/mcp-ai/` subdirectory. Path traversal prevented via `sanitize_file_name(basename())`. |
| **Shell execution** | Pro shell tools gated behind opt-in constant + `manage_options` capability. Uses `proc_open` array form (no shell interpolation). |
| **Permission callbacks** | All REST routes have `permission_callback`. 4 webhook routes use signature-verified callbacks. 9 legitimately-public webhook routes (Twitter CRC GET ×2, WhatsApp verify GET ×2, Messenger verify GET, Telegram Mini App page/validate ×4) plus the MCP OPTIONS preflight are documented exceptions, each carrying an inline justification comment. |

---

## What Would Pass a .org Review Today

Based on the April 2026 re-audit and May 2026 hardening (re-audit May 19, code review May 23):

- ✅ All 18 WordPress.org Plugin Directory Guidelines pass (most recent re-audit: May 23, 2026)
- ✅ `wp plugin-check` passes (gating CI job)
- ✅ PHPCS: 0 errors, 0 warnings on the WP.org-shipped tree (796 files)
- ✅ `composer audit`: clean on both root and pro trees
- ✅ ABSPATH guards on all PHP files
- ✅ Text domain consistency (`mcp-ai-wpoos`)
- ✅ No freemium/trial gating in base plugin
- ✅ No hardcoded menu positions
- ✅ All external services documented with terms/privacy links
- ✅ No HEREDOC/NOWDOC syntax
- ✅ Attribution is opt-in
- ✅ Pro addon is a separate codebase (`addons/pro/`) that `.distignore` excludes
- ✅ No open High/Medium findings from the April 2026 audit (F-AUTHZ-01, F-CMP-04 fixed; F-AI-01 accepted with rationale)

---

## What Would Need Work Before .org Resubmission

1. **The 90-day window has expired.** The author would need to negotiate with the .org plugin team or create a new submission.
2. **F-AUTHZ-01** — ✅ closed in v1.1.64: all legitimately-public webhook `__return_true` callbacks carry inline justification comments.
3. **F-AI-01** — ✅ accepted with rationale in v1.1.64 (layered gates + warning UI). Sandboxed iframe becomes mandatory only if the addon ships on WordPress.org or Guest Access makes the live coder a public paste-and-share surface.
4. **F-CMP-04** — ✅ closed in v1.1.64: every plugin-authored minified bundle has a sibling source map; third-party vendor bundles exempt per the R-Q-06 precedent.

---

## For a Security Reviewer: Where to Focus

1. **REST endpoint permission callbacks** — Verify every state-changing route has a proper `permission_callback` that checks user capabilities (not just nonces).
2. **Tool capability declarations** — Each tool declares a `required_capability`. Verify these match the operations the tool performs.
3. **API key storage and transmission** — Keys are encrypted at rest. Verify they're never logged or exposed in error messages.
4. **External HTTP requests** — Verify user-supplied URLs flow through `wp_mcp_ai_is_safe_outbound_url()`.
5. **File operations** — Verify all file writes are restricted to `uploads/mcp-ai/` and paths are sanitized.
6. **Pro shell-exec tools** — Verify `WP_MCP_AI_ALLOW_SHELL_TOOLS` defaults to `false` and the gate is checked before execution.
7. **Guest token flow** — Verify guest tokens can't escalate to privileged tool execution.

---

**Related documents:** [FOR_REVIEWERS.md](../../project/FOR_REVIEWERS.md) · [SECURITY_AUDIT_2026_04.md](../compliance/SECURITY_AUDIT_2026_04.md) · [TRACEABILITY.md](../compliance/TRACEABILITY.md) · [ADDON_INVENTORY.md](../../project/ADDON_INVENTORY.md)
