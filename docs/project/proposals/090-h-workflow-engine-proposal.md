# Proposal 090-h — The workflow engine on the Laravel runtime

**Status:** Decision recorded 2026-11-14 — **Laravel-native, phased, three increments.**
**Unblocks:** `workflowBuilder`, `workflowPresets`, `workflowNodes`, `workflowRuns` (4 of the 17 remaining console endpoint keys).
**Related:** `090-b-laravel-admin-surfaces-proposal.md` ADR-5, ADR-6, ADR-8; `090-d-selective-tool-port.md`.

---

## 1. The decision

> **The Laravel runtime gets its own workflow engine. It never proxies to a WordPress one.**

That follows from ADR-6 (`NVOOS_BACKEND=wordpress|laravel`) and ADR-8 (two runtimes, three deployment profiles, never a runtime dependency). A Laravel estate must be deployable, debuggable and scalable without a WordPress instance behind it — so "proxy to WordPress" is not on the table, and neither is "declare the feature WordPress-only", because unlike `threads` a page really does read these keys.

The same rule bars `hybrid` as an escape hatch. `hybrid` is a same-origin strangler for a migration window, so wiring `workflowRuns` to a WordPress engine would work on exactly one deployment shape and break on the other two. The engine is native or it is absent.

The engine is **incrementally useful**: each phase turns on endpoint keys the console already knows how to consume, so the Workflows page degrades less with every merge instead of staying blank until the last one.

## 2. What exists today

**On the console.** `WorkflowsPage` renders a ReactFlow canvas and talks to one client, `WorkflowBuilderClient`. The full call surface:

| Client call | Route (fragment) | Needs |
|---|---|---|
| `list()`, `create()`, `get()`, `update()`, `remove()` | `api/v1/orchestration/workflows` | storage |
| `execute()` | `POST …/workflows/{id}/execute` | engine |
| `executeNode()` | `api/v1/pro/workflow-builder/nodes/execute` | engine |
| `runs()` | `api/v1/orchestration/runs?workflow_id=` | run store |
| `presets.list()`, `presets.install()` | `api/v1/pro/workflow-presets` | data only |

**On Laravel.** `OrchestrationStatsController` already reports the agent inventory; its workflow fields are honestly empty. `TriggersController` is live at `/api/v1/orchestration/triggers`, so the trigger half of the story already exists — it currently has nothing to trigger.

**On WordPress.** Engine V2: a DAG executed with Kahn's algorithm, ten node types, preset categories, and a run record per execution. The console's UI is built against it.

## 3. Options considered

| Option | Cost | Why not |
|---|---|---|
| **A. Proxy to a WordPress backend** | Low | Reintroduces the exact dependency ADR-6/ADR-8 remove. A "Laravel deployment" would secretly need WordPress, and it would hold only on the `hybrid` profile — the capability map would have to lie on `laravel` and the proxy would 502 on `wordpress`. Rejected. |
| **B. Declare WordPress-only, drop the keys** | Lowest | Defensible for `threads` because nothing read it. Here a full builder UI reads these keys; deleting them removes a shipped surface from the console for Laravel estates. Rejected on product grounds, not technical ones. |
| **C. Laravel-native, phased** | Medium | **Chosen.** Storage and catalogue are ordinary CRUD; only the executor is genuinely new. Phasing means each increment ships value. |

## 4. Storage

**Decision: workflows are a content record, not a new table.**

`ContentStoreInterface` already provides typed records, a JSON `meta` column, permissions, guard hooks and the `wp/v2` shim. A workflow is `type: mcp_ai_workflow` with `meta.graph` holding the ReactFlow graph (`nodes[]`, `edges[]`), plus `meta.status`, `meta.preset`, `meta.version`.

This matches how every other portable family was landed (090-e/090-f) and avoids a second storage seam. **Divergence from WordPress:** WP stores Engine V2 graphs in its own table; the record shape is the same JSON, so a ported graph imports unchanged — documented per the 090-d convention.

