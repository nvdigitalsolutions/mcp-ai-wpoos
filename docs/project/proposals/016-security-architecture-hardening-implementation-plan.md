# Implementation Plan: Security & Architecture Hardening (Code Review August 2026)

**Based on:** Proposal 016 (`docs/project/proposals/016-security-architecture-hardening-code-review-2026-08.md`)
**Date:** 2026-08-03
**Status:** Draft
**Target releases:** v1.1.44 (Wave 1), v1.2.0 (Wave 2), v1.3.0 (Wave 3)

---

## Executive Summary

Thirteen findings from the 2026-08-03 code review, remediated in three waves:

- **Wave 1 (v1.1.44, patch):** correctness and security-relevant fixes that touch few files and carry low regression risk — M6, H2, L1, L2, L5.
- **Wave 2 (v1.2.0, minor):** SSRF consolidation (H1), REST arg validation (M3), Pro standards & cleanup (M4, M5, L4, L6).
- **Wave 3 (v1.3.0, minor):** loader performance (M1) and instance-management consolidation (M2).

**Files modified:** ~14 base + ~6 pro (Waves 1–2); ~10 base (Wave 3, mostly mechanical).
**New files:** ~9 (exception class, HTTP wrapper, 2 PHPCS sniffs, 5 test files).
**Estimated LOC:** ~1,800 changed/added + ~900 test LOC.

Every task lists: files, concrete change, tests, and acceptance criteria. Task IDs map to findings (H1–L6).

---

## Wave 1 — v1.1.44 (patch)

### Task M6-1 — Preserve `expires_at` through credential normalization

**File:** `includes/class-wp-mcp-ai-credentials.php`

1. In `normalize_credentials()` (L376–402), add `expires_at` to the normalized record:

```php
$normalized[] = array(
    'id'         => $id,
    'hash'       => $hash,
    'created_at' => isset( $credential['created_at'] ) ? sanitize_text_field( $credential['created_at'] ) : '',
    'created_by' => isset( $credential['created_by'] ) ? absint( $credential['created_by'] ) : 0,
    'revoked_at' => isset( $credential['revoked_at'] ) ? sanitize_text_field( $credential['revoked_at'] ) : '',
    'revoked_by' => isset( $credential['revoked_by'] ) ? absint( $credential['revoked_by'] ) : 0,
    'expires_at' => isset( $credential['expires_at'] ) ? sanitize_text_field( $credential['expires_at'] ) : '',
);
```

2. Audit for any other fields written by `issue_credential()` but dropped by normalization (currently only `expires_at`; add a code comment: "any new field added to the issued record MUST be carried through here").

**Tests:** new `tests/security/test-credentials-expiry.php`:
- Issue two credentials on one assistant → revoke one → assert surviving credential retains `expires_at` and still fails validation after its expiry time (use `time()` mocking via filter or set expiry 1 second in the past for the negative case).
- `delete_credential()` variant of the same test.
- Legacy records without `expires_at` normalize to `''` and validate as non-expiring (BC).

**Acceptance:** new tests pass; `composer run lint:errors-only` clean on the file.

---

### Task M6-2 — Data repair routine for already-stripped credentials

**File:** `includes/class-wp-mcp-ai-credentials.php` (new static method) + `includes/bootstrap/activation.php` (upgrade hook).

1. Add `WP_MCP_AI_Credentials::repair_missing_expiry()`:
   - Iterates all `mcp_ai_assistant` posts with `_wp_mcp_ai_credentials` meta.
   - For each credential record missing `expires_at` **and** created after the 1.2.0 expiry feature shipped, recompute `expires_at = created_at + credential_lifetime_days`.
   - Records repaired count; logs via `WP_MCP_AI_Logger`.
2. Hook into the plugin upgrade routine (version-compare `wp_mcp_ai_version` option < 1.1.44) so it runs once.

**Tests:** seed a post with a legacy stripped record, run repair, assert `expires_at` restored; records with `revoked_at` are skipped (no point extending dead tokens).

