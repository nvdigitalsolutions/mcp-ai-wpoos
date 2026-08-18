# NV oOS OOS Engine Context

> **GSD Context File** — Load this when working on the OOS (Open Operator System) engine: `includes/oos/`, `includes/bootstrap/oos-bridge.php`, the `lib/core` orchestrator/session/tool-scope domain, the Pro composition subsystem, or the parity CLIs.
> Last reviewed: August 18, 2026 (v1.1.58, Proposal 029 Phases 0–5.8).

---

## What the OOS engine is

The OOS engine is the framework-agnostic orchestrator in `lib/core` (`ChatOrchestrator`, domain contracts, 109 migrated tools). The WordPress plugin runs a **legacy chat path** (`includes/`) and can run the **OOS path** in three modes:

| Mode | Gate | Behavior |
|------|------|----------|
| Direct | Assistant chat routed by feature detection | OOS serves the response |
| **Shadow** (Phase 4.1) | `wp_mcp_ai_oos_shadow_enabled()` filter, **default off** | OOS runs in parallel on sampled legacy requests; the **legacy result is served** — zero user exposure |
| **Canary** (Phase 4.2) | `wp_mcp_ai_oos_canary` filter, **default off** + `_wp_mcp_ai_engine` post meta opt-in per assistant | Assistant runs on OOS |

The bridge (`includes/bootstrap/oos-bridge.php`) is the composition root: PHP 8.1 runtime gate, erlang-c concurrency, rate limiter, semantic compressor, data-budget tracker, tool resolver, and the shadow-runner bootstrap.

## Key subsystems & files

| Subsystem | Location | Notes |
|-----------|----------|-------|
| Bridge (composition root) | `includes/bootstrap/oos-bridge.php` | `wp_mcp_ai_oos_orchestrator()`; shadow/canary gate functions (filterable, default off) |
| Shadow runner | `includes/oos/class-wp-mcp-ai-oos-shadow-runner.php` | Hooked on `wp_mcp_ai_before_chat_request` priority 1; non-streaming only; stores up to 100 runs in `wp_mcp_ai_oos_shadow_runs` option (non-autoloaded); audit-logged via `WP_MCP_AI_Logger` |
| Security audit logger | `includes/oos/class-wp-mcp-ai-security-audit-logger.php` | Security-gate parity audit trail |
| Session log observer | `includes/measurement/class-wp-mcp-ai-session-log-observer.php` | Records `SessionLog`/`SessionEvent`/`SessionTelemetry` (Phase 3) |
| Parity CLI | `includes/cli/class-wp-mcp-ai-cli-oos-parity-command.php` | `wp mcp-ai oos parity` — reads the shadow-run store |
| Domain contracts | `lib/core/src/Domain/Contract/` | `ToolGuardInterface` (security-gate parity), `ToolResolverInterface`, `ToolWriteClassInterface`, `SessionLogStoreInterface`, `WaterfallEventDispatcherInterface` |
| Tool scoping | `lib/core/src/Application/Tool/ToolScope.php` + `Domain/ValueObject/ToolRestriction.php` | Phase 5 scoped tools |
| Compaction seam | `lib/core/src/Application/Chat/CompactionProvider.php` | Phase 5 compaction seam |
| Cancellation | `lib/core/src/Domain/ValueObject/CancellationToken.php` | Deadline tokens for shadow/orchestrator runs |
| Pro composition | `addons/pro/includes/composition/` | `WP_MCP_AI_Pro_Composition_Service`, `WP_MCP_AI_Pro_Legacy_Tool_Resolver`, `wp mcp-ai composition` CLI (Phase 5 Pro — child binding) |

## Safety invariants (do not weaken)

- Shadow and canary are **opt-in and default off**; shadow runs never emit output and always serve the legacy result.
- The bridge must remain **PHP 7.4-parseable** (runtime guarded by its own PHP 8.1 gate) — no PHP 8 syntax in `includes/oos/` or `oos-bridge.php`.
- The shadow store is capped at 100 entries, newest first, non-autoloaded.
- OOS-path tools must pass the same security gate (`ToolGuardInterface`) as the legacy path — capability + destructive-op parity (Phase 2).

## Where to read more

- Proposal: [`docs/project/proposals/029-oos-orchestration-runtime-consolidation-implementation-plan.md`](../docs/project/proposals/029-oos-orchestration-runtime-consolidation-implementation-plan.md) — phase gates and kill criteria.
- Folder READMEs: [`includes/oos/README.md`](../includes/oos/README.md), [`addons/pro/includes/composition/README.md`](../addons/pro/includes/composition/README.md).
- Tests: `addons/pro/tests/test-oos-composition-service.php`; shadow-runner behavior in the parity CLI test suite.
