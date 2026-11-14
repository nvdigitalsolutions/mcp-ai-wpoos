# Proposal 090-h — The workflow engine on the Laravel runtime

**Status:** Decision recorded 2026-11-14 — **Laravel-native, phased, three increments.**
**Shipped:** H1 + H2 + H3, including the `trigger → execute` wiring (2026-11-14). All four engine keys report `true`; no workflow key remains false in the console contract.
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

### H1 — Catalogue (unblocks `workflowPresets`)

Pure data. No storage, no execution.

- `GET /api/v1/pro/workflow-presets` — the preset catalogue as WordPress serves it.
- `POST /api/v1/pro/workflow-presets/{id}/install` — the full preset graph.

**Acceptance:** the catalogue lists with categories; install returns `nodes`/`edges`; `workflowPresets` reports `true` from `/features`.

**Two corrections to the original scope, found while implementing (2026-11-14):**

1. **`install` does not write a record — on either runtime.** WordPress's
   `install_preset()` returns the graph; the console loads it into the canvas as
   a *draft* and the user saves it through the workflow `PUT`. Writing a record
   on install would produce a workflow the user never asked to keep, and would
   make install depend on H2 for no benefit. The acceptance criterion "a preset
   installs and appears in `list()`" was therefore wrong; it is now "install
   returns the graph".
2. **`workflowNodes` is H3, not H1.** The node palette is client-side static:
   `spa-v2/src/features/workflows/workflow-graph.ts` owns `NODE_TYPE_LABELS`
   (`tool`/`action`/`agent`) and `WorkflowCanvas.tsx` owns the xyflow type map.
   Nothing reads a server palette. Only `POST /nodes/execute` consumes
   `api/v1/pro/workflow-builder`, and that is H3. `workflowNodes` stays `false`
   until then.

Because `WorkflowsPage.loadPreset()` requires a **selected** workflow, the
catalogue is only usable once H2's storage exists — so H1 and H2 shipped
together rather than as two merges. The proposal's phase order (H1 before H2)
implied a preset-first world the client does not implement.

**Catalogue breadth:** all 33 presets ship. Filtering by "node types that exist
on this runtime" is a no-op — the canvas maps any type onto a generic node and
round-trips the original through `data.rawType`, so `input`/`output`/`condition`
nodes render and save unchanged. What a preset actually needs is its **tools**,
and that gate belongs to the H3 executor, not the catalogue.

### H2 — Graph storage + CRUD (unblocks `workflowBuilder`)

