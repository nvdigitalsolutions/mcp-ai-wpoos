# Remaining Test-Suite Fixes Plan

Post-sweep remediation for the single-process PHPUnit suite. Follows the
exit-trap work in PR #5929 (`fix/test-suite-exit-traps`).

**Baseline (full 1,343-file sweep, 6 workers, 180s/file cap):**

| Status | Count |
|---|---|
| PASS | 704 |
| FAIL | 610 |
| DIED | 0 (2 found during the sweep, both fixed in PR #5929) |
| TIMEOUT | 24 |
| NO_TESTS | 3 |

Goal: get every remaining *suite-blocking* issue fixed — TIMEOUTs bounded and
understood, and the loud FAILs in the trap-adjacent files resolved where the
root cause is test drift or a small production bug. The 610-bucket overall is
pre-existing assertion drift and is **out of scope** here.

## Guiding principles

1. **No silent changes to production behavior.** Production edits are limited
   to visibility widening (`protected` → `public`), test-seam guards
   (`WP_MCP_AI_TESTS_RUNNING`), and one class rename that resolves a real
   duplicate-class bug.
2. **Tests must stay honest.** Prefer `markTestSkipped()` with an explicit
   reason over deleting assertions. Never weaken a security assertion
   (nonce/capability) to make a test pass.
3. **Each fix is validated per-file before moving on**, then a targeted sweep
   over changed files, then a full sweep at the end.

---

## Phase 0 — Environment re-triage (cheap, do first)

The sweep ran while the Docker host was I/O-saturated (the `oos-media-worker`
crash-loop + 6 parallel workers over the Windows 9p bind mount). Several
TIMEOUTs never got past bootstrap, which is not a code property.

1. `docker stop oos-media-worker` (if restarting).
2. Re-run every TIMEOUT file **individually** with a 600s budget and record
   pass/fail/time + where it stalls.
3. Re-classify:
   - **Bootstrap-stall artifacts** → re-run once more to confirm; if they now
     pass, no code change needed (record in the sweep notes).
   - **Real slow files** → apply the category fixes in Phase 2.

## Phase 1 — Deterministic fixes (each with root cause confirmed)

### 1.1 `tests/test-ajax-handlers-registered.php` fires `admin_init` 63×

**Evidence.** `setUp()` runs `do_action( 'admin_init' )` guarded by
`did_action()`. wp-phpunit's `_backup_hooks()` / `_restore_hooks()` roll
`$wp_actions` back per test, so `did_action( 'admin_init' )` is always 0 and
the full admin_init chain (WooCommerce + plugin admin classes) runs once per
test — 63 times. Before the WC shim it errored instantly (cheap); now it's the
slowest file in the suite and TIMEOUTs.

**Fix.** Move the one-time bootstrap into `setUpBeforeClass()`. Hooks
registered there are captured in every test's `_backup_hooks()` snapshot, so
they survive the per-test rollback — identical observable behavior, ~63× less
work:

```php
public static function setUpBeforeClass(): void {
	parent::setUpBeforeClass();
	if ( ! did_action( 'admin_init' ) ) {
		do_action( 'admin_init' );
	}
}
```

and delete the `did_action` guard from `setUp()`.

**Follow-up in the same file.** The 14 "missing action" failures are
environment-gated registrations (Auth0 setup, Pro packages, MCP endpoint
probes). Audit each against its registration gate (class loaded? addon
loaded? setting enabled?) and either (a) load the registering class the way
`tests/test-orchestration-dashboard-memory-phase4a.php` does, or (b) exclude
the action from `$expected_ajax_actions` when its gate is not met, with a
comment naming the gate. Do **not** simply delete entries.

**Validate.** File completes < 120s; failure count drops from ~50 to only the
genuinely-gated leftovers.

### 1.2 Export manager calls a `protected` method across classes (product bug)

**Evidence.** `WP_MCP_AI_Export_Manager::import()` (lines 316, 327, 337) calls
`$provider->log_action()`, declared `protected` on
`WP_MCP_AI_Export_Provider_Base`. PHP raises `Error: Call to protected method
WP_MCP_AI_Export_Provider_Base::log_action() from scope
WP_MCP_AI_Export_Manager` — this breaks **settings import in production** and
errors 3 tests in `tests/test-settings-utility-ajax.php`.

**Fix.** Change the declaration in
`includes/admin/export/class-wp-mcp-ai-export-provider-base.php`:

```php
public function log_action( string $action, $result ): void
```

Visibility widening only; all existing callers (`$this->log_action()` inside
providers) remain valid. Update the docblock (`@access` if present).

**Validate.** `tests/test-settings-utility-ajax.php` import tests move from
Error to assertions; add or update a unit test asserting the action is logged
if none exists.

### 1.3 Markup telemetry double-counts in tests

**Evidence.** `includes/markup-init.php` registers a **global**
`WP_MCP_AI_Markup_Telemetry` recorder at `plugins_loaded`. The tests
(`tests/test-markup-telemetry.php`,
`tests/test-markup-telemetry-admin-page.php`,
`tests/test-markup-stats-slash-command.php`) additionally call
`$this->telemetry->register()` in `setUp`, so every
`wp_mcp_ai_markup_request_created` fires **two** recorders →
`assertSame(1, ...['created'])` sees 2.

**Fix.** Remove the local `register()` calls and the paired
`remove_action()` blocks in `tearDown()` from all three test files. The global
recorder's hooks are registered before the first test's backup snapshot, so
they persist across tests; keep the existing `WP_MCP_AI_Markup_Telemetry::reset()`
calls in `setUp`/`tearDown`. Drop the now-unused `$this->telemetry` property
or keep it for symmetry only if referenced.

**Validate.** All three files PASS; `test_reset_handler_blocks_non_admins`
asserts 1 again.

### 1.4 Model pricing tests — catalog drift + JetEngine-gated branch

**Evidence.** `tests/test-model-pricing-checker.php` hardcodes upstream prices
that have since changed: gpt-4o is now `0.0025` input (test expects `0.005`),
and `o3-mini` / `o1-2024-12-17` expectations don't match the current catalog
in `WP_MCP_AI_Model_Rate_Limits_CCT::load_catalog()`. Additionally,
`test_update_model_costs_validates_pricing` can't reach the validation branch
because `update_model_costs()` bails with "Unable to access Model Rate Limits
CCT handler." when JetEngine is inactive (the validation happens *after* the
CCT-handler gate, inside the apply loop).

