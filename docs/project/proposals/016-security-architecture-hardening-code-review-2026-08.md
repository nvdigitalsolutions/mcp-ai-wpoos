# Proposal 016: Security & Architecture Hardening — Code Review Remediation (August 2026)

**Date:** 2026-08-03
**Status:** Draft
**Author:** AI-assisted code review (Zed / Kimi K3)
**Based on:** Full code review of base + pro plugin performed 2026-08-03 against `alpha-working` @ `eca652c8f`
**Related docs:** `docs/operations/security/SECURITY_POSTURE.md` · `docs/project/audits/2026-04/findings-register.md` · `docs/project/code-reviews/`
**Implementation plan:** `016-security-architecture-hardening-implementation-plan.md` (companion)

---

## 1. Executive Summary

A complete code review of the base plugin and Pro addon was performed on 2026-08-03, covering the bootstrap chain, DI/plugin kernel, the seven security classes, REST authentication and permission flows, the tool registry and representative tools, credentials, encryption, lifecycle handlers, Pro shell tools, SSRF guards, and upload validation.

**Overall verdict:** the codebase is well-disciplined for its size — the security architecture is genuinely layered and the self-audit trail is honest. The review surfaced **2 high**, **5 medium**, and **6 low** findings, plus one medium-severity data-integrity bug in credential expiry handling that is security-relevant.

This proposal recommends accepting all 13 findings and scheduling remediation across three releases:

| Release | Theme | Findings |
|---|---|---|
| **v1.1.44** (patch) | Correctness & security-relevant bug fixes | H2, M6/L3, L1, L2, L5 |
| **v1.2.0** (minor) | SSRF consolidation, Pro standards, Pro tree cleanup | H1, M3, M4, M5, L4, L6 |
| **v1.3.0** (minor) | Loader performance + instance-management consolidation | M1, M2 |

The findings register below uses the IDs assigned during the review (H = high, M = medium, L = low). M6 was promoted from L3 during triage because it silently strips token expiry.

---

## 2. Review scope

| Area | Files / classes examined |
|---|---|
| Bootstrap | `mcp-ai-wpoos.php`, `mcp-ai-wpoos-base.php`, `includes/bootstrap/*` (constants, autoload, loader, activation, helpers) |
| Kernel | `includes/class-wp-mcp-ai-plugin.php` |
| Security layer | `includes/security/` — request guard, URL guard, API key store, destructive ops gate, concurrency guard, cost tracker, CSP headers, audit logger |
| REST | `includes/class-wp-mcp-ai-rest.php` (permission flow L1900–2150, file download L6148–6330), `includes/rest/` — authenticator, controller base, tools controller, chat controller |
| Tools | Tool registry, `class-wp-mcp-ai-tool-scrape-product.php`, `class-wp-mcp-ai-tool-responsive-image-validator.php`, Pro `execute_shell_command` |
| Secrets | `includes/class-wp-mcp-ai-encryption.php`, `includes/security/class-wp-mcp-ai-api-key-store.php`, `includes/class-wp-mcp-ai-credentials.php` |
| Uploads | `includes/traits/trait-wp-mcp-ai-validated-upload.php` |
| Lifecycle | `includes/bootstrap/activation.php` (activation, uninstall, multisite) |
| Pro | `addons/pro/mcp-ai-wpoos-pro.php`, Pro tool tree (48 tool dirs), Pro REST surface (~100 routes) |
| Cross-cutting | SSRF call-site audit (~507 `wp_remote_*` callers), SQL preparation spot checks, AJAX nonce coverage, `unserialize()` usage, dangerous function usage |

---

## 3. What the review confirmed as strong

Recorded here so future audits don't re-verify:

1. **Request guard** — global `rest_pre_dispatch`/`rest_dispatch_request`/`rest_post_dispatch` wrapping: body-size limits, JSON-depth limits, exception wrapping, error-verbosity filtering, SSE slot tracking.
2. **SSRF posture** — `wp_mcp_ai_is_safe_outbound_url()` resolves all A records (DNS-rebinding defence) and blocks loopback/private/link-local/multicast/APIPA. The 2026-04 audit's per-callsite disposition (F-SSRF-01) was verified accurate.
3. **Encryption** — AES-256-GCM with `v2:` version prefix, legacy CBC decrypt fallback, transparent plaintext migration on read, master-key rotation with rollback.
4. **REST auth flow** — `WP_MCP_AI_REST::permissions_check()` (L1946–2111) correctly handles nonce/bearer/mesh/guest paths, per-assistant capability resolution, and blocks bearer-token privilege piggybacking on WP session cookies (L2018–2038).
5. **Shell tools** — triple gate (opt-in constant default-false + `manage_options` + denylist), `proc_open` with timeout, `exec()` fallback removed.
6. **Lifecycle** — uninstall gated on `delete_on_uninstall`, multisite-safe with per-site try/catch, correct LIKE-escaping in wildcard deletes, 26 cron hooks cleared.
7. **WP 6.7+/6.9 compatibility** — NOOP_Translations preload preventing JIT textdomain warnings; translations deferred to `init`.
8. **Honest audit trail** — 2026-04 findings register statuses verified accurate against code.