- `GET|POST /api/v1/orchestration/workflows`, `GET|PUT|DELETE /{id}`.
- Validation on write: node ids unique, every edge endpoint resolves, no cycle on save (reject with the offending edge, don't silently drop it).
- Provenance and permissions reuse `ContentStoreInterface`. The gate is **`manage_options`**, not `edit_posts`: `WP_MCP_AI_REST_Workflow_CPT_Controller::permissions_check()` requires `manage_options` on every endpoint, reads included, so the port matches the reference.

**Acceptance:** create → save → reload round-trips the graph byte-identically; an unauthenticated or under-privileged write is refused by middleware, not by a controller check.

### H3 — Execution + runs (unblocks `workflowRuns` and `workflowNodes`, and makes `execute` real)

- `POST /api/v1/orchestration/workflows/{id}/execute` — Kahn topological order over the saved graph, one node at a time, each node's output available downstream.
- `GET /api/v1/orchestration/runs?workflow_id=` — run summaries, newest first (50); `GET /runs/{id}` adds input, output, per-node events and the error.
- `POST /api/v1/pro/workflow-builder/nodes/execute` — the canvas's per-node run button.
- `POST /api/v1/orchestration/triggers/{id}/fire` — run a trigger's workflow now; the manual half of `trigger → execute`, and how an operator checks a trigger before trusting the scheduler.
- `php artisan nvoos:fire-triggers` — evaluates `cron_schedule` triggers (`config.cron` or `config.schedule`, accepting `hourly`/`twicedaily`/`daily`/`weekly`/`monthly` or a five-field expression, with `config.every_minutes` as a fallback) and fires the slots that have not run. Registered `everyMinute()->withoutOverlapping()`, so it fires only where the Laravel scheduler runs.
- Runs are append-only rows in `nvoos_workflow_runs`: status `running|completed|failed`, `source` (`manual`/`trigger`), `trigger_id`, tool-call count, duration, and the executor's per-node record in `events`.

**Node types.** `tool` (registry call, capability-gated), `condition` (`node_2.pageviews < 100`), `agent` (chat orchestrator, assistant resolved by id or the site default), `delay` (capped), plus `input`/`output` pass-throughs. Any other type without a `toolSlug` is a no-op, so a graph authored for WordPress still runs its tool nodes here.

**Templates.** `{{ input.key }}` and `{{ node_id.key.0.nested }}`, resolved by `TemplateResolver`: an exact single template keeps its type (array, number, bool), embedded ones interpolate. Conditions use the same resolver with a five-operator grammar (`=== !== == != > >= < <=`). No `eval` — a stored graph can never execute PHP.

**Divergence from the reference — deliberate.** WordPress's Engine V2 does **not** walk the graph: it renders the nodes into a description and hands the whole thing to an LLM-planned `execute_workflow` tool. The Laravel runtime executes the graph deterministically. That makes a run testable, names the failing node, and bounds its cost — but it is not what WordPress does, so a graph whose steps depend on model judgement can produce different results on the two runtimes. Recorded per §7 and in 090-d.

**Cost and safety.** Tool nodes reuse `ToolRegistry::execute()`, so each tool's `required_capability` and the pre-execution listener (destructive-ops gate) apply unchanged; the caller's identity is carried, never elevated. The executor adds a 200-node and a 50-tool-call ceiling per run. Agent nodes run through `ChatOrchestrator` with the assistant's own provider/model/tools and a 5-iteration cap.

**Not carried over.** `action` nodes need a command bus this runtime does not have, so the single-node endpoint answers 501 and the executor treats them as no-ops. Event-sourced trigger types (`comment_published`, `file_upload`, a WordPress `save_post`) have no Laravel counterpart yet — they are stored and manually fireable, but nothing dispatches them; `cron_schedule` is the scheduled type. Loops and human-in-the-loop pauses stay out of scope.

**Acceptance:** a two-node preset executes end-to-end against the registry, the run row records status/duration/tool calls, a missing tool fails the run and names the node, and a deliberately cyclic graph is rejected at save time rather than at execution time. All four are covered by `WorkflowTest`, `WorkflowRunTest` and the core executor suite.

## 6. Risks

| Risk | Mitigation |
|---|---|
| The executor is an arbitrary tool-invocation surface | It runs through the registry and inherits each tool's `required_capability`; the caller's identity is carried, never elevated. Same gate as `POST /tools`. |
| Runaway cost from a wide graph | Per-node iteration cap plus a run-level tool-call ceiling; the run row records spend so the existing cost surface accounts for it. |
| Graph drift between runtimes | The graph is JSON in a documented shape; a WP-exported graph imports unchanged, and H2's save-time validation catches what it cannot execute. |
| Scope creep into triggers | Triggers already ship. The dispatcher landed **after** the executor was proven (2026-11-14), so a broken engine could never break scheduling: `nvoos:fire-triggers` reuses the same `WorkflowRunner` the console uses and records the same run row. |

## 7. What this does not settle

- **`skills` and `mcpApps`** are separate decisions (deferred inside the assistant editor) and are not part of this proposal.
- **Posture parity** (8 native signals vs WordPress's 21) is independent.
- **Preset catalogue breadth**: all shipped presets are listed regardless of tool availability (see H1's corrections). Filtering belongs to execution, not to the catalogue.
- **Divergences from the reference (recorded per 090-d):** save-time graph validation is stricter than WordPress (which validates nothing), deletion trashes rather than destroys, and graph/tags are written as native JSON rather than JSON-in-a-string — reading tolerates both forms so a copied database lists unchanged.
- **Licensing of the preset data.** The Laravel app is MIT; the preset catalogue is Pro-licensed content. The JSON export carries a `_license` field and the loader states the split, same as the repo's base/Pro boundary. A deployment that must be MIT-only replaces `resources/workflow-presets.json` with its own catalogue — the loader needs no change.

## 8. Consequences

- Two of the four engine keys flip after H1+H2 with **no executor at all** — the cheapest visible win in the remaining gap list. (Shipped 2026-11-14.)
- The Orchestration page stops reporting `recent_workflows` and `patterns` as honestly-empty once H2 lands.
- A Laravel estate can run automations without a WordPress peer, which is the whole point of ADR-6/ADR-8.
- WordPress's Engine V2 stays the reference implementation; where the port diverges (storage, node subset), the divergence is recorded in `090-d` rather than smoothed over.
