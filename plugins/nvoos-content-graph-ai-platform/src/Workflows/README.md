# Workflows

## Purpose

Wave E1 port surface. `WorkflowCpt` is an aligned port of the base
plugin's `WP_MCP_AI_Workflow_CPT`: byte-identical `mcp_ai_workflow` CPT
args, graph/version/tags meta keys with the same sanitize + auth
callbacks, graph read/write helpers, the portable export/import JSON
contract (`schema_version` 1.0), and semver bumping. `WorkflowRunCpt`
is the aligned port of `WP_MCP_AI_Workflow_Run_CPT`: byte-identical
`mcp_ai_workflow_run` CPT args, the ten run meta keys, the run lifecycle
(create → append events → status transitions with terminal
`finished_at` stamping), the event-log read path, and the
four-dimension budget check. `WorkflowTriggerCpt` is the aligned port of
`WP_MCP_AI_Workflow_Trigger_CPT`: byte-identical `mcp_ai_trigger` CPT
args, the five trigger meta keys, enabled-trigger discovery, the six
trigger-type hook bridges, and the fire path with the dispatcher/engine
hand-off seams — the durable storage layer the workflow engine (later
E1 sub-clusters) reads and writes. `Dispatcher` is the aligned port of
the base plugin's `WP_MCP_AI_Workflow_Dispatcher`: byte-identical
`wp_mcp_ai_workflow_executor` filter contract (null = defer,
array|WP_Error = ownership), the default-executor fallback chain
(Engine V2 when available and enabled), and the `no_workflow_executor`
error envelope — the pluggable entry point the trigger CPT, replay
tool, and any workflow consumer call without hard-binding to the
engine. `WorkflowEngine` is the aligned port of
`WP_MCP_AI_Workflow_Engine_V2`: byte-identical enable gate, execution
guards, lifecycle actions, durable run records, graph →
`execute_workflow` tool delegation, and the result envelope — the
default executor the dispatcher hands off to.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::registerWorkflowCpts()` — standalone-only (`! defined('WP_MCP_AI_PATH')`); `Dispatcher` is a static utility with no hooks of its own |
| **Optional dependencies** | None (posts + postmeta only); execution hand-off resolves the dispatcher/engine per install mode |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Workflows\WorkflowCpt` | `WorkflowCpt.php` | `Plugin::registerWorkflowCpts()` — CPT + meta at `init` 12; consumed by the DAG builder (E-UI-2) + engine (E1) |
| `NvoosContentGraphAiPlatform\Workflows\WorkflowRunCpt` | `WorkflowRunCpt.php` | `Plugin::registerWorkflowCpts()` — CPT + meta at `init` 13; consumed by the engine/dispatcher (E1) |
| `NvoosContentGraphAiPlatform\Workflows\WorkflowTriggerCpt` | `WorkflowTriggerCpt.php` | `Plugin::registerWorkflowCpts()` — CPT + meta at `init` 14, trigger hooking at 20; consumed by trigger registry (E1) |
| `NvoosContentGraphAiPlatform\Workflows\Dispatcher` | `Dispatcher.php` | `WorkflowTriggerCpt::fire_trigger()` hand-off (standalone), the replay tool, third-party executors via the `wp_mcp_ai_workflow_executor` filter |
| `NvoosContentGraphAiPlatform\Workflows\WorkflowEngine` | `WorkflowEngine.php` | Static utility — resolved by `Dispatcher::engine_class()` + `WorkflowTriggerCpt::engine_class()` standalone |

## Inputs / Outputs / Neighbors

- **Reads from:** workflow/run/trigger posts + postmeta under the three
  CPT slugs, the `wp_mcp_ai_workflow_executor` filter chain, the
  per-mode engine class (`engine_class()` seam); `wp_mcp_ai_settings`
  (nothing yet — settings land with the engine)
- **Writes to:** workflow graph/version/tags meta, run records + event
  log + budget meta, trigger last-fired timestamps, the
  `wp_mcp_ai_trigger_cron_*` cron events, executor return values
- **Upstream callers:** the workflow engine (E1 later sub-clusters), the
  trigger registry, the DAG builder UI (E-UI-2), future replay/REST
  consumers
- **Downstream consumers:** `wp_mcp_ai_trigger_fired` +
  `wp_mcp_ai_workflow_run_budget_exceeded` + `wp_mcp_ai_workflow_run_*`
  actions; the dispatcher/engine seams (`dispatcher_class()`,
  `engine_class()`) resolve base classes monolith / platform classes
  once they port — null standalone until then (documented no-hand-off
  degradation); `WP_MCP_AI_Workflow_Engine_V2` monolith (boot-gated) /
  platform `WorkflowEngine` standalone once it ports

## Conventions

- Standalone-only registration — the base bootstrap loader owns the
  same `init` wiring (priorities 12/13/14/20) in monolith installs.
- Byte-identical CPT args/meta schemas/error codes/filter contract with
  the base; deviations documented in the class docblocks (text domain,
  additive `META_KEYS`/`TERMINAL_STATUSES`/`META_FIELDS` constants,
  `static::` fire-path closures for test seams, per-mode execution
  hand-off, method_exists-guarded `is_enabled()`).
- Static utilities, no hooks of their own beyond the trigger bridges —
  wiring stays in `Plugin`.

## Tests

- `tests/test-workflow-cpts.php` — characterization suite covering CPT
  + meta registration for all three types, graph roundtrip +
  malformed-JSON fallback, export/import contract, semver bumping, run
  lifecycle + event log + status transitions + budget checks, trigger
  discovery + all six hook bridges + the fire path, and the per-mode
  dispatcher/engine seams (both matrices).
- `tests/test-dispatcher.php` — characterization suite covering the
  filter pass-through (array + WP_Error + all four args), the default
  executor fallback with a fake engine (enabled/disabled), the
  `no_workflow_executor` degradation, the per-mode engine seam, and the
  trigger hand-off integration through the real filter (both matrices).
- `tests/test-workflow-engine.php` — characterization suite covering
  the enable gate + filter, execution guards, lifecycle actions, the
  run-record roundtrip (running → terminal + step events), the graph →
  tool delegation shape, parallel-node detection, budget forwarding,
  the no-tool/no-registry degradations, and the per-mode collaborator
  seams (both matrices).

## Also Load

- [`../Queues/README.md`](../Queues/README.md) — the E2 queue family
  (trigger hand-off lands there via the dispatcher)
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping + capability checks

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E1 row status