**Fix (two parts).**

1. **Re-anchor data assertions to the catalog, not to memorized prices.** For
   the three drift tests, read expected values from
   `WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data()` (filter by
   `model_name`) instead of hardcoding. Where a hardcoded value is still
   meaningful (e.g. `test_price_change_detection` seeds a synthetic "old"
   price), assert only that `new_input` equals the catalog's current value
   rather than a magic number, and keep the old-value assertions.
   `test_pricing_data_storage` / `test_new_models_tracked`: replace fixed
   model-name assertions with a lookup against the catalog (skip with a
   message if a model was genuinely removed).
2. **Gate the JetEngine-dependent test honestly.** At the top of
   `test_update_model_costs_validates_pricing`:

   ```php
   if ( class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' )
       && ! WP_MCP_AI_Model_Rate_Limits_CCT::get_item_handler() ) {
       $this->markTestSkipped( 'JetEngine CCT item handler is not available.' );
   }
   ```

   Optional follow-up (separate issue): move pricing *validation* ahead of
   the CCT-handler gate in `update_model_costs()` so invalid input is rejected
   before touching infrastructure — nicer behavior, but a product change, not
   part of this test-only pass.

**Validate.** File PASS (or PASS + skip) with JetEngine inactive; re-run with
the real CCT when available in CI.

### 1.5 Quick-actions widget tests — stale design/docs assertions

**Evidence.** Two pre-existing failures in `tests/test-quick-actions-widget.php`:
- `test_ajax_action_registered` asserts
  `wp_ajax_nopriv_wp_mcp_ai_execute_quick_action` is registered, but the
  handler constructor **intentionally omits nopriv** (comment at
  `includes/class-wp-mcp-ai-quick-actions-handler.php` lines 44-45 — the
  handler requires `read`, so nopriv is pointless).
