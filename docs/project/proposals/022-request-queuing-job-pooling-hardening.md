# Proposal 022: Request Queuing, Job Pooling & Concurrency Hardening

**Date:** 2026-08-11
**Status:** Draft
**Author:** AI Agent (Zed / DeepSeek V4 Pro)
**Based on:** Full architecture review of request handling, queuing, job pooling, and concurrency control in Base + Pro plugin performed 2026-08-11
**Related docs:** `docs/operations/security/SECURITY_POSTURE.md` · `016-security-architecture-hardening-code-review-2026-08.md` · `011-queue-worker-implementation-plan.md`
**Implementation plan:** `022-request-queuing-job-pooling-hardening-implementation-plan.md` (companion)

---

## 1. Executive Summary

A complete review of the plugin's request handling, queuing, job pooling, and concurrency infrastructure was performed against `includes/` and `addons/pro/includes/` on 2026-08-11. The review traced the full request path — REST entry → guard layers → authentication → tool execution → async dispatch → cron processing → dead letter queue — across **17 classes** spanning security, orchestration, queue management, and execution.

**Overall verdict:** The plugin has a genuinely layered architecture with many well-designed individual components. However, there are **significant integration gaps** — components exist in isolation but are not wired together at the execution chokepoints. Three parallel queuing systems operate independently with no unified view of system load. Two critical safety mechanisms (cost budget enforcement and operation-type concurrency limits) are fully implemented but never invoked from the production tool-execution path.

This proposal identifies **10 gaps** (2 critical, 4 high, 4 medium) and recommends a phased remediation approach across two releases.

| Release | Theme | Gaps |
|---|---|---|
| **v1.1.44** (patch) | Wire missing enforcement into execution path | C1, C2, H3 |
| **v1.2.0** (minor) | Backpressure, circuit-breaking, unification | H4, H5, H6, M7, M8, M9, M10 |

---

## 2. Review Scope

| Area | Files / classes examined |
|---|---|
| Request entry | `includes/security/class-wp-mcp-ai-request-guard.php` (body size, JSON depth, SSE slots) |
| REST dispatch | `includes/class-wp-mcp-ai-rest.php` (handle_tool_request, execute_tool_call_internal) |
| MCP protocol | `includes/class-wp-mcp-ai-rest-mcp-methods.php` (JSON-RPC batch, SEP-2243) |
| Auth | `includes/rest/class-wp-mcp-ai-rest-authenticator.php` |
| Concurrency | `includes/security/class-wp-mcp-ai-concurrency-guard.php` |
| Cost tracking | `includes/security/class-wp-mcp-ai-cost-tracker.php` |
| Rate limiting | `includes/class-wp-mcp-ai-rate-limit-manager.php`, `includes/class-wp-mcp-ai-sse-rate-limiter.php` |
| Job queuing | `includes/class-wp-mcp-ai-async-job-queue.php`, `includes/class-wp-mcp-ai-job-queue-manager.php` |
| Async execution | `includes/services/class-wp-mcp-ai-tool-async-executor.php`, `includes/services/class-wp-mcp-ai-async-tool-orchestrator.php` |
| Resource mgmt | `includes/class-resource-manager.php` |
| SLA | `includes/class-wp-mcp-ai-sla-manager.php` |
| Dead letter | `includes/class-wp-mcp-ai-dead-letter-queue.php` |
| Queue mgr (RMQ) | `includes/class-wp-mcp-ai-queue-manager.php` |
| Execution hooks | Subscribers at `wp_mcp_ai_before_tool_execution` (priority 0–99) |
| Pro extensions | `addons/pro/includes/class-wp-mcp-ai-circuit-breaker.php`, `addons/pro/includes/class-wp-mcp-ai-pro-parallel-model-dispatcher.php` |
| Cross-cutting | Execution hook subscriber audit, tool capability flag propagation |

---

## 3. Architecture: Request Flow (Current State)

