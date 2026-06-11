# NV oOS Orchestration — Documentation Hub

> **Phase 7 Gap-Fill — Canonical Navigation Index**  
> Last Updated: May 2026 · Plugin Version: 1.1.15+

NV oOS (Open Operator System) is a WordPress-native AI orchestration platform that connects 9 language-model providers to an extensible tool registry through the Model Context Protocol (MCP), the Agent-to-Agent (A2A) protocol, Server-Sent Events streaming, and a 6-agent hierarchical supervisor pattern. It runs entirely inside WordPress — no separate Python runtime, no external cloud infrastructure, and no additional managed service required. Every feature on this page is deployed as a standard WordPress plugin on PHP 7.4+ infrastructure; see [`CLAUDE.md`](../CLAUDE.md) for the canonical PHP-compatibility and naming rules.

> **This document is a navigation hub.** It consolidates links to every orchestration-relevant doc without duplicating their content. For the deep-dive infrastructure reference — workflow presets, resource presets, PSO optimizer, health monitoring, budget enforcement, hooks, and data-storage keys — see [`docs/ORCHESTRATION_REFERENCE.md`](ORCHESTRATION_REFERENCE.md).

---

## Table of Contents

1. [Core Infrastructure](#1-core-infrastructure)
2. [Feature Documentation](#2-feature-documentation)
3. [Phase 1 — Observability](#3-phase-1--observability)
4. [Phase 2 — Human-in-the-Loop (HITL)](#4-phase-2--human-in-the-loop-hitl)
5. [Pro Workflow Builder ↔ Base Orchestration Bridge](#5-pro-workflow-builder--base-orchestration-bridge)
6. [Roadmap: Phases 4–6](#6-roadmap-phases-46)
7. [Further Reading](#7-further-reading)

---

## 1. Core Infrastructure

| Document | What You'll Find |
|---|---|
| [Orchestration Layer Reference](ORCHESTRATION_REFERENCE.md) | Workflow presets, resource presets, PSO adaptive optimization, tool execution orchestrator, load balancer, reasoning controller, multi-agent system, health monitoring, budget enforcement, hooks/filters, data-storage keys, admin UI |
| [Architecture Overview](architecture/ARCHITECTURE.md) | System overview, 9-provider LLM router, 34 REST controllers, tool layer (~830 tools; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative), data flow, key design patterns |
| [Request Flow Walkthrough](architecture/REQUEST-FLOW-WALKTHROUGH.md) | End-to-end trace of a chat message: `sendChat()` → REST → auth → SSE → LLM router → agentic loop (up to 15 iterations) → tool execution → response |
| [LLM Harness](llm-harness.md) | Seven opt-in per-request layers (Prompt/Cue, Reasoning, Tool Routing, Retrieval, Self-Refine, Memory Scoping, Evaluation) — the *epistemic* quality layer that sits on top of, and delegates to, the orchestration layer |

---

## 2. Feature Documentation

| Document | What You'll Find |
|---|---|
| [Orchestration Modes](features/orchestration/ORCHESTRATION-MODES-DISPLAY-ENHANCEMENT.md) | Display enhancements and visual comparison of all orchestration modes |
| [Multi-Agent System](features/multi-agent/README-MULTI-AGENT-SYSTEM.md) | 6-agent hierarchical supervisor pattern (Orchestrator → Research → Parser → Drafter → Auditor → Publisher), preconfigured assistants, dashboard, workflow visualization |
| [Chat-client Memory Bridge](features/memory/chat-client-integration.md) | REST proxy (`/mcp-ai/v1/chat-memory/`), Memory Drawer (Memories / Scope / Audit tabs), `memory_event` SSE frames, per-user and site-wide memory gates |
| [Agent Memory Limits](features/memory/memory-limits.md) | Per-assistant memory quotas, retention cron, `wp_mcp_ai_metric_retention_days` filter |
| [Transcript Mining](features/memory/transcript-mining.md) | Retroactive mining of chat transcripts into agent memory, background job, REST API (3 endpoints), provenance metadata, de-duplication |
| [Agent Skills](features/agent-skills.md) | Portable `SKILL.md` behaviour packages, 44+ bundled base skills, Pro skill packs, progressive disclosure, remote catalogues (`mcp-ai-pro/v1/catalogues/*`) |
| [Slash Commands](slash-commands-guide.md) | `/help`, `/ship`, `/compact`, `/context`, and all built-in commands; syntax, flags, registration API |
| [Pro Workflow Builder](pro-workflow-builder.md) | React-based visual workflow DAG editor (Pro); designing multi-step automations with branching and conditions |
| [Measurement Subsystem](measurement/README.md) | Stock metrics, persistent metric event store (`wp_mcp_ai_metric_events`), eval harness, OTel JSON exporter, Measurement dashboard (**Tools → Measurement**), WP-CLI runner |

---

## 3. Phase 1 — Observability

*Shipped as part of the Phase 1 orchestration gap-fill. All three capabilities are in the base plugin unless noted.*

### Run Timeline

An admin page under **NV oOS → Run Timeline** surfaces a chronological, per-run trace of every agentic loop execution: tool calls made, tokens consumed at each step, errors encountered, and wall-clock duration. Data is captured automatically for every assistant run — no configuration required.

### Layer I: Prompt Injection Detector

A harness layer that inspects incoming user messages for prompt-injection patterns before the request reaches the LLM.

- **Default:** Off.
- **Enable:** Open the assistant edit screen, find the **LLM Harness** metabox, and set `injection_detector.enabled = true` in the harness profile. You can also enable it programmatically via the `wp_mcp_ai_harness_profile` filter:

```php
add_filter( 'wp_mcp_ai_harness_profile', function( $profile, $assistant_id ) {
    $profile['injection_detector']['enabled'] = true;
    return $profile;
}, 10, 2 );
```

When the detector fires it blocks execution and returns a `WP_Error` with code `injection_detected`. Flagged requests are surfaced in the Run Timeline. The layer is strictly opt-in to preserve existing behaviour — see [`docs/llm-harness.md`](llm-harness.md) for a full description of the harness profile schema and all available keys.

### Structured Output Guardrail

A post-generation layer that validates LLM output against a declared JSON Schema before the response is streamed to the client. Non-conforming responses are re-prompted up to the harness retry cap before returning a structured error.

- **Default:** Off.
- **Enable:** Set `structured_output.enabled = true` in the harness profile. Pair it with a `structured_output.schema` key holding the JSON Schema the model must conform to:

```php
add_filter( 'wp_mcp_ai_harness_profile', function( $profile, $assistant_id ) {
    $profile['structured_output']['enabled'] = true;
    $profile['structured_output']['schema']  = array(
        'type'       => 'object',
        'properties' => array(
            'title'   => array( 'type' => 'string' ),
            'summary' => array( 'type' => 'string' ),
        ),
        'required' => array( 'title', 'summary' ),
    );
    return $profile;
}, 10, 2 );
```

### OTel Span Exporter

NV oOS exports OpenTelemetry spans for every agentic loop iteration, tool execution, and LLM call to any OTLP-compatible backend (Jaeger, Grafana Tempo, Honeycomb, Datadog, etc.). Spans carry `assistant_id`, `tool_name`, `iteration`, `tokens_prompt`, `tokens_completion`, and `duration_ms` attributes.

**Configure via WordPress option** (persisted, applied at runtime):

```php
update_option( 'wp_mcp_ai_otel_endpoint', 'https://otel-collector.example.com:4318/v1/traces' );
```

**Configure via environment variable** (takes precedence over the option):

```bash
WP_MCP_AI_OTEL_ENDPOINT=https://otel-collector.example.com:4318/v1/traces
```

For the full OTel exporter reference — payload format, batch size, flush interval, and the `wp_mcp_ai_otel_span` filter — see [`docs/measurement/README.md`](measurement/README.md).

---

## 4. Phase 2 — Human-in-the-Loop (HITL)

*Shipped as part of the Phase 2 orchestration gap-fill. Available in the base plugin.*

Human-in-the-Loop (HITL) lets an assistant pause its agentic loop and wait for explicit human sign-off before proceeding with a potentially destructive or irreversible action — publishing content, sending a message, modifying records, or calling an external API with side effects.

### `request_user_approval` Tool

The primary HITL entry point. When the LLM calls this tool, the agentic loop is suspended and an approval request is enqueued for human review. The loop resumes automatically when a reviewer acts, or times out if no action is taken.

**Tool slug:** `request_user_approval`

**Parameters:**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `action_label` | `string` | ✅ | Short human-readable label for the action (e.g. `"Publish post #42 to production"`) |
| `context` | `object` | — | Structured context surfaced in the Approvals admin UI (`tool`, `arguments`, `rationale`) |
| `timeout_seconds` | `integer` | — | Seconds before the request auto-expires. Default: `300`. Max: `3600`. |

**Return values:**

- **Approved:** `{ "approved": true, "approver": "<user_login>", "approved_at": "<ISO 8601>" }`
- **Rejected:** `{ "approved": false, "reason": "<reviewer note>" }`
- **Timed out:** `WP_Error` with code `approval_timeout`

### `WP_MCP_AI_Approval_Queue`

The server-side service that manages approval request lifecycle: enqueue, list, approve, reject, and expire. You can call it directly from PHP when you need to drive approvals outside the chat interface (e.g. from a custom REST endpoint or a WP-CLI command):

```php
// Enqueue an approval request programmatically.
$queue      = WP_MCP_AI_Approval_Queue::get_instance();
$request_id = $queue->enqueue( array(
    'assistant_id' => 42,
    'action_label' => 'Send Slack notification to #general',
    'context'      => array(
        'tool'      => 'send_slack_message',
        'channel'   => '#general',
        'rationale' => 'User asked to notify the team about the deployment.',
    ),
) );

// Approve programmatically (e.g. from a custom workflow or CLI command).
$queue->approve( $request_id, get_current_user_id() );

// Reject with a reason.
$queue->reject( $request_id, get_current_user_id(), 'Not the right channel — use #deployments.' );
```

### REST Endpoints (`/mcp-ai/v1/approvals/*`)

All endpoints require a valid WordPress nonce (`X-WP-Nonce` header) and the `manage_options` capability. See [`.context/rest-api.md`](../.context/rest-api.md) for the REST authentication pattern.

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/mcp-ai/v1/approvals` | List all pending approval requests |
| `GET` | `/mcp-ai/v1/approvals/{id}` | Get a single approval request |
| `POST` | `/mcp-ai/v1/approvals/{id}/approve` | Approve a request |
| `POST` | `/mcp-ai/v1/approvals/{id}/reject` | Reject a request (optional `reason` body field) |
| `DELETE` | `/mcp-ai/v1/approvals/{id}` | Delete / expire a request |

### Admin Page: NV oOS → Approvals

A real-time admin screen that lists pending, approved, and rejected requests. Reviewers can inspect the full context (tool name, arguments, rationale provided by the model), approve or reject with an optional written note, and watch the suspended run resume automatically on approval. Access it at:

**WordPress admin → NV oOS → Approvals**

---

## 5. Pro Workflow Builder ↔ Base Orchestration Bridge

The Pro Workflow Builder (`addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php` + the React UI under `addons/pro/build/workflow-builder/`) is the canonical authoring surface for Pro sites. It stores workflows as keyed entries in the WordPress option `wp_mcp_ai_pro_workflows` and per-workflow execution history in `wp_mcp_ai_workflow_executions_{workflow_id}`.

The base orchestration primitives (Phases 1, 2, 4, 5) plug into the Pro builder through a thin bridge service (`WP_MCP_AI_Pro_Workflow_Bridge`, in `addons/pro/includes/services/`) and four hooks added to the Pro builder:

| Hook | Kind | Wired by bridge to |
|---|---|---|
| `wp_mcp_ai_workflow_execute_agent` | filter | Phase 1 — runs `WP_MCP_AI_Prompt_Injection_Detector::analyze()` on the agent prompt and short-circuits with a `WP_Error` when `flagged && (block-on-detect set)`. |
| `wp_mcp_ai_pro_workflow_pre_execute_tool` | filter | Phase 2 — when the tool advertises the `requires-approval` capability flag, enqueues a HITL approval via `WP_MCP_AI_Approval_Queue::enqueue()` and returns `status=awaiting_approval` instead of executing. |
| `wp_mcp_ai_pro_workflow_node_executed` | action | Phase 4 — appends a `node_completed` / `node_failed` event to `WP_MCP_AI_Workflow_Run_CPT`, lazily creating the run on the first node and tying it to the front-end's `execution_id`. |
| `wp_mcp_ai_pro_workflow_execution_saved` | action | Phase 4 — appends a final `execution_summary` event and calls `WP_MCP_AI_Workflow_Run_CPT::set_status()` with the terminal status (`completed` / `failed` / `cancelled`). |

Because the Pro builder uses string workflow IDs (sanitize-key form) and the base run-log expects an int post ID, the mirrored run records use `workflow_id = 0` and stash the original Pro string ID in the run's `context.pro_workflow_id` along with `pro_execution_id`. Consumers of `/mcp-ai/v1/orchestration/runs/*` and the Run Timeline admin page can correlate the two systems through that context.

### Future TODOs — Phase 3 / Engine V2 enhancements

The Phase 3 base classes (`WP_MCP_AI_Workflow_CPT`, `WP_MCP_AI_Workflow_Engine_V2`, `WP_MCP_AI_Admin_DAG_Builder`, and the `mcp-ai/v1/orchestration/workflows/*` REST routes) ship as a complete vanilla-SVG "lite" workflow surface. The bridge above gives the Pro builder durability, HITL, and observability today; the items below converge the two stores so the Pro builder eventually becomes the only authoring UI:

- [ ] **Native CCT-backed Pro workflow store** — migrate the `wp_mcp_ai_pro_workflows` option into a JetEngine CCT (or the base `mcp_ai_workflow` CPT) so workflows benefit from revisions, RBAC, and import/export round-tripping.
- [x] **Single canonical authoring surface** — when the Pro bridge is active it removes the base "DAG Builder" submenu so site owners see only the Pro Workflow Builder. Re-enable the base UI with `add_filter( 'wp_mcp_ai_show_base_dag_builder', '__return_true' );`. (`addons/pro/includes/services/class-wp-mcp-ai-pro-workflow-bridge.php::maybe_remove_base_dag_builder()`)
- [ ] **Shared import/export schema** — converge the base `workflow.json` round-trip with the Pro builder's JSON export so a workflow authored in either UI is portable to the other.
- [ ] **Engine V2 ↔ Pro presets** — let `WP_MCP_AI_Pro_Workflow_Presets` instantiate workflows directly into the unified store so presets benefit from semver auto-bump and post-revisions.
- [x] **Trigger → executor handoff** — the trigger CPT and replay tool now route through `WP_MCP_AI_Workflow_Dispatcher::dispatch()`, which fires the `wp_mcp_ai_workflow_executor` filter (Pro and third-party executors register here) before falling back to `WP_MCP_AI_Workflow_Engine_V2::execute()`. The Pro bridge registers a handler that recognises string-keyed Pro workflow IDs and currently returns `WP_Error( 'pro_workflow_client_only' )` until a server-side traversal is built. (`includes/class-wp-mcp-ai-workflow-dispatcher.php`)
- [x] **Replay tool — pluggable executor** — `replay_workflow_run` no longer hard-depends on `WP_MCP_AI_Workflow_Engine_V2::execute()`; it dispatches through the same `wp_mcp_ai_workflow_executor` filter as triggers.
- [ ] **Visual DAG parity** — eventually deprecate the base vanilla-SVG canvas in favour of the React UI once Pro coverage matches the base node palette (agent / tool / condition / parallel / approval / loop).

---

## 6. Roadmap: Phases 4–6

| Phase | Title | Summary |
|---|---|---|
| **4** | Durable / Replayable Execution | Checkpoint-based execution so long-running agentic runs survive PHP timeouts, server restarts, and partial tool failures. Replay from any saved checkpoint. Builds on the existing async-jobs infrastructure (`docs/features/async-jobs/`). |
| **5** | Federated Multi-Site Orchestration | Distribute agent runs across a WordPress multisite network or across independent sites via the existing A2A protocol. Planned: central coordinator assistant, per-site sub-agents, and network-wide observability on a single Measurement dashboard. |
| **6** | Adaptive Fine-Tune & Curriculum Export | Harness Layer H (Pro): failure clustering, counterfactual runner output, and JSONL curriculum export for human-reviewed fine-tuning. Extends the existing eval harness and Measurement subsystem. |

---

## 7. Further Reading

| Resource | Description |
|---|---|
| [Platform comparison](comparisons/orchestration-platform-comparison.md) | NV oOS vs LangGraph, CrewAI, AutoGen, OpenAI Agent Builder, n8n-AI — feature matrix with honest notes |
| [10-minute quickstart](quickstart-workflow.md) | Build your first HITL workflow end-to-end |
| [Hooks reference](hooks-reference.md) | All 60+ action and filter hooks |
| [Full documentation index](DOCUMENTATION_INDEX.md) | Comprehensive index of all 1,600+ docs |
| [`CLAUDE.md`](../CLAUDE.md) | Canonical naming, PHP compatibility, and security rules |
| [`.context/conventions.md`](../.context/conventions.md) | Coding conventions, class naming, file organisation |
