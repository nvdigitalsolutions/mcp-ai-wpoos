# Ralph Wiggum Loop — Gap Remediation & Production Hardening Plan

**Status:** Draft for Review  
**Based on:** Audit of current implementation (2026-07-01), industry best-practices research  
**References:**
- `RALPH-WIGGUM-TASK-ORCHESTRATION-IMPLEMENTATION.md` — Original Phase 1–3 plan
- `RALPH-WIGGUM-CCT-ORCHESTRATION.md` — CCT integration architecture
- `RALPH-WIGGUM-QUICK-REFERENCE.md` — Pattern overview (v2.0.0)
- Industry: Loop Engineering Design Patterns (Data Science Dojo, June 2026)
- Industry: Durable Execution for AI Agent Runtimes (Zylos Research, April 2026)
- Industry: Agentic Loops: From ReAct to Loop Engineering (June 2026 Guide)

---

## Executive Summary

The Ralph Wiggum autonomous orchestration system shipped in v1.1.29 with a solid foundation: all 13 core orchestration PHP tools, four JetEngine CCT schemas, a browser-side autonomous orchestrator with circuit breaker, and LangChain-based ReAct agent loop support. However, the audit revealed **six gaps** that prevent production-grade reliability, observability, and durability. This plan prioritises those gaps against industry standards established by Temporal, LangGraph, OpenAI Agents SDK, and the emerging discipline of **loop engineering**, and maps each to concrete remediation steps.

---

## Industry Standards — What Production Loop Engineering Demands

Before detailing the gaps, here is the industry baseline. The following patterns are considered **table stakes** for autonomous agent loops running in production (sources cross-referenced below):

### Loop Engineering Ten Patterns (Data Science Dojo, June 2026)

| # | Pattern | Relevance to NV oOS Ralph |
|---|---------|--------------------------|
| 1 | **ReAct Loop** | ✅ Already implemented (LangChain orchestrator, tool-use loop) |
| 2 | **Reflection Loop** | ⚠️ Partial — `detect_completion_indicators` does semantic analysis but no structured self-critique |
| 3 | **Tool Use Loop** | ✅ Implemented via tool registry + orchestrator |
| 4 | **Prompt Chaining** | ✅ Implemented via `createSequentialChain()` in LangChain orchestrator |
| 5 | **Ralph Loop** | ⚠️ Partial — exit condition exists but context-reset-per-iteration is missing |
| 6 | **Evaluator-Optimizer** | ❌ Not implemented — no separate evaluator model |
| 7 | **Multi-Agent Supervisor** | ✅ Supported via Agent Roles + Team Orchestrator |
| 8 | **Circuit Breaker** | ⚠️ JS-side only — PHP-side uses transient-based health checks, no persistent breaker |
| 9 | **Heartbeat Loop** | ✅ Partially — Pro Schedule Manager + cron support |
| 10 | **Bounded Execution** | ⚠️ Partial — max_iterations and token_budget exist but not enforced at infra level |

### Durable Execution Primitives (Zylos Research, Temporal, LangGraph, OpenAI Agents SDK)

| Primitive | NV oOS Status |
|-----------|---------------|
| **Run & step journal** (event history) | ❌ CCT registered but not written to |
| **Durable step results** (prevent re-execution) | ❌ No idempotency on tool calls |
| **Durable timers / sleeps** | ⚠️ Session expires_at stored but no persistent timer |
| **External event wait** (human approval) | ❌ No approval gate mechanism |
| **Retry policy** with global budget | ⚠️ JS retry (LangChain) exists; PHP retry not scoped |
| **Idempotency keys** per tool call | ❌ Not implemented |
| **Checkpoint & replay** | ❌ Not implemented |
| **Durable human gates** | ❌ Not implemented |

### Key Industry Takeaways