```
HTTP Request
    │
    ▼
┌─────────────────────────────────────────┐
│ WP_MCP_AI_Request_Guard                 │  rest_pre_dispatch P10
│  • Body size check (default: 1 MB)       │
│  • JSON depth check (default: 32)        │
└─────────────────────────────────────────┘
    │
    ▼
┌─────────────────────────────────────────┐
│ WP_MCP_AI_Request_Guard::wrap_dispatch  │  rest_dispatch_request
│  • try/catch for unhandled exceptions    │
│  • Error-verbosity filtering             │
└─────────────────────────────────────────┘
    │
    ▼
┌─────────────────────────────────────────┐
│ Route handler (tools/call, chat, MCP)   │
│  • Authentication (nonce/bearer/Auth0)   │
│  • Per-user tool rate limit (60/60s)     │
│  • Tool resolution + allow-list check    │
└─────────────────────────────────────────┘
    │
    ▼
┌─────────────────────────────────────────┐
│ wp_mcp_ai_before_tool_execution (hook)  │
│  P0: DestructiveOpsGate       (confirm) │
│  P1: CoSAI Capability Boundary (gate)   │
│  P5: Tool Token Limiter        (budget) │
│  P5: Execution Observer        (metric) │
│  P99: OTel Span Exporter       (trace)  │
│  ✗  P?: ConcurrencyGuard       (MISSING)│  ← GAP C1
│  ✗  P?: CostTracker            (MISSING)│  ← GAP C2
└─────────────────────────────────────────┘
    │
    ▼
┌─────────────────────────────────────────┐
│ Async Orchestrator Decision             │
│  → sync:  $tool->execute()              │
│  → async: Tool_Async_Executor::queue()  │
│           → wp_schedule_single_event    │
│           → spawn_cron() + inline kick  │
└─────────────────────────────────────────┘
```

### 3.1 Three Parallel Queuing Systems

The plugin maintains **three separate, non-integrated** queuing systems:

| System | Storage | Purpose | Concurrency Model |
|---|---|---|---|
| `WP_MCP_AI_Async_Job_Queue` | DB table `mcp_ai_job_queue` | Command, workflow, tool, agentic-loop jobs | Cron poll (1/min) + Action Scheduler bridge |
| `WP_MCP_AI_Job_Queue_Manager` | DB table `mcp_ai_concurrent_jobs` | Concurrent API request throttling | `FOR UPDATE SKIP LOCKED` (MySQL 8.0+) + SLA tier limits |
| `WP_MCP_AI_Tool_Async_Executor` | WordPress transients | Async tool result lifecycle | WP-Cron single event + inline shutdown kick + cooperative tick lock |

**Key problem:** Jobs created by one system never respect the limits of another. A tool queued via `Async_Job_Queue` does not observe `Job_Queue_Manager`'s concurrency caps, and vice versa. There is no unified `get_total_system_load()` across all three.

### 3.2 What the Review Confirmed as Strong

1. **Request guard** — global `rest_pre_dispatch`/`rest_dispatch_request`/`rest_post_dispatch` wrapping at the earliest possible WordPress hook.
2. **Cooperative tick lock** in `Tool_Async_Executor` using `wp_cache_add()` with TTL — prevents inline shutdown worker and delayed cron loopback from double-executing.
3. **Dead Letter Queue** — automatic retry (3 attempts), typed retry strategies (webhook, cron_job, async_tool, job_queue), dismiss/purge lifecycle, admin UI integration.
4. **Atomic job claiming** — `SELECT ... FOR UPDATE SKIP LOCKED` in `claim_pending_jobs()` is the correct pattern for concurrent workers on MySQL 8.0+.
5. **SLA Manager** — Little's Law capacity calculation, tier-based priority and concurrency limits.
6. **Self-healing** — `kick_inline_if_stale()` triggers inline execution if a job sits `pending` >5 seconds.
7. **Agentic loop deduplication** — MD5-based `tool_call_signature` prevents re-queuing the same call across iterations.
8. **Rate limit manager** — `Retry-After` header parsing, exponential backoff, retriable error classification (429, 500, 502, 503, 504).
9. **Async orchestrator** — 8-priority decision hierarchy with clean SoC (background-only → force-sync → explicit arg → legacy → agentic loop → global setting → timeout risk → preference).

---

## 4. Findings Register

### Critical

#### C1 — ConcurrencyGuard Is Implemented But Never Invoked from Production Tool-Execution Path

- **Where:** `includes/security/class-wp-mcp-ai-concurrency-guard.php` (139 lines, full implementation with 9 operation types, acquire/release/usage API).
- **Call sites found:** Only 2:
  1. `includes/abilities/class-wp-mcp-ai-ability-security-bridge.php::check_concurrency()` — abilities subsystem only
  2. `includes/helpers/api-key-helpers.php::wp_mcp_ai_acquire_concurrency_slot()` — a helper that nothing calls
- **Missing:** No subscriber at `wp_mcp_ai_before_tool_execution` maps tool capability flags → concurrency operation type → calls `acquire()`/`release()`.
- **Impact:** Image generation (limit: 3), video generation (limit: 1), music generation (limit: 2), deep research (limit: 2), and other resource-intensive operations have **no concurrent execution limit**. A chat loop or batch job could spawn unlimited parallel video generation requests, exhausting API quotas and server resources.
- **Risk:** Critical. Resource-exhaustion DoS vector.

#### C2 — CostTracker Budget Enforcement Is Fully Implemented But Never Called