**Acceptance:** idempotent (second run repairs 0); network-safe on multisite via existing `wp_mcp_ai_iterate_network_sites()`.

---

### Task H2-1 — Introduce `WP_MCP_AI_Destructive_Confirmation_Required` exception

**New file:** `includes/exceptions/class-wp-mcp-ai-destructive-confirmation-required.php`

```php
class WP_MCP_AI_Destructive_Confirmation_Required extends Exception {
    private $tool_slug;
    private $payload; // preview + flags + confirmation instructions
    public function __construct( $tool_slug, array $payload ) { ... }
    public function get_tool_slug() { ... }
    public function get_payload() { ... }
    public function to_wp_error() {
        return new WP_Error(
            'wp_mcp_ai_destructive_confirmation_required',
            $this->getMessage(),
            array( 'status' => 428 ) + $this->payload
        );
    }
}
```

Require it from `includes/bootstrap/loader.php` adjacent to the security classes (or via the new autoload path if Wave 3 has landed).

---

### Task H2-2 — Replace `wp_die()` in the gate with the exception

**File:** `includes/security/class-wp-mcp-ai-destructive-ops-gate.php`

1. `reject_unconfirmed()` → build the same payload as today, then `throw new WP_MCP_AI_Destructive_Confirmation_Required( $tool_slug, $payload )` instead of `wp_die()`.
2. Keep the audit-log call before throwing.
3. Add filter `wp_mcp_ai_destructive_gate_throw` (bool) so tests/integrations can observe without catching.

**File:** tool executor (locate the `wp_mcp_ai_before_tool_execution` dispatch site — `includes/class-tool-registry.php` / `includes/class-wp-mcp-ai-tool-registry.php` executor path):

4. Wrap the `do_action( 'wp_mcp_ai_before_tool_execution', ... )` in try/catch for the new exception; convert via `to_wp_error()` and return it as the tool result (canonical envelope).

**Tests:** new `tests/security/test-destructive-ops-gate.php`:
- Enable `require_confirm_destructive_ops`, execute a flagged tool without confirmation → assert `WP_Error` with status 428, `preview` payload, and that `rest_post_dispatch` filters still run when invoked through a REST request (integration test using `WP_REST_Server`).
- With `confirm_destructive => true`, tool executes.
- No `wp_die` — the process survives (assert by continuing the test after the call).

**Acceptance:** JSON envelope preserved end-to-end; gate audit event still logged.

---

### Task L1-1 — Generic DNS-failure message in URL guard

**File:** `includes/security/class-wp-mcp-ai-url-guard.php` L102–112.

Replace the `url_guard_dns_failed` message `Could not resolve hostname: %s` with a generic `The URL could not be validated.` Keep the detailed reason in a `WP_Error` data key (`'reason' => 'dns_failed'`, hostname only when `WP_DEBUG`).

**Tests:** extend the existing URL-guard test (or create `tests/security/test-url-guard.php`): unresolvable host → error code `url_guard_dns_failed`, message contains no hostname; hostname present in `get_error_data()` only under `WP_DEBUG`.

---

### Task L2-1 — `WP_MCP_AI_MASTER_KEY` constant support

**File:** `includes/class-wp-mcp-ai-encryption.php` `get_master_key()` L58–67.

```php
public static function get_master_key() {
    if ( defined( 'WP_MCP_AI_MASTER_KEY' ) && is_string( WP_MCP_AI_MASTER_KEY ) && '' !== WP_MCP_AI_MASTER_KEY ) {
        return WP_MCP_AI_MASTER_KEY;
    }
    // existing option path...
}
```

Document in `docs/operations/security/` (rotation doc: constant takes precedence; rotation via `rotate_master_key()` is a no-op with an explanatory `WP_Error` when the constant is defined — re-encryption under a config-managed key must be done by the operator).

**Tests:** define constant in test bootstrap → assert it wins over the option; rotation returns the explanatory error.

---

### Task L5-1 — Wrap mesh-key log line in WP_DEBUG

**File:** `includes/admin/class-wp-mcp-ai-settings-dashboard.php` L848. Wrap the `error_log()` in `if ( defined( 'WP_DEBUG' ) && WP_DEBUG )`.