- `test_documentation_exists` points at `docs/ai-quick-actions-widget-*.md`,
  which moved to `docs/features/chat-ui/ai-quick-actions-widget-*.md`.

**Fix.**
1. Update `test_ajax_action_registered` to assert the documented design:
   `wp_ajax_…` registered, `wp_ajax_nopriv_…` **not** registered.
2. Update the two doc paths to `docs/features/chat-ui/…`.

**Validate.** File PASS.

### 1.6 Orchestration stats action not registered for the misc-AJAX cluster

**Evidence.** `tests/test-assistant-misc-ajax.php::test_get_orchestration_stats_happy_path`
dispatches `wp_mcp_ai_get_orchestration_stats`, but
`WP_MCP_AI_Admin_Orchestration_Dashboard` (whose constructor registers it) is
only loaded by the admin branch of `includes/bootstrap/loader.php`, which is
not active in this CLI environment. Result:
`{"success":false,"data":""}` — no handler ran.

**Fix.** In that test, mirror the pattern from
`tests/test-orchestration-dashboard-memory-phase4a.php`: require the class file
(it self-instantiates and registers the actions) before dispatching, guarded by
`class_exists()`.

**Validate.** The test's happy path passes (the handler's `ajax_get_stats`
responds via the dispatch harness).

### 1.7 Settings-utility export shape assertion

**Evidence.** `test_export_settings_happy_path_returns_json_download` asserts
the decoded body has `settings`, but the Export Manager envelope has
`providers` (only the legacy fallback has `settings`).

**Fix.** Assert the envelope contract instead: `version` plus
(`settings` OR `providers`), with a comment naming both code paths.

**Validate.** File reaches PASS except any genuinely-gated cases.

### 1.8 Provenance-tracer eval stubs double-declare the Graphify bridge (CI fatal)

**Evidence.** `tests/test-memory-provenance-tracer.php::test_max_depth_filter_clamps_caller_value`
eval-declared stub classes `NV_oOS_Graphify_Memory_Bridge` and
`NV_oOS_Graphify_DB` (guarded by `class_exists`). The guard only prevents a
second eval — it does **not** prevent `tests/test-mempalace-phase4a-graphify-bridge.php`
(runs later in the same single-process suite) from `require_once`-ing the real
`addons/graphify/includes/class-nvoos-graphify-memory-bridge.php`, which then
hits `class NV_oOS_Graphify_Memory_Bridge` → **"Cannot declare class
NV_oOS_Graphify_Memory_Bridge, because the name is already in use"** and the
process dies.

**Fix.** Delete both evals. Load the real bundled classes instead
(`require_once` the bridge + DB files from `addons/graphify/includes/`, the
same way the mempalace tests do), and `markTestSkipped()` if they are not
loadable. A fresh test database has no graph rows, so the real
`NV_oOS_Graphify_DB::get_neighbor_ids()` walk returns an empty neighbourhood
and the clamp assertion (`available=true`, `depth=3`) still holds.

**Validate.** Run `tests/test-memory-provenance-tracer.php` **and**
`tests/test-mempalace-phase4a-graphify-bridge.php` in one phpunit invocation
(MySQL) — 23 tests, 111 assertions, 0 failures, no fatal.

### 1.9 Bootstrap's wc_get_page_screen_id() stub redeclares under WC (CI fatal)

**Evidence.** `tests/bootstrap.php` declared a `wc_get_page_screen_id()` stub
(guarded by `function_exists`) at parse time. WooCommerce declares the same
function **without** a guard in `includes/admin/wc-admin-functions.php`, which
`WC_Install::create_pages()` includes on every fresh install (each fresh MySQL
/ CI run). Result: **"Cannot redeclare wc_get_page_screen_id()"** during
wp-phpunit's install phase. SQLite re-runs reuse the persisted database and
skip installation, which is why local runs never hit it.

**Fix.** Defer the decision to `muplugins_loaded` priority 1 (before
WooCommerce boots at priority 5): when WooCommerce's real file exists,
`require_once` it; declare the stub only when WooCommerce is absent (Base
builds). The real file exits on undefined `ABSPATH`, hence the deferral.