- **Where:** `includes/security/class-wp-mcp-ai-cost-tracker.php` (400+ lines, complete with model pricing for 16+ models, hourly spend tracking, budget estimation, and `check_budget()`).
- **Call sites found:** 0 from production tool-execution paths. The class's own docblock shows the intended pattern:
  ```php
  // $estimate = WP_MCP_AI_Cost_Tracker::estimate( $tool_slug, $arguments );
  // $check    = WP_MCP_AI_Cost_Tracker::check_budget( $assistant_id, $estimate );
  // if ( is_wp_error( $check ) ) { return $check; }
  // WP_MCP_AI_Cost_Tracker::record( $assistant_id, $actual_cost );
  ```
  This pattern is never followed. `WP_MCP_AI_Workflow_Run_CPT::check_budget()` is the only consumer and it checks workflow-run-level budgets, not assistant-level API cost budgets.
- **Impact:** Budgets configured in Settings → NV oOS → Cost Limits have **zero enforcement effect**. An assistant with a $5/day budget can silently exceed it.
- **Risk:** Critical. Financial control failure — no guardrail on API spend.

### High

#### H3 — `handle_tool_request()` and `execute_tool_call_internal()` Have Divergent Async Behavior

- **Where:** `includes/class-wp-mcp-ai-rest.php`:
  - `handle_tool_request()` (L5860–6019) — direct `tools/call` endpoint: **always executes synchronously**, never consults the async orchestrator.
  - `execute_tool_call_internal()` (L11520–11960) — chat agentic loop path: consults async orchestrator, respects `should_execute_async()`, can queue via `Tool_Async_Executor`.
- **Impact:** A tool flagged `background-only` (must run async) that is called directly via `tools/call` will execute synchronously and potentially timeout. A tool called from chat will correctly go async. This inconsistency creates unpredictable timeout behavior depending on the caller.
- **Risk:** High. Behavioral divergence between equivalent API surfaces.

#### H4 — No Backpressure Signal at REST Entry Point

- **Where:** All REST entry points (`handle_mcp_request`, `handle_tool_request`, `handle_chat_request`).
- **Problem:** When the system is overloaded (queues full, concurrency at capacity), the REST caller receives no backpressure signal (no `429 Too Many Requests` with `Retry-After`). The `Job_Queue_Manager::process_queue()` logs "at_capacity" internally, but this is a cron-side-only event invisible to API callers.
- **Impact:** Under load, requests pile up in queues indefinitely. Callers retry aggressively (no guidance from the server), compounding the overload. No graceful degradation path exists.
- **Risk:** High. Cascading failure under sustained load.

#### H5 — No Queue Depth Limit on Async Executor

- **Where:** `includes/services/class-wp-mcp-ai-tool-async-executor.php::queue_tool()` (L176–324).
- **Problem:** `queue_tool()` always accepts new jobs — there is no maximum-queue-size check, no rejection threshold, and no per-user queue cap. A runaway agentic loop or malicious caller can enqueue thousands of pending jobs.
- **Impact:** Transient storage exhaustion (each job stores metadata in transients), cron backlog (thousands of single events), and eventual memory pressure.
- **Risk:** High. Unbounded queue growth.

#### H6 — wp_mcp_ai_before_tool_execution Is Not a Unified Chokepoint for All Execution Paths

- **Where:** `includes/class-wp-mcp-ai-rest.php` — `handle_tool_request()` and `execute_tool_call_internal()` independently call `do_action('wp_mcp_ai_before_tool_execution', ...)` but each has its own surrounding logic for the destructive-ops gate catch and pre-execute filter.
- **Problem:** The `wp_mcp_ai_pre_execute_tool` filter short-circuit path exists in both methods but with slightly different context arrays and error handling. Future gate subscribers (like C1 and C2 fixes) need to be hooked into this action, and any divergence between the two call sites will cause inconsistent enforcement.
- **Risk:** High. Risk of partial enforcement as new gate subscribers are added.

### Medium

#### M7 — SSE Connection Tracking Is Duplicated Across Two Systems

- **Where:**
  1. `includes/class-wp-mcp-ai-sse-rate-limiter.php` — per-user limit (5), global limit (100), register/release with UUID tokens, `wp_mcp_ai_sse_user_*` / `wp_mcp_ai_sse_global` transients.
  2. `includes/security/class-wp-mcp-ai-request-guard.php` — `acquire_sse_slot()` / `release_sse_slot()` on `wp_mcp_ai_sse_stream_started` / `wp_mcp_ai_sse_stream_ended` hooks, `wp_mcp_ai_sse_connections_*` transients.
- **Impact:** The two systems use different transient keys and different limit defaults. A single user could have 5 connections tracked by the SSE rate limiter and 5 more by the Request Guard, effectively doubling the intended limit. Counters are never reconciled.
- **Risk:** Medium. Duplicated, potentially conflicting enforcement.