1. **"Define what 'done' means before the loop starts, using verifiable automated checks not agent self-assessment"** — Loop Engineering Guide, 2026
2. **"Session memory is not durable execution. A transcript is useful context, but it is not a recovery log."** — Zylos Research, 2026
3. **"The biggest trap is believing that checkpoints alone solve durability. They do not."** — Zylos Research, 2026
4. **"Without a circuit breaker, a stuck agent burns tokens indefinitely."** — Loop Engineering Design Patterns, 2026
5. **"Multi-agent systems cost up to 15x more per session than single-agent interactions."** — Loop Engineering Guide, 2026
6. **"Start with the simplest loop that could work, then add complexity only when you can measure the improvement."** — Loop Engineering Design Patterns, 2026

---

## Gap Inventory & Remediation Plan

### Gap 1: Session Storage — Transients → Persistent CCT ✅ → 🔧

**Current state:** `manage_autonomous_session` stores all session data in WordPress transients (`mcp_ai_session_{uuid}`) with a 24-hour TTL. The `mcp_autonomous_sessions` CCT is registered and ready but unused. Code self-documents this: *"For now, store in transients (24h TTL). In Phase 2, we'll use CCT or custom table."*

**Why this matters (industry context):**
- Transients are **volatile** — they can be evicted by object cache pressure, `wp_cache_flush()`, or database optimisations. A 20-hour autonomous session can lose all state on a cache clear.
- Transients have **no query surface** — you cannot list active sessions, filter by health, or aggregate metrics without loading every key. The CCT provides admin columns, REST endpoints, and JetEngine query builders.
- WordPress core's own Real-Time Collaboration project (May 2026) recommended **custom-table-with-transients** as the preferred hybrid strategy — transients for hot reads, custom tables for durable state.
- The industry standard (Temporal, LangGraph, OpenAI Agents SDK) is **durable step journaling**, not ephemeral key-value. Every meaningful operation boundary should be persisted.

**Remediation steps:**

| Step | Description | Effort | Priority |
|------|-------------|--------|----------|
| 1.1 | Add `store_session_cct()` method to `manage_autonomous_session` tool, writing all 17 CCT fields | 4h | P0 |
| 1.2 | Add a `cct_available()` guard with transparent transient fallback | 1h | P0 |
| 1.3 | Migrate `check_exit_conditions`, `analyze_loop_health`, `get_session_status` to read from CCT first, transient fallback second | 3h | P0 |
| 1.4 | Add `WP_MCP_AI_Autonomous_Sessions_CCT::cleanup_expired()` for expired session pruning | 2h | P1 |
| 1.5 | Add `WP_MCP_AI_Autonomous_Sessions_CCT::get_sessions_by_status()` for dashboard queries | 2h | P1 |
| 1.6 | Write migration routine to hydrate CCT from existing transients on upgrade | 3h | P1 |

**Target:** Sessions survive cache flushes, server restarts, and persist beyond 24 hours. Admin can query, filter, and monitor all sessions.

---

### Gap 2: Execution History CCT — Schema Exists, Never Written To ❌ → 🔧

**Current state:** `mcp_execution_history` CCT is registered with rich fields (session_id, iteration, tool_name, success, duration_ms, tokens_used, input_summary, output_summary, executed_at) but no orchestration tool writes to it. Each tool call, each iteration, each health check produces zero durable audit trail.

**Why this matters (industry context):**
- The Run & Step Journal is **the single most important primitive** in durable agent execution. Temporal's event history, LangGraph's checkpoints, and Inngest's step memoization all serve the same purpose: reconstruct what happened.
- Without it, debugging a failed 50-iteration session means reading raw chat transcripts. With it, you query: "show me every tool that returned an error in session X."
- **Observability is not durability.** Traces explain what happened. A durable journal decides what may be replayed, skipped, or compensated (Zylos Research, 2026).

**Remediation steps:**

| Step | Description | Effort | Priority |
|------|-------------|--------|----------|
| 2.1 | Create a shared `WP_MCP_AI_Execution_Logger` utility class with `log_tool_call(session_id, tool_name, success, duration_ms, tokens, input_summary, output_summary, error_message)` | 3h | P0 |
| 2.2 | Add a call to the logger at the end of every orchestration tool's `execute()` method | 4h | P0 |
| 2.3 | Add `get_session_history()`, `get_error_summary()`, `get_tool_frequency()` query methods | 3h | P1 |
| 2.4 | Add `purge_old_history($days)` for retention policy (default: 30 days) | 1h | P1 |