Runs are **append-only rows** keyed by workflow id, written by the executor. They are not content records: they are high-volume and never edited, so they get their own narrow table rather than bloating `nvoos_posts` meta.

## 5. Phases

### H1 — Catalogue (unblocks `workflowPresets`, `workflowNodes`)

Pure data. No storage, no execution.

- `GET /api/v1/pro/workflow-presets` — the preset catalogue, filtered to presets whose node types exist on this runtime.
- `POST /api/v1/pro/workflow-presets/{id}/install` — writes a `mcp_ai_workflow` record from the preset definition.
- `GET /api/v1/pro/workflow-builder/nodes` — node-type catalogue (id, label, category, config schema) so the canvas can render a palette. `POST /nodes/execute` stays 501 until H3.

**Acceptance:** a preset installs and appears in `list()`; the canvas renders a palette; `workflowPresets` and `workflowNodes` report `true` from `/features`.

### H2 — Graph storage + CRUD (unblocks `workflowBuilder`)

- `GET|POST /api/v1/orchestration/workflows`, `GET|PUT|DELETE /{id}`, `GET /{id}/versions` (optional).
- Validation on write: node ids unique, every edge endpoint resolves, no cycle on save (reject with the offending edge, don't silently drop it).
- Provenance and permissions reuse `ContentStoreInterface` — `edit_posts` to create, ownership/`manage_options` to edit others, the same rule the classroom family uses.

**Acceptance:** create → save → reload round-trips the graph byte-identically; an unauthenticated or non-owner write is refused by the store, not by a controller check.

### H3 — Execution + runs (unblocks `workflowRuns`, and makes `execute` real)

- `POST /api/v1/orchestration/workflows/{id}/execute` — Kahn topological order over the saved graph.
- `GET /api/v1/orchestration/runs?workflow_id=` — run summaries; `GET /runs/{id}` for the step detail.
- Node types, in this order: **tool** (registry call), **condition** (branch on a previous result), **agent** (assistant run via the orchestrator), **delay**. Templates use `{{ node.xyz.output }}` resolution against the run context.
- Execution reuses `ChatOrchestrator`'s limits: `max_agentic_iterations` per node, plus a run-level tool-call ceiling. Cost tracking already exists, so a run reports its own spend.

**Not in H3:** loops, human-in-the-loop pauses (approvals exist but wiring a pause/resume into a run is its own phase), cross-runtime run history.

**Acceptance:** a two-node preset executes end-to-end against a spike provider, the run row records status/duration/tool calls, and a deliberately cyclic graph is rejected at save time rather than at execution time.

## 6. Risks

| Risk | Mitigation |
|---|---|
| The executor is an arbitrary tool-invocation surface | It runs through the registry and inherits each tool's `required_capability`; the caller's identity is carried, never elevated. Same gate as `POST /tools`. |
| Runaway cost from a wide graph | Per-node iteration cap plus a run-level tool-call ceiling; the run row records spend so the existing cost surface accounts for it. |
| Graph drift between runtimes | The graph is JSON in a documented shape; a WP-exported graph imports unchanged, and H2's save-time validation catches what it cannot execute. |
| Scope creep into triggers | Triggers already ship. H3 wires `trigger → execute` last, so a broken engine cannot break scheduling. |

## 7. What this does not settle

- **`skills` and `mcpApps`** are separate decisions (deferred inside the assistant editor) and are not part of this proposal.
- **Posture parity** (8 native signals vs WordPress's 21) is independent.
- **Preset catalogue breadth**: shipping all ten preset categories in H1 is not required. Start with the categories whose node types exist after H3's first cut; add the rest with their node types.

## 8. Consequences

- Two of the four engine keys flip after H1 with **no executor at all** — the cheapest visible win in the remaining gap list.
- The Orchestration page stops reporting `recent_workflows` and `patterns` as honestly-empty once H2 lands.
- A Laravel estate can run automations without a WordPress peer, which is the whole point of ADR-6/ADR-8.
- WordPress's Engine V2 stays the reference implementation; where the port diverges (storage, node subset), the divergence is recorded in `090-d` rather than smoothed over.