**Acceptance:** grep shows no unconditional `error_log` left in that file.

---

### Wave 1 release checklist

- [ ] All five tasks merged to `alpha-working`
- [ ] New tests green: `tests/security/test-credentials-expiry.php`, `test-destructive-ops-gate.php`, `test-url-guard.php`
- [ ] `composer run lint:errors-only` clean
- [ ] CHANGELOG entry crediting the 2026-08-03 review
- [ ] `docs/operations/security/SECURITY_POSTURE.md` updated (M6/H2 marked fixed)

---

## Wave 2 — v1.2.0 (minor)

### Task H1-1 — Unify SSRF guards behind `WP_MCP_AI_Url_Guard`

**Files:** `includes/bootstrap/helpers.php`, `includes/security/class-wp-mcp-ai-url-guard.php`

1. Port the helper's advantages into the guard class: `gethostbynamel()` all-A-record check, `wp_mcp_ai_http_allowed_host` filter, IPv6 resolution with ULA/link-local/loopback blocking.
2. Re-implement `wp_mcp_ai_is_safe_outbound_url()` as a thin wrapper: `return ! is_wp_error( WP_MCP_AI_Url_Guard::validate( $url ) );` (keeping its exact current signature/behaviour for BC).
3. Deprecate nothing publicly — both entry points remain, one implementation.

**Tests:** `tests/security/test-url-guard.php` extended: parity matrix — every case in the old helper's behaviour table produces the same verdict via both entry points; IPv6 ULA (`fd00::1`), link-local (`fe80::1`), loopback (`::1`) blocked; multi-A-record host with one private IP blocked.

---

### Task H1-2 — Enforced HTTP wrapper for tools

**New file:** `includes/helpers/http-client-helpers.php` (or extend `api-key-helpers.php` sibling):

```php
function wp_mcp_ai_remote_get( $url, $args = array() ) {
    $check = WP_MCP_AI_Url_Guard::validate( $url );
    if ( is_wp_error( $check ) ) { return $check; }
    $args = wp_parse_args( $args, array( 'timeout' => 10, 'redirection' => 3 ) );
    return wp_remote_get( $url, $args );
}
// + wp_mcp_ai_remote_post()
```

Provide filter `wp_mcp_ai_http_skip_url_guard` (bool, default false) for the audited hardcoded-provider-endpoint callers to opt out explicitly rather than silently bypassing.

**New PHPCS sniff:** `phpcs/WPMCPAI/Sniffs/HTTP/RequireGuardedHttpSniff.php` — flags `wp_remote_get|post|request` in `includes/tools/` and `addons/pro/includes/tools/` unless the URL argument is a string literal or the call carries an annotated `// nvoos-http-audited: <reason>` comment (mirrors how the audit register recorded dispositions).

**Migration:** convert `scrape-product` and `responsive-image-validator` to the wrapper (they already call the helper — replace helper+fetch pair with the single wrapper). Annotate the ~507 audited call sites in bulk with the sniff comment generated from the findings register.

**Tests:** sniff unit fixtures (bad/good cases); wrapper tests (blocked URL returns `WP_Error` without an HTTP call — assert via `pre_http_request` filter hit-count).

---

### Task M3-1 — `validate_callback` with did-you-mean for tool slug

**File:** `includes/rest/class-wp-mcp-ai-rest-tools-controller.php` L186–191.

Add `validate_callback` on the `tool` arg: if slug not registered, return `WP_Error( 'wp_mcp_ai_unknown_tool', ..., array( 'status' => 400, 'suggestions' => $closest ) )` where `$closest` = up to 3 registered slugs by `levenshtein()`/`similar_text()`. Keep `sanitize_callback => 'sanitize_key'`.

**Tests:** REST integration test — POST unknown tool → 400 + suggestions array; known tool unaffected.

---

### Task M4-1 — Delete `.bak` file