**Validate.** Fresh MySQL run completes the install phase with no fatal; the
stub path is exercised by Base builds without WooCommerce.

### 1.10 Toolkit MCP server rejects non-OAuth clients + native resource URIs

**Evidence.** `addons/pro/tests/test-toolkit-server-execution.php` failed 9/10
on alpha-working: `check_scope_for_method()` treated a null OAuth scope
(non-OAuth auth) as insufficient, returning -32001 for every JSON-RPC call;
and `handle_resources_read()` ran URIs through `esc_url_raw()`, which strips
the `nvoos://` scheme and rejected every native URI with "missing uri".

**Fix.** Scope enforcement is OAuth-only — return early when the granted
scope is null (matching `WP_MCP_AI_REST_Authenticator::oauth_scope_sufficient()`
and the controller's own docblock). Sanitize resource URIs with
`sanitize_text_field()` instead of `esc_url_raw()` (the same sanitizer the
mounted-URI parser already used).

**Validate.** `addons/pro/tests/test-toolkit-server-execution.php`: 10/10 PASS
(was 1/10).

---

## Phase 2 — TIMEOUT triage (after Phase 0 re-runs)

Categories and prescribed handling, applied per file:

| Category | Signature | Handling |
|---|---|---|
| Bootstrap-stall artifact | tail stops at "Loaded optional test plugins…" | Re-run once; if green, no change — note in sweep log |
| External HTTP without stub | stall inside a `wp_remote_*` call in a client/provider test (e.g. Hugging Face, ISAMS, provider connections) | Use `WP_MCP_AI_Ajax_TestCase::stub_http_response()` / a `pre_http_request` filter; default-deny already exists in the helper |
| Async/sleep loops | Veo async, cron status SSE, timeout-loop-safety | Add or honor a test seam: shorten polling intervals via a filter or `WP_MCP_AI_TESTS_RUNNING` fast path; never assert on wall-clock timing |
| Heavy-but-legit | `test-cron-manager.php`, import handlers | Keep; confirm it finishes under the CI timeout, else raise the sweep `--timeout` for the file list only |

Files observed in the 24: `test-isams-query-tool`, `test-jetengine-cpt-taxonomy-integration`,
`test-import-blueprint-tools`, `test-chat-turn-observer`, `test-memory-streaming`,
`test-action-safety-profile`, `test-asset-inventory`, `test-chat-transcript-guest-tokens`,
`test-cron-manager`, `test-cron-nested-hook-tracking`, `test-huggingface-client`,
`test-import-handlers-ajax`, `test-markup-validator`, `test-pm-task-dependencies`,
`test-provider-connections-ajax`, `test-rest-cron-status-sse`, `test-skill-registry`,
`test-timeout-loop-safety`, `test-token-sanitization`, `test-veo-1080p-auto-duration`,
`test-veo-duration-fix`, `test-veo-parent-job-completion`, `test-veo-timeout-async-fallback`.

## Phase 3 — Validation & re-sweep

1. Per-file runs for every file touched (PHPUnit summary must print).
2. `php bin/sweep-tests.php --files <changed files> --timeout 300`.
3. Re-triage the TIMEOUT list against the Phase 0 notes.
4. Full 6-worker sweep (`--list` → shard, mirrored DBs, preseeded
   `wp_mcp_ai_professions_seeded`); target: **DIED 0, TIMEOUT ≤ a small
   documented allowlist**, and no pass-to-fail regressions in touched files.
5. WPCS (`composer run lint`) on changed production files; PHP lint on all
   changed files.

## Phase 4 — Delivery

- Commit per fix (atomic, imperative subjects).
- Push to `fix/test-suite-exit-traps`, update PR #5929 body/comment with the
  new numbers.
- Vendor files remain uncommitted.

## Out of scope (recorded, not fixed here)

- The 610 FAIL bucket (pre-existing assertion drift across pro/base areas).
- `test-markup-telemetry` counter semantics beyond the double-registration fix.
- Validation-first reordering of `update_model_costs()` (product change).
- `addons/saas-controller/worker/dist/index.js` working-tree deletion (build
  artifact, unrelated).
