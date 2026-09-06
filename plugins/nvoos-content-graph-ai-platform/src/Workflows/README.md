# Workflows

## Purpose

Wave E1 port surface (sub-cluster 1: CPTs). `WorkflowCpt` is an aligned
port of the base plugin's `WP_MCP_AI_Workflow_CPT`: byte-identical
`mcp_ai_workflow` CPT args, graph/version/tags meta keys with the same
sanitize + auth callbacks, graph read/write helpers, the portable
export/import JSON contract (`schema_version` 1.0), and semver bumping.
`WorkflowRunCpt` is the aligned port of `WP_MCP_AI_Workflow_Run_CPT`:
byte-identical `mcp_ai_workflow_run` CPT args, the ten run meta keys,
the run lifecycle (create → append events → status transitions with
terminal `finished_at` stamping), the event-log read path, and the
four-dimension budget check. `WorkflowTriggerCpt` is the aligned port of
`WP_MCP_AI_Workflow_Trigger_CPT`: byte-identical `mcp_ai_trigger` CPT
args, the five trigger meta keys, enabled-trigger discovery, the six
trigger-type hook bridges, and the fire path with the dispatcher/engine
hand-off seams — the durable storage layer the workflow engine (later
E1 sub-clusters) reads and writes.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::registerWorkflowCpts()` — standalone-only (`! defined('WP_MCP_AI_PATH')`) |
| **Optional dependencies** | None (posts + postmeta only); execution hand-off resolves the dispatcher/engine per install mode |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Workflows\WorkflowCpt` | `WorkflowCpt.php` | `Plugin::registerWorkflowCpts()` — CPT + meta at `init` 12; consumed by the DAG builder (E-UI-2) + engine (E1) |
| `NvoosContentGraphAiPlatform\Workflows\WorkflowRunCpt` | `WorkflowRunCpt.php` | `Plugin::registerWorkflowCpts()` — CPT + meta at `init` 13; consumed by the engine/dispatcher (E1) |
| `NvoosContentGraphAiPlatform\Workflows\WorkflowTriggerCpt` | `WorkflowTriggerCpt.php` | `Plugin::registerWorkflowCpts()` — CPT + meta at `init` 14, trigger hooking at 20; consumed by trigger registry (E1) |

## Inputs / Outputs / Neighbors

- **Reads from:** workflow/run/trigger posts + postmeta under the three
  CPT slugs; `wp_mcp_ai_settings` (nothing yet — settings land with the
  engine)
- **Writes to:** workflow graph/version/tags meta, run records + event
  log + budget meta, trigger last-fired timestamps, the
  `wp_mcp_ai_trigger_cron_*` cron events
- **Upstream callers:** the workflow engine + dispatcher (E1 later
  sub-clusters), the trigger registry, the DAG builder UI (E-UI-2)
- **Downstream consumers:** `wp_mcp_ai_trigger_fired` +
  `wp_mcp_ai_workflow_run_budget_exceeded` + `wp_mcp_ai_workflow_run_*`
  actions; the dispatcher/engine seams (`dispatcher_class()`,
  `engine_class()`) resolve base classes monolith / platform classes
  once they port — null standalone until then (documented no-hand-off
  degradation)

## Conventions

- Standalone-only registration — the base bootstrap loader owns the
  same `init` wiring (priorities 12/13/14/20) in monolith installs.
- Byte-identical CPT args/meta schemas/error codes with the base;
  deviations documented in the class docblocks (text domain, additive
  `META_KEYS`/`TERMINAL_STATUSES`/`META_FIELDS` constants, `static::`
  fire-path closures for test seams, per-mode execution hand-off).
- Static utilities, no hooks of their own beyond the trigger bridges —
  wiring stays in `Plugin`.

## Tests

- `tests/test-workflow-cpts.php` — characterization suite covering CPT
  + meta registration for all three types, graph roundtrip +
  malformed-JSON fallback, export/import contract, semver bumping, run
  lifecycle + event log + status transitions + budget checks, trigger
  discovery + all six hook bridges + the fire path, and the per-mode
  dispatcher/engine seams (both matrices).

## Also Load

- [`../Queues/README.md`](../Queues/README.md) — the E2 queue family
  (trigger hand-off lands there via the dispatcher)
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping + capability checks

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E1 row status