Delete `addons/pro/includes/class-wp-mcp-ai-ezuite-cct-manager.php.bak`; add `*.bak`, `*.orig`, `*.old` to `.distignore` and to a pre-commit/CI check (`bin/check-no-backup-files.sh`, wired into `ci:all`).

---

### Task M4-2 — HMAC verification for graphify webhook

**File:** `addons/graphify/includes/rest/class-nvoos-graphify-rest.php` L374–384.

Mirror the Shopify pattern (`addons/pro/includes/class-wp-mcp-ai-shopify-sync-webhook-handler.php`): keep `permission_callback => '__return_true'` only if signature verification runs inside the callback — preferred: move HMAC check into the permission callback itself (closes F-AUTHZ-01 pattern properly).

- New setting `graphify_webhook_secret` (encrypted via `WP_MCP_AI_Api_Key_Store`).
- Verify `X-Graphify-Signature` HMAC-SHA256 of raw body; `hash_equals()`.
- Versioned migration: legacy unsigned requests accepted for 60 days with `_doing_it_wrong` + response header `X-NVoos-Deprecation`, then hard-fail.

**Tests:** valid signature → 200; invalid → 401; missing secret configured → 503 with setup instructions; legacy window behaviour.

---

### Task M4-3 — Pro under PHPCS (incremental)

1. New composer scripts: `lint:pro` (errors-only over `addons/pro/includes`), `lint:pro:baseline` (generate baseline of current warnings).
2. CI: add `lint:pro` errors gate (fails build on error-level); warnings tracked via baseline file, ratcheted (baseline may only shrink) — reuse the pattern from `test:gaps:check`.
3. Auto-fix pass: `phpcbf` on Pro for the ~11,016 auto-fixable issues, in one isolated commit, no functional changes.
4. Delete dead code found during the pass.

**Acceptance:** `lint:pro` errors = 0 in CI; baseline file committed; F-LINT-02 status updated in the findings register.

---

### Task M5-1 — Fail closed in chat controller

**File:** `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` L78–103.

Remove the `$GLOBALS` reconstruction; return `null` and have callers emit `WP_Error( 'wp_mcp_ai_controller_unavailable', ..., 503 )`. Log once per request via the security audit logger.

**Tests:** simulate missing main controller → 503 JSON error, not a reconstructed controller.

---

### Task L4-1 — `@since` alignment pass

Script (`bin/audit-since-tags.php`) listing `@since` versions that don't exist in `CHANGELOG.md`; fix to the next planned release (`1.2.0`) or the version that actually introduced the symbol. One mechanical commit.

### Task L6-1 — Shell-tool documentation correction

Update `execute_shell_command` class docblock + `docs/tool-reference.md`: the denylist is a UX speed-bump; the security boundary is the constant + capability gate + (recommended) running WP under a low-privilege OS user. Create a follow-up ticket for a binary allowlist model (out of scope here).

---

### Wave 2 release checklist

- [ ] H1 parity tests green; sniff enabled in `phpcs.xml.dist`
- [ ] Graphify webhook migration window communicated in CHANGELOG
- [ ] `lint:pro` gate green
- [ ] Findings register: H1, M3, M4, M5, L4, L6 marked fixed; F-AUTHZ-01 re-evaluated for closure
- [ ] `.bak` guard in CI

---

## Wave 3 — v1.3.0 (minor)

### Task M1-1 — Classmap autoloading for `includes/`

1. Add to `composer.json` autoload: classmap over `includes/` (excluding `includes/tools/` initially — tools keep explicit registration), plus PSR-4 for new `includes/exceptions/`.
2. `includes/bootstrap/autoload.php`: after loading Composer's autoloader, define `WP_MCP_AI_AUTOLOAD_CLASSES` (default true).
3. `includes/bootstrap/loader.php`: when the flag is on, skip the require list for classes covered by the classmap; keep requires for files with side effects (init scripts, `add_action` at load time — list them explicitly; ~30 files such as `settings-dashboard-init.php`, `tools-init.php`, hook registrations in loader itself).
4. Keep the legacy path behind `define( 'WP_MCP_AI_AUTOLOAD_CLASSES', false )` for one release.