**Target:** Every tool call within an autonomous session produces a durable, queryable record. The admin dashboard can render per-session tool-call timelines.

---

### Gap 3: Missing `create_execution_prompt` Tool ❌ → 🔧

**Current state:** The original proposal defines this as tool #4 of 8. It generates structured `PROMPT.md` per iteration with objective, current task, available tools, constraints, success criteria, and exit signal instructions. **Not found** in `tools/orchestration/`.

**Why this matters (industry context):**
- The Ralph Loop pattern's core innovation is the **context-reset per iteration**: each loop reads a fresh prompt from disk rather than accumulating context-window degradation.
- Without structured iteration prompts, the agent's context grows monotonically. After 15+ iterations without a reset, output quality degrades (context overflow — one of the five canonical failure modes in loop engineering).
- Claude Code's `/goal` and Codex CLI's `/goal` commands both take an explicit, verifiable goal statement upfront. The execution prompt serves the same function at iteration granularity.

**Remediation steps:**

| Step | Description | Effort | Priority |
|------|-------------|--------|----------|
| 3.1 | Create `class-wp-mcp-ai-pro-tool-create-execution-prompt.php` with schema: `plan_id`, `iteration_number`, `previous_result`, `constraints` | 4h | P0 |
| 3.2 | Template engine: dynamic sections for objective (from plan), current task (next unchecked), available tools (assistant capabilities), constraints (remaining budget/iterations), success criteria (from plan) | 3h | P0 |
| 3.3 | Generate markdown output matching the proposed template format with explicit `EXIT_SIGNAL` instructions | 2h | P0 |
| 3.4 | Integrate with `get_task_plan` to read current task and `check_exit_conditions` for budget/token warnings in constraints section | 2h | P1 |

**Target:** Each autonomous iteration receives a fresh, scoped prompt with explicit success criteria and exit instructions, preventing context-window degradation.

---

### Gap 4: Orchestration Dashboard — Data Source Migration & Enhancement ⚠️ → 🔧

