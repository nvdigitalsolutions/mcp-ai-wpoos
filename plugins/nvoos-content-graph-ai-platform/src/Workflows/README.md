# Workflows

## Purpose

Wave E1 port surface (in progress across PRs). `Dispatcher` is an
aligned port of the base plugin's `WP_MCP_AI_Workflow_Dispatcher`:
byte-identical `wp_mcp_ai_workflow_executor` filter contract (null =
defer, array|WP_Error = ownership), the default-executor fallback chain
(Engine V2 when available and enabled), and the `no_workflow_executor`
error envelope — the pluggable entry point the trigger CPT, replay
tool, and any workflow consumer call without hard-binding to the
engine. The workflow/run/trigger CPTs land in a companion PR (see
tracker E1 row); the engine itself lands in a later sub-cluster.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | Static utility — no hooks of its own; resolved by `WorkflowTriggerCpt::dispatcher_class()` standalone (trigger PR) and consumed directly |
| **Optional dependencies** | None; the default executor resolves per install mode |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Workflows\Dispatcher` | `Dispatcher.php` | `WorkflowTriggerCpt::fire_trigger()` hand-off (standalone), the replay tool, third-party executors via the `wp_mcp_ai_workflow_executor` filter |

## Inputs / Outputs / Neighbors

- **Reads from:** the `wp_mcp_ai_workflow_executor` filter chain, the
  per-mode engine class (`engine_class()` seam)
- **Writes to:** executor return values only — the engine owns run
  records once it ports
- **Upstream callers:** `WorkflowTriggerCpt` (trigger PR), future
  replay/REST consumers
- **Downstream collaborators:** `WP_MCP_AI_Workflow_Engine_V2` monolith
  (boot-gated) / platform `WorkflowEngine` standalone once it ports —
  null standalone until then (`no_workflow_executor` degradation)

## Conventions

- Byte-identical filter contract + error envelope with the base;
  deviations documented in the class docblock (per-mode engine
  resolution, method_exists-guarded `is_enabled()`).
- Static utility — wiring stays with the consuming classes and
  `Plugin`.

## Tests

- `tests/test-dispatcher.php` — characterization suite covering the
  filter pass-through (array + WP_Error + all four args), the default
  executor fallback with a fake engine (enabled/disabled), the
  `no_workflow_executor` degradation, the per-mode engine seam, and the
  trigger hand-off integration through the real filter (both matrices).

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping + capability checks

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E1 row status