#### M8 — Tool Execution Rate Limiter Uses Per-User Transient, Not Distributed-Safe

- **Where:** `includes/class-wp-mcp-ai-rest.php::check_tool_rate_limit()` (L2842–2910).
- **Problem:** Uses per-user transients (`wp_mcp_ai_tool_rl_<user_id>`) which are not atomic. In a high-concurrency scenario, two simultaneous requests from the same user may read the same counter value before either increments, allowing transient rate-limit bursts above the configured maximum. The method's docblock acknowledges this as "best-effort."
- **Impact:** Theoretical rate-limit bypass during bursts. Acceptable for the current implementation but notes that a persistent object cache with atomic increment (Redis `INCR`) would provide stricter enforcement.
- **Risk:** Medium. Best-effort is reasonable for current install base; document the limitation.

#### M9 — WP_MCP_AI_Queue_Manager (RabbitMQ Path) Is Orphan Infrastructure

- **Where:** `includes/class-wp-mcp-ai-queue-manager.php` (full implementation with 4 execution modes, RabbitMQ integration).
- **Problem:** This class hooks into `wp_mcp_ai_before_tool_execute` at priority 5 but its `is_queue_available()` depends on `WP_MCP_AI_RabbitMQ_Client::is_available()`, which requires a configured RabbitMQ instance. On most WordPress installations this path never activates. Meanwhile, the `Async_Tool_Orchestrator` (a separate decision path) runs independently. There is no unified "which queue system is active?" query.
- **Impact:** Dead code weight. The class is required and loaded on every request (eager loading in bootstrap) but never activates. If someone does configure RabbitMQ, it introduces a fourth queuing system with no visibility into the other three.
- **Risk:** Medium. Code maintenance burden; potential surprise activation.

#### M10 — No Request-Level Priority Propagation

- **Where:** JSON-RPC request handling in `WP_MCP_AI_REST_MCP_Methods::handle_mcp_request()`.
- **Problem:** The `Async_Job_Queue` supports 5 priority levels and the `SLA_Manager` has 3 tiers, but there is **no mechanism for the caller to specify priority** in the JSON-RPC request. Priority is inferred solely from tool capability flags, not from request context. A time-sensitive user operation and a background batch job calling the same tool receive identical priority treatment.
- **Impact:** All callers of the same tool compete at the same priority level. SLA-aware clients (internal admin tools) cannot signal urgency.
- **Risk:** Medium. Limits SLA differentiation to tool-type only, not caller context.

---

## 5. What Is Already Fixed / Addressed

The following issues identified in earlier reviews and proposals have been resolved in the current codebase:

| Issue | Resolution |
|---|---|
| Missing rate limiting on tool execution | `check_tool_rate_limit()` implemented (v1.2.0) |
| SSE connection exhaustion | `WP_MCP_AI_SSE_Rate_Limiter` and `Request_Guard` SSE slots (v1.2.0) |
| Agentic loop infinite re-queuing | MD5 signature deduplication in `execute_tool_call_internal` |
| Cron loopback unreliability | Action Scheduler bridge + inline shutdown kick + `spawn_cron()` immediate trigger |
| Double-execution of async jobs | Cooperative tick lock via `wp_cache_add()` with TTL |
| No job lifecycle visibility | Job notifier, SSE progress events, webhook notifications |
| Legacy option-based queue storage | Migrated to custom DB tables (`mcp_ai_concurrent_jobs`) with `dbDelta()` |
| No SLA-aware prioritization | `WP_MCP_AI_SLA_Manager` with Little's Law and tier-based concurrency |
| Destructive operations without confirmation | `WP_MCP_AI_Destructive_Ops_Gate` at priority 0 |
| No circuit-breaking for autonomous loops | `WP_MCP_AI_Circuit_Breaker` (Pro, v1.2.0) |

---

## 6. Recommendation

Accept all 10 findings and schedule remediation in two waves:

- **Wave 1 (v1.1.44, patch):** Wire ConcurrencyGuard (C1) and CostTracker (C2) into `wp_mcp_ai_before_tool_execution`. Unify async behavior across `handle_tool_request` and `execute_tool_call_internal` (H3). These are low-regression-risk changes — the classes and hooks already exist; we are only adding subscribers and a `do_action` call.

- **Wave 2 (v1.2.0, minor):** Add backpressure (H4), queue depth limits (H5), unify SSE tracking (M7), add request-level priority parameter (M10), add provider circuit-breaking to base plugin (new), consolidate the `wp_mcp_ai_before_tool_execution` pattern (H6), and document the RabbitMQ path's status (M9).

Total estimated LOC: ~800 changed/added + ~600 test LOC across ~15 files.