**Correction (2026-07-01):** The orchestration dashboard **does exist** at `addons/pro/includes/admin/class-wp-mcp-ai-orchestration-dashboard.php` (850 lines, slug `mcp-ai-orchestration-pro`). It provides overview cards, capacity analysis (Little's Law), system status (cron, async, SSE, health), active sessions table, team workflows table, recent activity feed, and AJAX endpoints for dashboard data and session control. **However**, it reads session data directly from WordPress transients via raw `$wpdb` queries against `wp_options WHERE option_name LIKE '_transient_mcp_ai_session_%'`, and the tool-execution count is a hardcoded placeholder (`$active_sessions * 5`). There is no link from autonomous sessions to the `ai_chat_transcripts` CCT records they produced.

**What the dashboard already has (✅):**
- Overview cards: active sessions, task plans, tool executions, system health
- Capacity Analysis: Little's Law (utilization, queue length, load status)
- System Status: cron jobs, async ops health, SSE connectivity, orchestration health
- Active Sessions table: session ID, plan, status, health, progress, iterations, tokens, elapsed, actions
- Team Workflows table: workflow ID, team, task type, state, age, tasks, created, actions
- AJAX endpoints: `ajax_get_dashboard_data`, `ajax_control_session`, `ajax_trigger_workflow`
- Auto-refresh via JS polling

**What's missing / needs fixing (🔧):**
- `get_active_sessions()` (line 582) and `get_overview_metrics()` (line 500) query `wp_options` for transient keys — fragile, slow, and redundant since the CCT exists
- Tool execution count is `$active_sessions * 5` placeholder (line 529) — `mcp_execution_history` CCT has real data once Gap 2 is done
- No "View Transcripts" link from autonomous session → `ai_chat_transcripts` CCT filtered by `session_key`
- No session detail drill-down view (per-session iteration timeline with tool-call log)
- No circuit breaker status column in the sessions table (field exists in CCT, not rendered)
- **Discovery gap:** The PM Command Center (`nvoos-pm-command-center`, 10 tabs: overview/projects/tasks/events/analytics/PARA/risk/workflows/templates/configuration) does not surface autonomous orchestration at all — no task plans tab, no autonomous sessions KPI, no link to the Orchestration Monitor. Users discover task plans only through the AI chat interface or toolkit settings page.

**Why this matters (industry context):**
- The transient data source means sessions can disappear from the dashboard on cache flush — a "phantom healthy" problem where the dashboard shows 0 active sessions but sessions are actually running
- WordPress core's RTC project (May 2026) validated custom-table + transients as the recommended hybrid; the dashboard should read from the durable store (CCT) with transient as a hot-read cache
- Loop engineering's Pattern 8 (Circuit Breaker) requires visibility of breaker state — field exists in CCT but not displayed

**Remediation steps:**

| Step | Description | Effort | Priority |
|------|-------------|--------|----------|
| 4.1 | Migrate `get_active_sessions()` and `get_overview_metrics()` to read from `WP_MCP_AI_Autonomous_Sessions_CCT` with transient fallback | 3h | P0 |
| 4.2 | Add circuit breaker status column to the sessions table (reads `circuit_breaker_open` from CCT) | 1h | P0 |
| 4.3 | Replace placeholder tool-execution count with real query from `WP_MCP_AI_Execution_History_CCT` | 2h | P1 |
| 4.4 | Add "View Transcripts" link in session actions column → `admin.php?page=jet-cct-ai_chat_transcripts` filtered by `session_key` | 2h | P1 |
| 4.5 | **Session Detail view:** Per-session drill-down with iteration timeline, tool-call log (from execution history CCT), health events, exit condition checks | 4h | P1 |
| 4.6 | **PM Command Center integration:** Add "Autonomous Sessions" overview card to the PM Command Center overview tab with active session count and link to Orchestration Monitor; add "Task Plans" to the tab list showing plan status and progress | 3h | P1 |
| 4.7 | **Task Plans management tab:** List/create/edit task plans in PM Command Center with markdown preview, progress bars | 4h | P2 |

**Target:** Dashboard reads from durable CCT storage, shows real tool-execution counts, links autonomous sessions to their chat transcripts, and displays circuit breaker state. Session detail view enables per-session debugging.

---

### Gap 5: Tool Return Format Compliance — `success:false` Arrays → `WP_Error` ⚠️ → 🔧

**Current state:** Several orchestration tools return `array('success' => false, 'error' => 'message')` instead of `WP_Error`. This violates the canonical envelope rule in `CLAUDE.md`:

> *"Tool `execute()` returns the canonical envelope (success array or `WP_Error`, **never** `array('success' => false, ...)`)"*

Two custom PHPCS sniffs (`WPMCPAI.Tools.CanonicalReturnEnvelope`, `WPMCPAI.Tools.SanitizeAtEntry`) enforce this at severity 5.

**Affected tools (partial list):**
- `create_task_plan` — returns `array('success' => false, 'error' => ...)`
- `manage_autonomous_session` — returns `array('success' => false, 'error' => ...)`
- `check_exit_conditions` — returns `array('success' => false, 'error' => ...)`
- `detect_completion_indicators` — returns `array('success' => false, 'error' => ...)`

**Remediation steps:**

| Step | Description | Effort | Priority |
|------|-------------|--------|----------|
| 5.1 | Audit all 33 orchestration tools for return format violations | 2h | P0 |
| 5.2 | Convert all `array('success' => false, 'error' => ...)` to `new WP_Error('error_code', 'message')` | 3h | P0 |
| 5.3 | Add `WP_Error` → `array('success' => false, ...)` adapter in tool-call dispatch layer so downstream consumers aren't broken | 2h | P0 |
| 5.4 | Run `composer run lint` and fix PHPCS violations | 1h | P0 |

**Target:** All orchestration tools pass the `WPMCPAI.Tools.CanonicalReturnEnvelope` sniff. Downstream consumers are insulated by an adapter during the transition.

---

### Gap 6: PHP-Side Circuit Breaker & No-Progress Detection ⚠️ → 🔧

**Current state:** The browser-side `autonomous-orchestrator.js` has a full circuit breaker with CLOSED/OPEN/HALF_OPEN states, error thresholds, and reset timeouts. The PHP-side `analyze_loop_health` tool performs health analysis (stuck loop, error cascade, resource pressure) but does **not enforce** a circuit breaker — it only *reports* whether one *should* open. The actual circuit breaker state lives in the transient session and is never acted upon by the loop execution itself.

**Why this matters (industry context):**
- The circuit breaker is Pattern 8 of 10 in the loop engineering design patterns. It's considered **non-negotiable** for production autonomous loops.
- *"Without a circuit breaker, a stuck agent burns tokens indefinitely. This pattern directly addresses one of the most expensive failure modes in loop engineering."* — 10 Loop Engineering Design Patterns, June 2026
- The canonical Ralph Loop implementations (`frankbria/ralph-claude-code`, `ralph-orchestrator`) all include **circuit breakers with advanced error detection** as a core feature.

**Remediation steps:**

| Step | Description | Effort | Priority |
|------|-------------|--------|----------|
| 6.1 | Create `WP_MCP_AI_Circuit_Breaker` PHP class with CLOSED/OPEN/HALF_OPEN states, configurable thresholds, persistent state in CCT | 4h | P0 |
| 6.2 | Add `no_progress_detection` to `analyze_loop_health`: compare file states, task completions, or output hashes across N consecutive iterations; trip if stagnant | 3h | P0 |
| 6.3 | Wire circuit breaker into the autonomous loop execution path: chat service checks breaker state before dispatching next iteration; OPEN state returns early with a structured error | 3h | P0 |
| 6.4 | Add `configure_circuit_breaker` tool (proposed but missing): allow admin/agent to adjust sensitivity thresholds | 2h | P2 |
| 6.5 | Add `get_loop_metrics` tool (proposed but missing): expose success rate, avg iteration duration, tool-call frequency, error distribution | 3h | P2 |

**Target:** A stuck or failing session is automatically halted by the PHP runtime, not just reported. The circuit breaker state is durable (persists across requests) and visible in the admin dashboard.

---

## Gap 7 (New): Durable Execution — Idempotency & Checkpointing ❌ → 📋

> **This gap was identified during industry research and was not in the original proposal. It represents the next maturity level beyond the six immediate gaps.**

**Current state:** No tool in the orchestration system is idempotent. If a tool call succeeds but the PHP process crashes before the result is recorded, a retry will execute the tool again — potentially sending a duplicate email, creating a duplicate post, or charging an API twice. There is no checkpoint/replay mechanism.

**Why this matters (industry context):**
- Durable execution is **the bridge between memory and autonomy** (Zylos Research, 2026). Without it, autonomous agents are not safe for production workloads that involve side effects.
- The five high-risk operations that require idempotency: shell commands, file/database writes, pull request creation, email/notification sends, and human approvals. NV oOS tools do all of these.
- Temporal, Restate, Inngest, LangGraph, and Azure Durable Task all provide checkpointing as a first-class primitive. The industry is converging on this as table stakes.

**Remediation steps:**

| Step | Description | Effort | Priority |
|------|-------------|--------|----------|
| 7.1 | Add `idempotency_key` parameter to the base tool interface (optional, auto-generated from session_id + iteration + tool_name) | 3h | P3 |
| 7.2 | Create `WP_MCP_AI_Tool_Receipt` CCT or table to store idempotency receipts: key, tool, result_hash, executed_at. Tools check receipt before executing. | 4h | P3 |
| 7.3 | Add `checkpoint_save` and `checkpoint_restore` methods to `manage_autonomous_session`, serialising full session state to CCT | 4h | P3 |
| 7.4 | Implement outbox pattern for notification/email tools: write intent to outbox, dispatch separately | 6h | P3 |

**Target:** Idempotent tool execution prevents duplicate side effects. Session checkpointing enables pause → resume across server restarts.

---

## Prioritised Implementation Roadmap

### Wave 1: Storage & Observability (P0 — 2 weeks, ~31h)

These three gaps are interdependent: the dashboard needs CCT data, the CCT needs to be populated.

| Gap | Steps | Hours |
|-----|-------|-------|
| **Gap 1:** Session storage CCT migration | 1.1–1.6 | 15h |
| **Gap 2:** Execution history logging | 2.1–2.4 | 11h |
| **Gap 4:** Dashboard data source migration | 4.1–4.2 (CCT + breaker column) | 4h |

**Deliverable:** Sessions persist in CCT. Every tool call is logged. Dashboard reads from durable CCT storage instead of transients. Circuit breaker status visible.

### Wave 2: Loop Correctness & Safety (P0 — 1.5 weeks, ~23h)

These close the loop on the autonomous execution path.

| Gap | Steps | Hours |
|-----|-------|-------|
| **Gap 6:** PHP circuit breaker + no-progress detection | 6.1–6.3 | 10h |
| **Gap 3:** `create_execution_prompt` tool | 3.1–3.4 | 11h |
| **Gap 5:** Tool return format compliance | 5.1–5.4 | 8h |

**Deliverable:** Fresh iteration prompts with budget context. Circuit breaker enforced server-side. All tools pass canonical-envelope sniff.

### Wave 3: Dashboard Polish & Advanced Tools (P1–P2 — 2 weeks, ~28h)

| Gap | Steps | Hours |
|-----|-------|-------|
| **Gap 4:** Real execution counts + transcript linking + PM Command Center integration + session drill-down + task plans tab | 4.3–4.7 | 13h |
| **Gap 6:** `configure_circuit_breaker` + `get_loop_metrics` tools | 6.4–6.5 | 5h |
| Cross-cutting: Integration tests for autonomous session lifecycle | — | 8h |
| Cross-cutting: Documentation updates | — | 3h |

**Deliverable:** Full-featured dashboard with drill-down. Additional orchestration tools. Test coverage.

### Wave 4: Durable Execution (P3 — Future, ~17h)

| Gap | Steps | Hours |
|-----|-------|-------|
| **Gap 7:** Idempotency keys + tool receipts | 7.1–7.2 | 7h |
| **Gap 7:** Checkpoint save/restore | 7.3 | 4h |
| **Gap 7:** Outbox pattern | 7.4 | 6h |

**Deliverable:** Safe autonomous execution with side-effect protection. Session checkpointing across restarts.

---

## Summary: Current vs Target State

| Dimension | Current (v1.1.29) | After Wave 1–3 | After Wave 4 |
|-----------|-------------------|----------------|--------------|
| **Session storage** | Transients only | CCT primary, transient fallback | CCT + checkpoints |
| **Execution audit** | No durable log | Every tool call logged to CCT | Log + idempotency receipts |
| **Circuit breaker** | JS-only advisory | PHP-enforced, CCT-persisted | PHP + JS dual enforcement |
| **Iteration prompts** | None — context accumulates | Fresh per-iteration PROMPT.md | Fresh + checkpoint-aware |
| **Admin visibility** | Settings page help text | Full dashboard (sessions, plans, health) | Dashboard + alerting |
| **Tool return format** | Mixed `WP_Error` / arrays | All canonical `WP_Error` | Canonical + typed errors |
| **No-progress detection** | Not implemented | Stagnation trips circuit breaker | Semantic no-progress analysis |
| **Durable execution** | None | — | Idempotency + checkpoints + outbox |

---

## Decision Log

### 2026-07-01 — CCT over Custom Table for Session Storage

**Decision:** Use the existing JetEngine CCT (`mcp_autonomous_sessions`) for session storage rather than the custom table proposed in the original plan.

**Rationale:**
- CCT is already registered, field schema fully defined (17 fields), REST-enabled, and admin-column-configured.
- Pro addon already depends on JetEngine CCT for task plans, templates, and execution history — adding a separate custom table creates two storage patterns to maintain.
- JetEngine CCT provides built-in query builder, REST API, CSV export, and admin UI at zero additional code cost.
- WordPress core's RTC project (May 2026) validated "custom-table-with-transients" as the recommended hybrid. CCT tables *are* custom tables with JetEngine's ORM on top.

### 2026-07-01 — `create_execution_prompt` is P0 Despite Being "Missing"

**Decision:** Elevate this tool from the original Phase 1 plan (which shipped without it) to P0 now.

**Rationale:**
- Context-window degradation is one of the five canonical loop failure modes. The Ralph Loop pattern's defining innovation is the per-iteration context reset.
- Without structured iteration prompts, NV oOS autonomous sessions degrade in the same way AutoGPT did — monotonically growing context that eventually produces garbage.
- The tool is straightforward to implement (template engine filling markdown from existing plan data) but has outsized impact on reliability.

### 2026-07-01 — Durable Execution (Gap 7) Deferred to Wave 4

**Decision:** Defer idempotency, checkpointing, and outbox pattern to a future wave.

**Rationale:**
- The six immediate gaps represent missing basic infrastructure. Building idempotency on top of a system that doesn't yet log execution history is premature.
- Durable execution is complex and carries architectural risk — the tool interface contract changes, every mutating tool needs updating, and replay semantics interact with WordPress's request lifecycle.
- Waves 1–3 deliver the observability and safety foundation that makes durable execution testable.

---

## Appendix: Files to Create / Modify

### New Files
| File | Gap |
|------|-----|
| `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-create-execution-prompt.php` | Gap 3 |
| `addons/pro/includes/class-wp-mcp-ai-execution-logger.php` | Gap 2 |
| `addons/pro/includes/class-wp-mcp-ai-circuit-breaker.php` | Gap 6 |
| `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-configure-circuit-breaker.php` | Gap 6 |
| `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-get-loop-metrics.php` | Gap 6 |

### Modified Files
| File | Gap | Nature |
|------|-----|--------|
| `addons/pro/includes/admin/class-wp-mcp-ai-orchestration-dashboard.php` | Gap 4 | Migrate session data source from transients to CCT; add circuit breaker column; add transcript links |
| `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-manage-autonomous-session.php` | Gap 1 | Add CCT storage path |
| `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-check-exit-conditions.php` | Gap 1, 5 | Read from CCT, WP_Error |
| `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-analyze-loop-health.php` | Gap 1, 6 | Read from CCT, enforce breaker |
| `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-create-task-plan.php` | Gap 5 | WP_Error compliance |
| `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-detect-completion-indicators.php` | Gap 5 | WP_Error compliance |
| `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-get-session-status.php` | Gap 1 | Read from CCT |
| `addons/pro/includes/class-wp-mcp-ai-autonomous-sessions-cct.php` | Gap 1 | Add query methods |
| All 33 orchestration tools | Gap 2 | Add execution logging call |

### No Changes Needed
| Page / File | Reason |
|-------------|--------|
| `admin.php?page=jet-cct-ai_chat_transcripts` (JetEngine CCT listing) | Fully functional auto-generated JetEngine CCT admin page. Stores user-facing chat transcripts (session_key, request/response payloads, latency, metadata). Used by ChatKit, Elementor widgets, Graphify bridge, and Multi-Agent Dashboard. Separate data domain from autonomous sessions. |
| `includes/class-wp-mcp-ai-jetengine-cct.php` | CCT registration for `ai_chat_transcripts` is complete and stable. No autonomous-session fields needed here. |

---

**Plan Author:** AI Agent (Zed Coding Agent — DeepSeek V4 Pro)  
**Reviewed Against:** Industry standards from Temporal, LangGraph, OpenAI Agents SDK, Restate, Inngest, Azure Durable Task, Loop Engineering Design Patterns (2026)  
**Date:** 2026-07-01