---

## 4. Findings register

### High

#### H1 — Two SSRF guard implementations; the standard one is the weaker one

- **Where:** `includes/security/class-wp-mcp-ai-url-guard.php` (used at only 2 call sites) vs `wp_mcp_ai_is_safe_outbound_url()` in `includes/bootstrap/helpers.php` (the documented standard, ~13 call sites).
- **Problem:** The two implementations have diverged. The helper does not perform the guard class's IPv6 ULA/link-local checks on resolved hosts, has no CIDR-blocked-range filter parity, and nothing prevents a future tool from calling raw `wp_remote_get( $url )` on user input — there is no enforced chokepoint.
- **Risk:** Medium-High. A new tool author copy-pasting an older tool that predates the helper reintroduces F-SSRF-01.
- **Recommendation:** Single canonical guard. Make the helper delegate to `WP_MCP_AI_Url_Guard`, extend it with IPv6 resolution checks, and add a `wp_mcp_ai_remote_get()` / `wp_mcp_ai_remote_post()` wrapper that tools are required (by PHPCS sniff) to use for any non-hardcoded URL.

#### H2 — `wp_die()` used as short-circuit inside the destructive-ops gate

- **Where:** `includes/security/class-wp-mcp-ai-destructive-ops-gate.php` L217.
- **Problem:** `wp_die( $error, 428 )` fires mid-`do_action`, bypassing the REST pipeline: `rest_post_dispatch` never runs (so the request guard's error-verbosity filter is skipped), REST callers receive a non-JSON HTML response, and the gate is untestable and unfilterable.
- **Risk:** Medium-High. Behavioural inconsistency for MCP clients; breaks the canonical error envelope the tool contract promises.
- **Recommendation:** Replace with an exception (`WP_MCP_AI_Destructive_Confirmation_Required`) thrown by the gate and converted by the tool executor into a `WP_Error` with status 428 and the existing preview payload. Add `wp_mcp_ai_destructive_gate_short_circuit` filter for testability.

### Medium

#### M1 — Eager `require_once` of ~200+ files on every request

- **Where:** `includes/bootstrap/loader.php` (829 lines, unconditional requires for measurement, federation, A2A, ISO-27001, 18 AI provider clients + adapters, workflows, etc.).
- **Problem:** Every frontend pageview pays hundreds of file stats and opcache/memory for classes that are never used on that request. The output-buffer-then-discard wrapper (L49–54, L561–563) additionally swallows legitimate warnings from included files.
- **Recommendation:** Migrate to Composer classmap/PSR-4 autoloading for `includes/`, boot subsystems conditionally (admin-only, REST-only, integration-gated), and remove the blanket output buffer in favour of targeted suppression.

#### M2 — Three overlapping instance-management patterns

- **Where:** `includes/class-wp-mcp-ai-plugin.php` L149–250; `includes/bootstrap/loader.php` L457–466; `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` L78–103.
- **Problem:** DI container, singletons (`::get_instance()` called at file-load time), and `$GLOBALS` backfill coexist. Consequences visible in-tree: loader comments documenting JetEngine init races, and `Chat_Controller::get_main_controller()` "defensive fallback to global scope" which silently re-caches possibly-stale state.
- **Recommendation:** Container as single source of truth; deprecate `$GLOBALS` backfill behind `WP_MCP_AI_LEGACY_GLOBALS` (default true in 1.3.0, false in 1.4.0, removed in 1.5.0); fail closed (503) instead of reconstructing controllers from globals.

#### M3 — `sanitize_key()` on the `tool` REST arg silently mutates intent

- **Where:** `includes/rest/class-wp-mcp-ai-rest-tools-controller.php` L186–191.
- **Problem:** `sanitize_key` lowercases/strips; an LLM-hallucinated slug fails lookup with a confusing "tool not found" instead of a validation error.
- **Recommendation:** Add `validate_callback` returning 400 with a did-you-mean list (levenshtein over registered slugs).

#### M4 — Pro tree excluded from PHPCS; `.bak` file shipped; addon `__return_true` routes

- **Where:** `composer.json` `lint:base` ignores `addons/pro`; `addons/pro/includes/class-wp-mcp-ai-ezuite-cct-manager.php.bak`; `addons/graphify/includes/rest/class-nvoos-graphify-rest.php` L374–384 (CREATABLE webhook with `__return_true`); `addons/funiq-bridge`, `addons/comic-reader` (READABLE, acceptable).
- **Problem:** The 2026-04 audit measured 5,806 PHPCS errors / 8,141 warnings in Pro (F-LINT-02, OPEN). The paid product has a standards gap the free product doesn't. The graphify CREATABLE webhook lacks the HMAC verification the Shopify webhook correctly implements.
- **Recommendation:** Delete the `.bak` file; add HMAC/shared-secret verification to the graphify webhook; bring Pro under PHPCS incrementally (errors-only gate first, then ratchet warnings via baseline).

#### M5 — Global-fallback in chat controller masks state divergence

- **Where:** `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` L78–103.
- **Recommendation:** Covered by M2 — fail closed with 503 and log, do not reconstruct from `$GLOBALS`.

#### M6 — Credential expiry silently stripped by normalize/store round-trip

- **Where:** `includes/class-wp-mcp-ai-credentials.php` `normalize_credentials()` L376–402 (drops `expires_at`), called by `get_credentials()`; `revoke_credential()` L176–219 and `delete_credential()` L229–269 both do read→modify→`store_credentials()` round-trips.
- **Problem:** Revoking or deleting any one credential on an assistant rewrites the whole meta array from normalized records — which omit `expires_at`. Every sibling credential silently becomes non-expiring.
- **Risk:** Medium (security-relevant data loss: tokens intended to expire become permanent).
- **Recommendation:** Carry `expires_at` (and any future fields) through `normalize_credentials()`; add a regression test: issue two credentials, revoke one, assert the other retains `expires_at`.

### Low

| ID | Finding | Where | Fix |
|---|---|---|---|
| L1 | DNS-failure message leaks internal hostname existence (enumeration oracle) | `includes/security/class-wp-mcp-ai-url-guard.php` L102–112 | Generic "URL could not be validated" for `dns_failed` |
| L2 | Master key only stored in DB option | `includes/class-wp-mcp-ai-encryption.php` L58–67 | Support `WP_MCP_AI_MASTER_KEY` constant in wp-config.php taking precedence |
| L4 | Version drift: header 1.1.43, docblocks `@since 1.2.0`, Pro 1.1.26 | repo-wide | Align `@since` tags to real release numbers at release time |
| L5 | Unconditional `error_log()` when mesh key auto-generated | `includes/admin/class-wp-mcp-ai-settings-dashboard.php` L848 | Wrap in `WP_DEBUG` guard |
| L6 | Shell-command denylist is regex-based, bypassable (reordered flags, interpreters, base64) | `addons/pro/includes/tools/architect-agent/class-wp-mcp-ai-tool-execute-shell-command.php` L43–69 | Document as speed-bump not security boundary; long-term binary allowlist |
| — | (promoted) | L3 → **M6** | see above |

---

## 5. Non-goals

- Re-auditing all ~1,031 tools line-by-line (the PHPCS canonical-envelope and sanitize-at-entry sniffs are the scalable control; extend them to Pro per M4 instead).
- Refactoring `lib/core` (nvoos/core) — the hexagonal split is sound; the gap is that the WP side doesn't route through it yet. That is a separate, larger proposal.
- Migrating the remaining 147 admin-ajax actions to REST — direction is already correct; continue opportunistically.

---

## 6. Success metrics

1. Zero high findings open after v1.2.0.
2. M6 regression test in `tests/security/` passing.
3. Single SSRF chokepoint: PHPCS sniff flags any `wp_remote_*` call in `includes/tools/` + `addons/pro/includes/tools/` that doesn't go through the wrapper.
4. Pro PHPCS errors gate in CI (errors > 0 fails the build).
5. Frontend request loads ≤ 40 plugin class files outside admin/REST context (measured via a bootstrap counter in `WP_DEBUG` mode).
6. Destructive-ops gate returns JSON `WP_Error` envelope through the REST pipeline (integration test asserts `rest_post_dispatch` ran).

---

## 7. Risks

| Risk | Mitigation |
|---|---|
| Autoload migration (M1) breaks a `require_once` ordering assumption somewhere in 200+ files | Ship behind `WP_MCP_AI_AUTOLOAD_CLASSES` flag for one release; keep the legacy loader as fallback; run full PHPUnit + plugin-check on both paths |
| Container consolidation (M2) breaks third-party code reading `$GLOBALS['wp_mcp_ai_*']` | Two-release deprecation window with `_doing_it_wrong()` notices |
| Hardening the graphify webhook breaks existing graphify clients | Versioned endpoint: keep legacy route with a shared-secret migration window, emit deprecation header |
| Pro PHPCS gate floods CI with legacy errors | Errors-only gate first; warnings ratcheted via baseline file, new-code-only enforcement |

---

## 8. Decision requested

Approve the findings register and the three-release scheduling, or propose an alternative split. The companion implementation plan (`016-security-architecture-hardening-implementation-plan.md`) breaks every finding into file-level tasks with tests.
