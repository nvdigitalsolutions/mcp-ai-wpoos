# includes/oos/ — OOS Shadow Runner (Proposal 029, Phase 4)

## Purpose

Runs the opt-in OOS engine **in parallel** on sampled legacy-path chat requests for parity analysis, serving the legacy result — zero user exposure. Implements Phase 4.1 (shadow) of `docs/project/proposals/029-oos-orchestration-runtime-consolidation-implementation-plan.md` and nothing else.

## Conventions

- Class names follow `WP_MCP_AI_OOS_{Component}`; files are `class-wp-mcp-ai-oos-{component}.php`.
- PHP 7.4-parseable in this folder — no PHP 8-only syntax (the bridge's runtime gate handles 8.1+).
- Shadow mode is opt-in and default off; every new gate must ship as a filterable helper in `oos-bridge.php` defaulting to off.
- Run records are written through the canonical `WP_MCP_AI_Logger::log_event()` path — never `error_log()`.
- All new safety-critical logic must be covered by the safety invariants list above (or extend it in the same PR).

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4-parseable (runtime guarded by the OOS bridge's PHP 8.1 gate) |
| **Loaded by** | `includes/bootstrap/oos-bridge.php` — required + `WP_MCP_AI_OOS_Shadow_Runner::register()` when the lib/ tree is present |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_OOS_Shadow_Runner` | `class-wp-mcp-ai-oos-shadow-runner.php` | hooked on `wp_mcp_ai_before_chat_request` priority 1; CLI `wp mcp-ai oos parity` reads its store |

Stable contract: `STORE_OPTION = 'wp_mcp_ai_oos_shadow_runs'`, `STORE_MAX = 100`, run record shape (see `build_record()`), and the hook contract below.

## Inputs / Outputs / Neighbors

- **Listens to:** `wp_mcp_ai_before_chat_request` (legacy path fires it with `$assistant_id, $messages, $options, $request`; the OOS path fires the same hook with a domain event object — the runner ignores non-`WP_REST_Request` payloads).
- **Calls:** `wp_mcp_ai_oos_orchestrator()->handleChat()` (non-streaming only — shadow runs never emit output), guarded by a deadline `CancellationToken`.
- **Writes to:** option `wp_mcp_ai_oos_shadow_runs` (capped at 100, newest first, non-autoloaded) + `WP_MCP_AI_Logger::log_event('oos_shadow_run', …)`.
- **Gates:** `wp_mcp_ai_oos_shadow_enabled()`, `wp_mcp_ai_oos_shadow_sample_rate()`, `wp_mcp_ai_oos_shadow_timeout_seconds()` (all defined in `oos-bridge.php`, filterable, default off).

## Safety invariants (do not weaken)

1. **Write-class tools never execute in shadow.** The bridge registers a `tools/execute` waterfall (priority 20) that short-circuits write-class tools with a synthetic `(shadow: write-class tool suppressed)` result whenever `$context['shadow_mode']` is set. Classification: `ToolWriteClassInterface::isWriteClass()` (capability flags win; capability heuristic fails safe). A shadow run must never double-execute destructive tools.
2. **Shadow never emits output.** `handleChat()` (non-streaming) is the only path used; no SSE, no echo.
3. **Shadow failures are contained.** try/catch + deadline; a broken shadow run is recorded with an `error` key and the legacy response proceeds.
4. **No shadow on the OOS path.** The engine-flag guard prevents shadowing when OOS is already authoritative; the REST-request guard prevents shadowing from the bridge-mapped hook.

## Tests

```bash
vendor/bin/phpunit tests/ --filter OOSShadow
```

(Run-record shape and classification are covered indirectly by the lib/core suite: `LegacyToolAdapterTest::test*WriteClass*`, `ChatOrchestratorTest::testShadowModePropagatesToToolContext`.)

## Also Load

- [`includes/bootstrap/oos-bridge.php`](../bootstrap/oos-bridge.php) — flag helpers + suppression listener (mandatory pre-read)
- [`.context/pro-vs-base.md`](../../.context/pro-vs-base.md) — Base placement rationale
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — capability-flag taxonomy used by the classifier
- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- `docs/project/proposals/029-oos-orchestration-runtime-consolidation-implementation-plan.md` — Phase 4 gates and kill criteria
