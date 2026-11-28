# 168 — Multi-agent teams at scale + A2A 1.0 conformance

**Status:** Proposed (2026-11-28)
**Research:** [168-R — research and recommendations](./168-multi-agent-team-scale-and-a2a-conformance-research.md) — the evidence, sources, and confidence behind every decision below (2026-11-28)
**Schema:** [168-S — Run Board schema and claim-path proofs](./168-run-board-schema.md) — DDL, index rationale, claim SQL, EXPLAIN + race harnesses
**Plan:** [168-P0 — implementation plan](./168-multi-agent-team-scale-and-a2a-conformance-p0-implementation-plan.md)
**Surfaces:** `includes/a2a/` (Agent Card, task manager, client, well-known), `includes/rest/class-wp-mcp-ai-rest-a2a-controller.php` (route table), `includes/services/class-wp-mcp-ai-agent-team-orchestrator.php` (team lifecycle), `includes/services/class-wp-mcp-ai-agent-communication-service.php` (delegation), `includes/tools/class-wp-mcp-ai-tool-delegate-to-agent.php` + `class-wp-mcp-ai-tool-delegate-to-a2a-agent.php`, `includes/class-wp-mcp-ai-mesh-router.php` (peer selection), `addons/pro/includes/class-wp-mcp-ai-autonomous-sessions-cct.php`, `addons/pro/includes/tools/orchestration/` (task plans, loop health, circuit breaker)
**Related:** [024](./024-hermes-agent-fleet-operator-implementation-plan.md) (fleet operator), [029](./029-oos-orchestration-runtime-consolidation-implementation-plan.md) (orchestration runtime), [015](./015-mcp-2026-07-28-protocol-upgrade.md) (the MCP-side precedent for a spec-version conformance pass), [022](./022-request-queuing-job-pooling-hardening-implementation-plan.md) (queue/worker substrate the board can ride), [016](./016-security-architecture-hardening-code-review-2026-08.md) (destructive-ops gate, audit logger), [007](./007-multi-tenant-database-isolation-implementation-plan.md) (tenant scoping for board rows), [167](./167-database-toolkit-scoped-access-proposal.md) (scoped database toolkit, PR #8431 — the board does not depend on it, but it must never reach board tables)

## 168.1 The problem, with evidence

The repo is not missing multi-agent plumbing. It has A2A transport, in-site delegation, cross-site delegation, team composition with roles, a mesh router with circuit breakers, and a Pro orchestration toolkit with autonomous sessions. What it is missing is the thing the source article says actually makes a large team work: **durable, shared, claimable work state that outlives any one agent's context — and a safety boundary that lives outside the model.**

Five measured gaps, in descending order of severity.

### 168.1.1 Team state expires after 24 hours

`includes/services/class-wp-mcp-ai-agent-team-orchestrator.php`:

```php
protected function store_team( $team ) {
    $key = 'wp_mcp_ai_team_' . $team['team_id'];
    set_transient( $key, $team, DAY_IN_SECONDS );   // 24h
}
```

and recovery is a `LIKE` scan of the options table:

```php
protected function find_team_with_virtual_agent( $virtual_agent_id ) {
    global $wpdb;
    // SELECT option_name FROM ... WHERE option_name LIKE '_transient_wp_mcp_ai_team_%'
```

A team, its members, and its virtual agents therefore have a hard 24-hour ceiling, and finding a member costs an options-table scan. The source run took **28.7 hours** and was **restarted from a save** at hour 18 with a further 12 hours granted. On this substrate, that run would have lost its team definition mid-flight and could not have resumed. Autonomous sessions already have a durable store (`WP_MCP_AI_Autonomous_Sessions_CCT`); teams do not.

### 168.1.2 The A2A task store is one option row with a 500-task cap

`includes/a2a/class-wp-mcp-ai-a2a-task-manager.php`:

```php
const TASKS_OPTION = 'wp_mcp_ai_a2a_tasks';
const MAX_TASKS    = 500;
const TASK_TTL     = 86400;

protected static function save_task( $task ) {
    $tasks = self::get_all_tasks();          // read the whole array
    $tasks[ $task['id'] ] = $task;           // mutate
    // ... write the whole array back
}
```

Every task write is a full read-modify-write of a single autoloaded option, serialised across all concurrent peers. At 500 concurrent tasks the oldest are silently evicted. There is no per-task lookup index, no cursor, no `statusTimestampAfter` filter, and no tenant scoping — all of which A2A 1.0 requires for `ListTasks` (§3.1.4, §13.1). The article's team wrote to a shared SQL database with transactions precisely because a single shared row does not survive 1,000 writers.

### 168.1.3 The Agent Card advertises capabilities the route table cannot serve

`includes/a2a/class-wp-mcp-ai-a2a-agent-card.php`:

```php
$capabilities = array(
    'streaming'         => true,
    'pushNotifications' => ! empty( $settings['a2a_enable_push_notifications'] ),
);
```

`includes/rest/class-wp-mcp-ai-rest-a2a-controller.php` registers exactly three routes: `/a2a` (JSON-RPC), `/a2a/agent-card/{id}`, and `/a2a/webhook` (inbound push receiver). There is **no** `message/stream`, **no** `tasks/{id}:subscribe`, **no** `GET /tasks` (list), **no** `GET /tasks/{id}` (get), **no** `POST /tasks/{id}:cancel`, and **no** push-notification-config CRUD.

A2A 1.0 §3.3.4 is explicit: a declared capability obliges the server to serve it, or to return `UnsupportedOperationError`. A peer that reads `streaming: true` and calls `SendStreamingMessage` gets a WordPress 404, not a typed A2A error. This is the same defect class this project already treats as a defect at tool level — a rendered control with no reader (see [164](./164-laravel-production-toolkit-parity-proposal.md) §164.1).

### 168.1.4 The card shape is 0.3-era behind a 1.0 constant

`const PROTOCOL_VERSION = '1.0'` is correct, but the emitted document is not a 1.0 document:

| Field | Emitted | A2A 1.0 |
|---|---|---|
| Discovery path | `/.well-known/agent.json` (`class-wp-mcp-ai-a2a-wellknown.php` rewrite + `class-wp-mcp-ai-a2a-client.php` fetch) | `/.well-known/agent-card.json` (§14.3) |
| Endpoint | top-level `url` + `protocolVersion` | `supportedInterfaces[]` only; both fields removed (§4.4.1) |
| Security | `security` | `securityRequirements` (§4.4.1) |
| Binding | `'protocolBinding' => 'JSON-RPC'` | `JSONRPC` / `GRPC` / `HTTP+JSON` (§5.3) |
| Part discriminator | not checked here, but 0.3-era `kind` | removed in 1.0 (§A.2.1) |
| Signing | none | optional JWS over RFC 8785 canonical JSON (§8.4) |

The repo emits `supportedInterfaces` *and* the legacy top-level fields together, which reads as "0.3 with a 1.0 sticker". A strict 1.0 client will reject or mis-parse it. Discovery is also fragile: `$card_url = $agent_url . '.well-known/agent.json'` produces a malformed host for any base URL without a trailing slash.

### 168.1.5 There is no board

`delegate_to_agent` carries `task`, `context.parent_task_id`, `context.dependencies`, `context.shared_data`, `expected_output` — a good *delegation envelope*. `create_task_plan` produces a plan. `manage_autonomous_session` tracks a session. But nothing exposes, to a running fleet, the four things the article's lead created in its first ten minutes and every helper then used:

- **announce** — orders to the whole team
- **assign** — who owns what
- **notes** — an open board for plans, verdicts, progress
- **subtasks** — a claimable to-do list, first claimer owns it
- **landed** — a log of finished work

Instead, coordination flows through nested tool calls and per-agent context windows. That is the exact failure the article says broke its first attempt: *"agents lost track of the project's state and stopped collaborating effectively."* It is also the highest-frequency cluster in the MAST taxonomy ([arXiv 2503.13657](https://arxiv.org/abs/2503.13657)) — 6 of 14 failure modes are inter-agent misalignment, not model capability.

## 168.2 What the article actually demonstrates

The headline is "1,000 agents reproduced a proof of Fermat's Last Theorem". The transferable content is smaller and sharper.

| Mechanism | What was done | Transfers? |
|---|---|---|
| **Coordination is state, not conversation** | No messaging tools at all. Agents coordinated only through shared Postgres and Git. | **Yes — core lesson.** Build a board, not a chat bus. |
| **Five shared tables** | Lead created announce / assign / notes / subtasks / landed in ten minutes, then used them for 28 hours. | **Yes.** This is the schema. |
| **Atomic claim** | "The first helper to claim it owns it"; helpers also claimed tasks written by peers. | **Yes.** Claiming must be atomic and leased. |
| **Task tree** | One hard statement was split recursively to 2,418 tasks, 10 levels deep, whenever a piece was too big for one agent. | **Yes.** Decomposition must be a runtime act, not a plan-time one. |
| **Assignment → invitation** | Lead assigned explicitly, then wrote "do not wait for me"; helpers self-organised and volunteered. | **Yes.** Autonomy must be able to expand. |
| **Re-planning from data** | Helpers judged 47 of 60 statements infeasible; the lead set them aside, then reassigned everyone back once throughput was measured. | **Yes.** Budget/difficulty verdicts must be recorded, not remembered. |
| **Time reminders** | 19 automatic "time remaining" messages to the lead only. | **Yes.** Cheap, high value. |
| **Sandbox per agent** | One shared machine capped at ~100 agents; 1,000 separate sandboxes worked. 5 s SQL statement limit, 256 MiB write cap, unprivileged DB role, own branch + MR only. | **Partly.** We are multi-tenant WordPress, not microVMs — see §168.4.5. |
| **Identity outside the sandbox** | The gateway tagged every request with the agent's identity so one agent could not pose as another. | **Yes.** Authority must not be self-asserted. |
| **Two monitors, different lenses** | Real-time per-tool-call classifier + offline reviewer with cross-agent visibility. CoT + inter-agent communication monitoring caught all red-team sabotage. | **Yes as a design; not as an LLM classifier in a chat turn** — see §168.4.6. |
| **Block semantics by role** | A block on the lead ended the run; a block on a helper removed only that helper. | **Yes.** Severity must depend on role. |
| **Resume from save** | Run restarted at hour 18 with 12 more hours. | **Yes.** Cheap if state is durable, impossible if it is a transient. |
| **Token cost** | 1/3 the output tokens and 1/7 the proof length vs the guided run. | Context, not a target — Anthropic measures multi-agent at ~15× chat tokens. |

**What does not transfer.** The article's own conclusion is that this *does not* obviate domain harnesses, and that agents "tend to give up on hard problems and misjudge their difficulty" — human encouragement kept the run alive. We should not build a system that assumes 1,000 autonomous WordPress assistants will self-direct a 28-hour task without a budget, a board, and a kill switch. The realistic target is **tens of agents over hours**, with the board making 100+ survivable later.

## 168.3 Standards applied

| Standard | What it settles | Where it lands |
|---|---|---|
| **A2A 1.0** (Linux Foundation; `a2a-protocol.org/latest/specification`) | Card shape, `supportedInterfaces`, 9 task states, `ListTasks` cursor pagination, `SubscribeToTask`, `GetExtendedAgentCard`, push-config CRUD, JWS-signed cards, `A2A-Version` / `A2A-Extensions`, error-code mapping (`-32001`…`-32009`), §13.1 authorization scoping, §13.2 webhook SSRF rules, §7.6 `auth-required` chains | §168.4.4 |
| **Blackboard / agent-board literature** ([arXiv 2611.04100](https://arxiv.org/html/2611.04100), the 1980s blackboard lineage) | *"Atomic claim establishes ownership; assignment is an invitation to claim."* Default lease ~2 hours, explicitly renewed. A board is where agents meet; a machine is where they run. | §168.4.2 |
| **MAST** ([arXiv 2503.13657](https://arxiv.org/abs/2503.13657)) | 14 failure modes in 3 categories — system design (5), inter-agent misalignment (6), task verification (3). Verification is a first-class failure source, not an afterthought. | §168.4.2 (`landed` + verifier), §168.8 |
| **Anthropic multi-agent research system** | Orchestrator-worker; ~15× chat tokens; token usage explains 80% of BrowseComp variance; synchronous subagents bottleneck; durable execution + checkpoints + resume; end-state evaluation over step-matching; subagent writes to a filesystem and returns a reference rather than piping payload through the coordinator | §168.4.7, §168.4.8, §168.4.9 |
| **OpenTelemetry GenAI semantic conventions** | `invoke_agent`, `chat`, `execute_tool` spans; `gen_ai.*` attributes; token and model identity on every span | §168.4.9 |
| **Existing repo precedent** | Proposal [015](./015-mcp-2026-07-28-protocol-upgrade.md) ran a spec-version conformance pass for MCP; [016](./016-security-architecture-hardening-code-review-2026-08.md) established the destructive-ops gate and audit logger this proposal reuses | §168.4.4, §168.4.6 |

## 168.4 Design

### 168.4.1 Three principles

1. **The board is the contract.** Agents may not coordinate by hoping a peer read their context. Every handoff that must survive a context reset goes through a row. A tool call is a request; a board row is a fact.
2. **Assignment is an invitation; claim is ownership.** The lead writes intent. Exactly one agent acquires the work, atomically, with a lease that expires. No lease, no work.
3. **Authority is asserted by the platform, not by the agent.** Identity, budget, tool allowlist, and role come from the execution envelope, never from an argument an agent can write.

### 168.4.2 The Run Board

A durable coordination store, one row per unit of work, keyed by run.

**Two tables, not five.** The article's five are an *interface* over shared state; four of them collapse cleanly:

| Article table | Board implementation | Notes |
|---|---|---|
| announce | `messages` with `scope = broadcast` and `role_target = lead` | Lead orders, time reminders, policy blocks |
| assign | `assignments` | Who is expected to work on what. Advisory. |
| notes | `messages` with `scope = thread`, keyed `(run_id, node_id)` | Plans, difficulty verdicts, progress, "FREE … name ONE deep piece" |
| subtasks | `tasks` | Claimable, leased, treed |
| landed | `tasks.state = landed` + `artifacts` | Finished work is a task state plus a reference, not a separate log |

**`tasks` row:**

```
id                uuid
run_id            uuid          -- the board
node_id           uuid          -- parent node in the decomposition tree (null = root)
depth             smallint      -- cap 12; the article reached 10
title             text
spec              json          -- objective + output format + tool guidance + boundaries
state             enum          -- open|claimed|working|blocked|landed|failed|canceled
claimed_by        text          -- agent id, set only by an atomic claim
lease_expires_at  datetime      -- renewed explicitly
attempts          smallint
verifier          json          -- how "landed" is checked (see below)
artifact_ref      text          -- pointer, not payload
created_by        text
created_at        datetime
```

**Atomic claim.** One statement, and the winner is decided by the database:

```sql
UPDATE {prefix}mcp_run_tasks
   SET state = 'claimed', claimed_by = %s, lease_expires_at = %s, attempts = attempts + 1
 WHERE id = %s
   AND ( state = 'open'
      OR ( state = 'claimed' AND lease_expires_at < UTC_TIMESTAMP() ) );
-- affected rows == 1 → you own it. 0 → someone else does.
```

This is the single most important line in the proposal. Everything else is bookkeeping.

**Portability and precedent.** Deliberately *not* `SELECT … FOR UPDATE SKIP LOCKED`: that is MySQL 8.0+/recent-MariaDB only, MySQL's own manual restricts it to queue-like tables, and it is unnecessary here. The conditional `UPDATE` + affected-row check works on every supported version and is already this repo's house style — `addons/checkout-api/includes/class-nvoos-checkout-api-license-store.php` branches on the affected count three times, and `WP_MCP_AI_Concurrency_Guard` ships a plugin-owned slot table with `acquire()`/`release()` and a TTL sweeper. The claim must never live in the object cache: with a non-persistent cache (or a flush) atomicity is a fiction.

**Where the indexes actually go.** The by-id claim is a primary-key lookup and uses no secondary index; the ordered index exists for the *pool* query (`WHERE run_id = ? AND state = 'open' ORDER BY depth, created_at`) and for the sweeper (`WHERE state = 'claimed' AND lease_expires_at < ?`). [168-S §168-S.3](./168-run-board-schema.md) maps every query to its index and states which plan assertions are engine-portable and which are not. The claim verdict is also only sound because operations change a strictly monotonic column — see [168-S §168-S.3.1](./168-run-board-schema.md) for why `renew` needs a counter and a timestamp will not do.

**Leases.** Default 2 hours (the agent-board literature's default), renewable only by the holder, in whole increments, with a hard per-task ceiling so a wedged agent cannot hold a node for the whole run. Expiry returns the node to `open` and increments `attempts`; at `attempts >= 3` the node transitions to `blocked` and posts a `notes` row rather than being retried forever.

**Decomposition.** Any holder may `split(node)` — creating children under its own node, which is how the tree reaches depth 10 without a planner predicting it. Splitting is capped by depth and by a per-run node budget, so a pathological splitter cannot exhaust the board.

**Landing.** `landed` requires a verifier result, not an assertion. Three verifier kinds, all cheap:

- `command` — a shell/CLI check exits 0 (Lean's role in the article; our equivalent is `phpunit`, `composer lint`, a build)
- `http` — a URL returns an expected status and optional JSON-path assertion
- `judge` — an LLM rubric score over the artifact reference, used only where a mechanical check is impossible

A2A `artifacts` are reused verbatim as the payload shape; the board stores the reference and lets the A2A task manager own the bytes. This is Anthropic's "subagent writes to the filesystem, returns a reference" rule, applied to our substrate.

**Verify the verifier.** A verifier that always passes is worse than no verifier, and MAST counts *incorrect verification* as its own failure mode. So every declared verifier must also declare an `expected_fail_case` fixture; CI runs each verifier against its fail case and its pass case, and a verifier that passes the fail case is disabled rather than trusted. A run whose landed nodes are majority `judge`-verified (rather than `command`/`http`) is flagged in the offline review. Reliability claims come from simulating the same task set 3× and reporting a pass rate — one green run proves nothing.

**Why the board and not the A2A task store.** They are different objects with different lifetimes. An A2A task is a *peer-visible unit of delegated work* (spec-owned, 9 states, must be listable and cancellable by a remote client). A board node is an *internal unit of coordination* (claim, lease, depth, verifier). Attempting to make one table serve both is what produces the 500-row option store in §168.1.2. The board lives in its own table set; A2A task ids may be recorded on a board row as an external reference.

### 168.4.3 Roles as declared capability

Today `create_agent_team` composes planner / executor / critic and materialises virtual agents; the role is data inside a 24-hour transient. Move roles onto the execution envelope:

```php
'run' => array(
    'run_id'       => 'uuid',
    'role'         => 'lead' | 'captain' | 'helper',
    'crew'         => 'langlands-tunnell',   // optional
    'can_assign'   => true,                   // lead only
    'can_split'    => true,                   // lead, captain, helper
    'can_verify'   => false,
    'budget'       => array( 'tokens' => 0, 'tool_calls' => 0, 'wall_clock_s' => 0 ),
    'board_scopes' => array( 'read:run', 'write:notes', 'claim:tasks' ),
)
```

The lead is the only role that may write `assignments` and broadcast. Captains are helpers promoted by the lead (the article made one helper per crew a captain to post a plan and split work). Helpers may claim, split their own node, write notes, and land. None of this is enforced by asking a model to behave; it is enforced by the tool layer refusing the call.

**Escalating autonomy is a flag, not a prompt.** The article's lead eventually wrote "do not wait for me". Model that as `run.assignment_mode = 'directed' | 'advisory'`. In `advisory`, an unclaimed assignment is visible to everyone and the first claimer wins — which is exactly the article's behaviour once the lead stopped hand-assigning.

### 168.4.4 A2A 1.0 conformance

A dedicated conformance pass, in the shape of [015](./015-mcp-2026-07-28-protocol-upgrade.md).

**Discovery.** Serve `/.well-known/agent-card.json` as the canonical path; keep `/.well-known/agent.json` as a 301 to it for one minor version (the spec keeps legacy anchors resolvable; peers cache cards for an hour, per our own README convention). Fix the client to build the URL with `trailingslashit()` and to try `agent-card.json` first, then `agent.json`.

**Card.** Emit a 1.0 document and nothing else:

- `supportedInterfaces[]` is authoritative; drop top-level `url` and `protocolVersion`
- `security` → `securityRequirements`
- `protocolBinding` → `JSONRPC` (no hyphen)
- `capabilities.streaming` is `true` **only when** a streaming route exists
- `capabilities.pushNotifications` is `true` **only when** push-config CRUD exists
- optional `signatures[]` — JWS over RFC 8785 canonical JSON, `kid` + `jku`, excluding the `signatures` field itself (§8.4). This is the mechanism that makes a peer's card trustworthy across a mesh; without it, any site can impersonate any other by publishing a card.

**Routes.** Add the operations the spec requires, on the existing `mcp-ai/v1` namespace:

| Operation | Route | Notes |
|---|---|---|
| `SendMessage` | `POST /a2a` (exists) | unchanged |
| `SendStreamingMessage` | `POST /a2a/message:stream` | SSE; gated on `capabilities.streaming` |
| `GetTask` | `GET /a2a/tasks/{id}` | per-task lookup, not a full-array read |
| `ListTasks` | `GET /a2a/tasks` | cursor pagination, `statusTimestampAfter`, `includeArtifacts=false` default, sorted by status timestamp desc (§3.1.4) |
| `CancelTask` | `POST /a2a/tasks/{id}:cancel` | idempotent |
| `SubscribeToTask` | `POST /a2a/tasks/{id}:subscribe` | SSE; `UnsupportedOperationError` on terminal tasks |
| push configs | `POST/GET/DELETE /a2a/tasks/{id}/pushNotificationConfigs[/{configId}]` | only when declared |
| `GetExtendedAgentCard` | `GET /a2a/extendedAgentCard` | authenticated; only when declared |

**Errors.** Adopt the spec's mapping table verbatim — `TaskNotFoundError` `-32001`/404, `TaskNotCancelableError` `-32002`/400, `PushNotificationNotSupportedError` `-32003`, `UnsupportedOperationError` `-32004`, `ContentTypeNotSupportedError` `-32005`, `InvalidAgentResponseError` `-32006`/500, `ExtendedAgentCardNotConfiguredError` `-32007`, `ExtensionSupportRequiredError` `-32008`, `VersionNotSupportedError` `-32009`. Include a `google.rpc.ErrorInfo` detail with `reason` and `domain: "a2a-protocol.org"` so peers can branch without parsing prose.

**Version negotiation.** Read `A2A-Version`; empty means `0.3` per §3.6.2; unsupported means `VersionNotSupportedError` — not a silent downgrade. The existing `A2A-Version` header on outbound calls and push notifications is already correct; keep it.

**Task store.** Move off the single option. A custom table (`{prefix}mcp_a2a_tasks`) with `id`, `context_id`, `state`, `status_timestamp`, `peer_id`, `tenant_id`, `payload` (JSON), and indexes on `(context_id, status_timestamp)`, `(state, status_timestamp)`, `(tenant_id)`. Retention becomes a scheduled prune rather than a 500-row eviction. This is also the prerequisite for `ListTasks`, which cannot be honest against an option row.

**Authorization scoping (§13.1).** `ListTasks` and `GetTask` must filter to the caller before any query runs. A peer must not learn that a task it cannot see exists — the spec is explicit that "not found" and "not authorized" must not be distinguishable.

**Bidirectional push (§13.2).** The outbound push sender already has retry/backoff; add webhook URL validation against private ranges unless the host is an approved peer — the same guard `WP_MCP_AI_A2A_Client` already applies on discovery. Reuse it rather than write a second one.

### 168.4.5 Authority outside the agent

The article's gateway tags every request with an identity the sandbox cannot forge. Our equivalent, without microVMs:

- **Delegation credentials carry the chain.** A parent delegating to a child mints a short-lived, task-scoped credential that records `run_id`, `node_id`, `role`, `delegated_by`, and the *intersection* of the parent's scopes with the child's request. A child can never hold a scope the parent lacked — the standard confused-deputy fix, and the same shape as A2A's `auth-required` chain (§7.6.2).
- **Scope intersection is enforced at mint time**, in `WP_MCP_AI_Agent_Communication_Service`, not by the callee.
- **Every tool call is attributed** to `(run_id, agent_id, node_id)` in the audit log, so a blocked action can be traced to the agent that attempted it and to the node that authorised it.
- **Read-only and destructive gates compose.** `WP_MCP_AI_Security_*` (`read-only profile gate`, `destructive ops gate`, `concurrency guard`, `cost tracker`) already exist; the board adds `run_id` to their context so a run can be frozen as a unit.

### 168.4.6 The policy gate

The article runs an LLM classifier on every shell command and file edit. We should **not** put an LLM on the hot path of every WordPress tool call — it would add latency and cost to a request/response loop that is already token-expensive. Instead, three tiers, cheapest first:

| Tier | Mechanism | Latency | Covers |
|---|---|---|---|
| 1. Structural | Existing capability checks, `get_required_capability()`, read-only profile gate, destructive-ops gate, scope intersection | ~0 | The overwhelming majority of real failures: an agent attempting an action outside its declared authority |
| 2. Rule | Deny-rule engine over tool slug + argument shape — path escapes, writes above size, private-IP egress, `shell` with unlisted binaries, writes to plugin/theme roots | ~0 | The specific classes a fleet can hit that a single agent cannot: cross-tenant writes, board tampering, lease forgery |
| 3. Classifier | Optional, off by default, sampled or applied to a configurable high-risk tool list | 100s of ms | Novel behaviour neither structural nor rule covers |

**Where it attaches.** Tiers 1–2 do not need a new enforcement layer. Every tool execution already fires `wp_mcp_ai_before_tool_execution( $tool_slug, $arguments, $context )` — from all three REST execution paths — with `$context` carrying the run envelope, and every caller already catches `WP_MCP_AI_Chat_Profile_Blocked`. A new `WP_MCP_AI_Run_Scope_Gate` registers on that hook **between** `WP_MCP_AI_Read_Only_Profile_Gate` and `WP_MCP_AI_Destructive_Ops_Gate`: a run may not widen a chat profile, and a destructive action still needs its confirmation inside a run. Tier-2 rules are a versioned data file validated at boot, so a bad rule fails loudly instead of silently widening.

**Block semantics by role**, straight from the article: a Tier-2/3 block on the **lead** freezes the whole run (a human resumes it); a block on a **helper** removes that helper from the run and returns its leased nodes to `open`. Record the block as a `messages` row with `scope = broadcast` so every agent can see why, and as an audit event.

**Offline review.** A scheduled pass over the run's board + audit trail with cross-agent visibility — the article's offline monitor. Start it as a report (JSON + admin panel), not an autonomous actor. Its first job is to answer: did any agent claim outside its role, hold a lease past the ceiling, or land without a verifier pass?

### 168.4.7 Budgets, time, and difficulty calibration

**Budgets are per run and per node, and they are enforced, not suggested.** Token, tool-call, and wall-clock ceilings. On exhaustion the node transitions to `blocked` with a machine-readable reason; the run does not silently continue on a cheaper model unless `run.model_fallback` says so. A hard turn + budget cap per node is the cheap mitigation that turns a silent loop into a caught failure, so it belongs on the default path, not in a preset.

**Default to one agent.** The board is for work that is genuinely parallel or exceeds one context window. The run reports when it never had two concurrently-claimable nodes, and the admin panel says plainly that the run did not need a team. Pair this with the declared effort tier below — a lead choosing its own scale is how "50 subagents for a simple query" happens.

**Time reminders to the lead only** — the article sent 19. A scheduled `messages` row every N minutes with `elapsed / budget / nodes open / nodes blocked`. Cheap, and it is what produced the article's "no reason to idle" and "honest odds are low" course corrections.

**Difficulty verdicts are rows.** When a helper judges a node infeasible in budget, it writes `{node_id, verdict: 'infeasible_in_budget', confidence, rationale}` to `notes`. This is what let the article's lead set 47 statements aside and later reassign everyone back once throughput exceeded the estimate. A verdict that lives only in an agent's context window is lost at the next reset — and the article's most expensive failure was agents giving up early on work that was actually tractable.

**Effort scaling.** Encode Anthropic's guidance as data on the run: `simple` → 1 agent, ≤10 tool calls; `comparison` → 2–4 agents; `complex` → >4 agents with explicitly divided responsibilities. Without a declared tier, agents over-invest in simple work.

### 168.4.8 Durability and resume

- Team definitions move from a 24-hour transient to a durable store alongside `WP_MCP_AI_Autonomous_Sessions_CCT`; `find_team_with_virtual_agent` becomes an indexed lookup, not a `LIKE` scan.
- Board rows are durable by construction; leases survive restarts.
- A run has an explicit **checkpoint** row: the set of `landed` nodes and open leases at a point in time. Resume = reopen the board, expire stale leases, and re-issue `announce`. That is precisely the article's hour-18 restart.
- Deploys must not strand in-flight agents. Anthropic uses rainbow deployments; our cheap version is a `run.runtime_version` stamp — a resumed run continues on the version it started with, and new runs take the new version.

### 168.4.9 Observability

Emit OpenTelemetry GenAI spans using the **A2A convention** (`semantic-conventions-genai/docs/gen-ai/a2a.md`), not an invented namespace:

- span name = the A2A method name (`SendMessage`, `GetTask`, `ListTasks`, `SubscribeToTask`, `CancelTask`, `GetExtendedAgentCard`, and the push-config methods)
- required `a2a.method.name`; conditional `a2a.task.id`, `a2a.task.state`, `a2a.tenant`, `a2a.message.id`; recommended `a2a.protocol.version`, `a2a.message.reference_task_ids`
- `gen_ai.conversation.id` is populated from the A2A `contextId` — the mapping is prescribed, not free-form
- on the server side, enrich the WordPress REST span and rename it to the method name rather than nesting a second span
- **`error.type` carries the A2A error reason** (`google.rpc.ErrorInfo.reason`, e.g. `TASK_NOT_FOUND`), which means the §168.4.4 error-envelope work *is* the telemetry contract — one change, counted once
- agent turns add `invoke_agent` / `chat` / `execute_tool` spans with `gen_ai.*` attributes, plus our own `mcp_ai.run_id`, `node_id`, `role`, `depth`, `lease_age`

The GenAI and A2A conventions are all **Development** status, so pin the version in a constant and emit through a mapping layer — never scatter `a2a.task.state` literals through the codebase. The article's Figure 4 (reads/writes per table over time) and Figure 6 (which agent worked on what) are both derivable from board rows; building the board makes the run's shape visible for free.

Minimum dashboard, all from `tasks` + `messages`:

- open / claimed / blocked / landed per run, over time
- depth histogram and widest fan-out (the article's 2,418-node tree, 10 deep)
- claim contention (claims lost to a concurrent claimer) — the direct signal that the fleet is too big for the open work
- lease expiries and `attempts >= 3` blocks
- tokens per landed node — the efficiency number the article reports as 1/3

## 168.5 Phases

| Phase | Scope | Gate |
|---|---|---|
| **P0 — Board** | `tasks` + `messages` tables, atomic claim, leases, split, land-with-verifier, run envelope (`run_id`/`role`/budget), board tools (`board_announce`, `board_assign`, `board_claim`, `board_note`, `board_split`, `board_land`, `board_status`) | Two agents race for one node 200×; exactly one wins every time. A lease expires and the node becomes claimable. A run of 10 agents over 6 hours resumes after a forced restart with zero duplicate landings. |
| **P1 — Durable teams** | Move team + virtual-agent state off transients; indexed lookup; `assignment_mode`; role enforcement in the tool layer | A team survives 72 hours. A helper's attempt to `board_assign` is refused. `find_team_with_virtual_agent` no longer scans options. |
| **P2 — A2A 1.0 conformance** | Canonical `agent-card.json` + 301, 1.0 card shape, `ListTasks`/`GetTask`/`CancelTask`/`SubscribeToTask`, task table, error mapping, `A2A-Version` negotiation, authorization scoping | A2A 1.0 conformance suite green; a 1.0 peer lists, gets, subscribes to, and cancels a task; a 0.3 peer still works via the legacy path. `capabilities.*` matches the route table exactly (asserted by test). |
| **P3 — Authority + gate** | Delegation credentials with scope intersection, `run_id` in audit, Tier-2 deny rules, lead/helper block semantics | A child cannot mint a scope its parent lacked. A board-tampering or cross-tenant write is denied at Tier 2. A lead block freezes the run; a helper block returns its nodes to `open`. |
| **P4 — Telemetry + offline review** | OTel GenAI spans, run dashboard, scheduled offline review report | A 20-agent, 4-hour run produces a complete span tree and a review report naming any role/lease/verifier violation. |

P2 is independently valuable and can ship ahead of P0 if A2A interoperability is the nearer business need. P0 is the piece that changes what the system can do.

## 168.6 What we deliberately do not build

- **A general agent-to-agent chat bus.** The article removed messaging on purpose and coordination improved. Notes are scoped to `(run_id, node_id)`; there is no free-form channel.
- **A microVM sandbox per agent.** We are a multi-tenant PHP application. The sandbox's *function* — an unprivileged identity, a narrow write surface, and a request-time limit — is delivered by scope intersection, per-request caps, and the existing destructive-ops gate.
- **An LLM classifier on every tool call.** Tier 3 exists, is optional, and is off by default.
- **Unbounded recursion.** Depth and node count are capped; the article's own tree stopped at 10 levels.
- **A second database access layer.** Agent-authored SQL is Proposal [167](./167-database-toolkit-scoped-access-proposal.md)'s surface — connections, scopes, column masking, propose/apply writes. The board's SQL stays inside its own service, and 167 must never be able to reach board tables (it cannot today: `SITE_DATABASE_REFUSED` makes the application database unscopable — see [168-S §168-S.8](./168-run-board-schema.md)).
- **A second orchestration engine.** The board sits under the existing `create_task_plan` / `manage_autonomous_session` / `delegate_to_agent` surface; those tools gain board awareness rather than being replaced.

## 168.7 Risks

| Risk | Mitigation |
|---|---|
| Board becomes a second source of truth that drifts from A2A tasks | Board stores A2A task id as a reference only; A2A owns peer-visible task state. Asserted in tests. |
| Claim contention collapse at high agent counts | Measure contention per run; the dashboard surfaces it and the run scales in, not out. |
| A verifier that always passes (rubber-stamp landing) | Every verifier ships an `expected_fail_case` exercised in CI; a run whose landed nodes are majority `judge`-verified (>50%) is flagged in the offline pass |
| Scope intersection breaks legitimate escalation | Explicit `request_escalation` path with an approval gate (the existing HITL approval queue in the Content Graph platform addon), never an implicit widening. |
| Legacy `agent.json` peers break on the 1.0 card | 301 on discovery; 0.3 branch retained for one minor version; conformance suite covers both. |
| Table growth from many short runs | Retention policy by run age + state; `landed` rows pruned per tenant policy. |

## 168.8 Tests

- `tests/test-run-board.php` — claim atomicity under concurrency, lease expiry, attempts ceiling, split depth/node caps, verifier-gated landing, and idempotency (a retried `board_land` / `board_split` with the same op key is a no-op returning the original result)
- `tests/test-run-board-roles.php` — lead/captain/helper scope enforcement, `assignment_mode` transitions
- `tests/test-team-durability.php` — team survives beyond 24h, indexed lookup, no options scan
- `tests/rest/test-a2a-1-0-conformance.php` — card shape, `supportedInterfaces`, capability↔route equivalence, `A2A-Version` negotiation, error code mapping, `ListTasks` pagination/filter/ordering, `GetTask` authorization scoping (no existence leak), terminal-state `SubscribeToTask`
- `tests/test-a2a-task-store.php` — per-task lookup, retention, tenant isolation, no whole-array read-modify-write
- `tests/security/test-delegation-scope-intersection.php` — child cannot exceed parent scopes; forged `role` / `run_id` / `node_id` in arguments is ignored
- `tests/test-policy-gate.php` — Tier-2 rules, gate ordering (read-only profile → run scope → destructive), lead vs helper block semantics, audit attribution
- **Verifier fixtures** — every declared verifier ships an `expected_fail_case`; CI asserts it fails that case and passes its pass case. A verifier that passes its fail case fails the build.
- MAST-shaped evaluation set (≥20 scenarios across the 3 categories) run as end-state checks, not step matching; each scenario simulated 3× and reported as a pass rate
- **A2A Inspector run** as the P2 acceptance gate (external validator: card detection, all bindings, get/list/cancel/subscribe, push configs, response validation)

## 168.9 Decisions, with recommendations

All five original questions are resolved in [168-R §168-R.12](./168-multi-agent-team-scale-and-a2a-conformance-research.md#168-r12-resolved-decisions). Summary:

| Decision | Recommendation | Basis |
|---|---|---|
| Board storage | **Custom tables** via `dbDelta` + version option, mirroring `NV_oOS_Graphify_DB` | Atomic conditional `UPDATE` needs indexes; CCT inherits JetEngine coupling |
| Task-store migration | **One-shot + read-through shim** | The options store is capped at 500 rows, so the surface is provably small |
| Legacy `agent.json` window | **One minor version**, then typed 404 | Legacy anchors stay resolvable without hiding broken clients forever |
| Captain in P0 | **Yes** | The source run promoted captains inside the first hour |
| Default lease | **2 h at depth ≤2, 30 min below**, per-run configurable, hard per-node ceiling | Agent-board 2 h default; deep nodes should recycle faster |
| Claim mechanism | **Conditional `UPDATE` + affected rows**, never `SKIP LOCKED`; CI `EXPLAIN` assertion on the pool query, not the by-id claim | Version-portable; already the repo's house style |
| Gate integration | `WP_MCP_AI_Run_Scope_Gate` on `wp_mcp_ai_before_tool_execution`, between the read-only and destructive gates | Hook and exception contract already exist on all three execution paths |
| Telemetry vocabulary | Native `a2a.*` / `gen_ai.*` names, version-pinned mapping; `error.type` = `ErrorInfo.reason` | OTel A2A convention; Development status means version-pin |

Still open for the owner: whether to raise the plugin's `Requires at least: 6.0` floor (needed for `%i` in `$wpdb->prepare()`, WP 6.2+) or keep new table code on the constant-table-name form used by `class-nvoos-checkout-api-license-store.php`. The proposal assumes the latter, which needs no floor change.