**Acceptance/perf gate:** bootstrap counter (add to Site Health under WP_DEBUG): class-file includes per frontend request ≤ 40 (from ~200+); `composer run test` green on both paths; `wp plugin-check` green.

---

### Task M1-2 — Conditional subsystem boot

Split loader into boot profiles: `frontend` (shortcodes, blocks, REST), `admin` (+ admin pages, dashboards, ISO-27001 admin), `rest` (+ controllers), `cron/cli` (+ job queue). Register providers, federation, A2A, measurement exporters lazily on first use via the container's existing factory closures.

Remove the blanket `ob_start()`/`ob_end_clean()` wrapper; replace with targeted suppression only around the known-noisy include (if any remains).

**Tests:** existing suite green; new smoke test asserting frontend request doesn't load `WP_MCP_AI_Admin_*` classes.

---

### Task M2-1 — Container as single source; deprecate `$GLOBALS` backfill

1. `WP_MCP_AI::bootstrap()`: guard the `$GLOBALS` writes (L188–198) behind `WP_MCP_AI_LEGACY_GLOBALS` (default true in 1.3.0).
2. Add `_doing_it_wrong()`-style notice (via `wp_mcp_ai_deprecated_globals` filter + logger) when any code reads the legacy globals — implement a tiny compat layer function `wp_mcp_ai_legacy_global( $key )` used by the backfill so reads can be tracked where feasible (document that direct `$GLOBALS` reads can't be intercepted; grep-audit in-tree callers to zero).
3. Migrate in-tree readers to `wp_mcp_ai_container()->get(...)`.
4. 1.4.0: default false. 1.5.0: remove.

**Tests:** with legacy globals off, full suite green; with on, unchanged behaviour.

---

### Wave 3 release checklist

- [ ] Perf gate met (≤40 class files frontend)
- [ ] Both autoload paths CI-tested
- [ ] Deprecation notices documented in CHANGELOG + upgrade guide
- [ ] `docs/operations/security/SECURITY_POSTURE.md` and proposal 016 marked fully closed

---

## Cross-cutting

### New/changed tests (summary)

| File | Covers |
|---|---|
| `tests/security/test-credentials-expiry.php` | M6-1, M6-2 |
| `tests/security/test-destructive-ops-gate.php` | H2 |
| `tests/security/test-url-guard.php` | L1, H1-1, H1-2 |
| `tests/security/test-encryption-master-key-constant.php` | L2 |
| `tests/rest/test-tools-controller-validation.php` | M3 |
| `tests/rest/test-graphify-webhook-hmac.php` (or addon-local) | M4-2 |

### CI additions

- `lint:pro` errors gate (Wave 2)
- `bin/check-no-backup-files.sh` in `ci:all` (Wave 2)
- Autoload dual-path job (Wave 3)

### Documentation updates

- `docs/operations/security/SECURITY_POSTURE.md` — mark items fixed per wave
- `docs/project/audits/2026-04/findings-register.md` — close F-AUTHZ-01 (graphify), F-LINT-02 status
- `docs/tool-reference.md` — shell-tool boundary wording (L6)
- CHANGELOG entries per wave crediting the 2026-08-03 review

### Rollback plan

- Wave 1: each task is an isolated commit; revert individually. M6-2 repair routine is idempotent and additive (never deletes data).
- Wave 2: graphify change has a 60-day legacy window; PHPCS gate can be temporarily demoted to warning via CI variable; wrapper migration is behind sniff annotations, not behavioural changes for audited callers.
- Wave 3: entirely behind `WP_MCP_AI_AUTOLOAD_CLASSES` / `WP_MCP_AI_LEGACY_GLOBALS` flags; legacy loader retained for one full release.

### Effort estimate

| Wave | Dev | Tests | Review | Total |
|---|---|---|---|---|
| 1 | 1.5 d | 1.5 d | 0.5 d | ~3.5 d |
| 2 | 4 d (incl. phpcbf pass + sniff) | 2 d | 1 d | ~7 d |
| 3 | 5 d | 2 d | 1.5 d | ~8.5 d |
