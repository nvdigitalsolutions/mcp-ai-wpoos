# 168-R — Research and recommendations: multi-agent teams at scale + A2A 1.0

**Status:** Research (2026-11-28)
**Companion to:** [`168-multi-agent-team-scale-and-a2a-conformance-proposal.md`](./168-multi-agent-team-scale-and-a2a-conformance-proposal.md) — [168-S schema spec](./168-run-board-schema.md) — [168-P0 implementation plan](./168-multi-agent-team-scale-and-a2a-conformance-p0-implementation-plan.md)
**Primary sources read in full:** A2A Protocol Specification 1.0 (`a2a-protocol.org/latest/specification`), OpenTelemetry GenAI `a2a.md` semantic conventions, Anthropic *How we built our multi-agent research system*, the Claude Fable 5.2 scaling post, `a2a-inspector`, RFC 8693, MAST (arXiv 2503.13657), the agent-board literature (arXiv 2611.04100), and this repo's own shipped implementations.

## 168-R.1 Method

Each section below answers one question the proposal depends on, states what the evidence actually says, and ends with a **Recommendation** and a confidence label:

- **Verified** — the source states it normatively, or the repo code was read directly.
- **Supported** — the source implies it and multiple independent write-ups agree.
- **Judgement** — engineering inference from the constraints; no direct source.

Where a recommendation *changes* the proposal, the section says so explicitly. §168-R.12 collects every resolved decision.

## 168-R.2 Question 1 — Should agents coordinate through messages or through shared state?

**Evidence.** The Fable 5.2 run removed agent-to-agent messaging entirely: agents "had no dedicated messaging tools and coordinated only through the database and Git." Its stated reason is that earlier attempts failed because "agents lost track of the project's state and stopped collaborating effectively." Anthropic's production research system reaches the same conclusion from the other direction — its subagents "call tools to store their work in external systems, then pass lightweight references back to the coordinator," explicitly to avoid "the game of telephone."

MAST supplies the failure accounting: of 14 failure modes, **6 are inter-agent misalignment** and **3 are task verification** — i.e. two-thirds of the taxonomy is *not* model capability. Coordination structure is where the reliability is won or lost.

**Recommendation.** Keep the proposal's position: a scoped notes board (`messages` keyed by `run_id` + `node_id`), not a chat bus. Do not add a free-form inter-agent channel as a convenience.

**One addition.** MAST's FC2 mitigation — *"share the full trace, not messages"* — argues for one thing the proposal under-specifies: when an agent is resumed or handed a node, it should receive the node's **message thread plus its ancestor chain of specs**, not a paraphrase. Make `board_status(node_id)` return `spec` + lineage + notes, so a fresh context can reconstruct intent without asking a peer.

**Confidence:** Verified for the source claims; Supported for the recommendation.

## 168-R.3 Question 2 — What is the correct atomic claim on MySQL/MariaDB under WordPress?

**Evidence.** Three candidate mechanisms:

| Mechanism | Portability | Notes |
|---|---|---|
| `SELECT … FOR UPDATE SKIP LOCKED` | **MySQL 8.0+ / recent MariaDB only** | MySQL's own manual warns it "return[s] an inconsistent view of the data" and is "not suitable for general transactional work … may be used to avoid lock contention when multiple sessions access the same queue-like table." |
| `GET_LOCK()` advisory lock | All MySQL/MariaDB | Requires a released lock per claim; leaks on crash until connection close; awkward inside a web request. |
| **Conditional `UPDATE … WHERE` + affected-rows** | **All versions** | The MariaDB community answer to "alternative to SKIP LOCKED" is precisely this: an indexed processing column, select-then-update in one statement, treat 0 affected rows as a lost race. |

**The repo already ships this pattern.** `addons/checkout-api/includes/class-nvoos-checkout-api-license-store.php` does one-shot conditional updates and branches on the affected-row count three times (`set_terms_agreed`, `set_buyer_email`, `mark_email_sent`), each guarding with `… WHERE license_key = %s AND col IS NULL`. `WP_MCP_AI_Concurrency_Guard` goes further: it owns a custom table (`mcp_ai_concurrency_slots`), an `acquire()`/`release()` pair, a `LOCK_TTL = 600`, and a `cleanup_expired_slots()` sweeper.

So the pattern is not novel here — it is the house style.

**Recommendation.**

1. **Claim with a single conditional `UPDATE`, never `SKIP LOCKED`.** The proposal's statement is correct as written; make the portability reason explicit so nobody "optimises" it into a locking read later:

```sql
UPDATE {prefix}mcp_run_tasks
   SET state = 'claimed', claimed_by = %s, lease_expires_at = %s, attempts = attempts + 1
 WHERE id = %s
   AND ( state = 'open' OR ( state = 'claimed' AND lease_expires_at < UTC_TIMESTAMP() ) );
```

`$wpdb->query()` returns the affected count; `1` wins, `0` loses. No transaction needed, no lock held across the model call.

2. **Require an index that satisfies the claim**, `KEY claim (state, lease_expires_at)`, and add an `EXPLAIN` assertion to CI so a schema change cannot silently introduce a filesort on the hot path.

3. **Reuse the slot-table shape, not the slot-table API.** `WP_MCP_AI_Concurrency_Guard` is keyed by operation type (a counter); the board is keyed by node id (a lease). Same columns, same TTL discipline, same sweeper — a shared abstract `*_Lease_Store` is tempting but premature; the two have different semantics and fusing them risks the concurrency guard.

4. **Do not put the claim in the object cache.** With a persistent object cache the atomicity is real, but with a per-request cache (or a cache flush) it is not. The database is the only arbiter. (`WP_MCP_AI_Concurrency_Guard` already does DB-fallback for exactly this reason.)

5. **Sweep expired leases on Action Scheduler**, following the existing `function_exists( 'as_schedule_recurring_action' )` + `WP_MCP_AI_Cron_Manager` fallback used elsewhere in the repo — not a bespoke cron.

**Confidence:** Verified (MySQL manual, RFC-level behaviour, repo code read).

## 168-R.4 Question 3 — What does durability actually require?

**Evidence.** Temporal-style durable execution amounts to four properties, all of which map onto the board:

| Property | Meaning here |
|---|---|
| Deterministic replay | The board, not the agent's memory, is the replay source. Resume = re-read rows. |
| Idempotency | Every board mutation is keyed (`run_id` + `node_id` + operation) so a retried tool call does not double-land or double-split. |
| Heartbeat / visibility timeout | The lease *is* the heartbeat. Holder renews; non-renewal is the failure signal. |
| Resume from checkpoint | `landed` set + open leases at a point in time. |

The Fable 5.2 run is a live demonstration: restarted at hour 18 "from its last save" with 12 more hours. Anthropic's production post states the same requirement independently — "we built systems that can resume from where the agent was … restarts are expensive and frustrating."

**Recommendation.**

1. Make **every board tool idempotent** and give each a caller-supplied `op_id` (or derive one from `run_id + node_id + verb`). A retried `board_land` must be a no-op that returns the existing result.
2. Treat **lease expiry as the only liveness signal.** Do not add a separate heartbeat endpoint; it is a second clock that will disagree with the first.
3. Store `attempts` and refuse the third retry into `blocked` — an unbounded retry loop is MAST FC1 (step repetition) wearing a queue costume.
4. Cap **run wall-clock** independently of node leases, so a run cannot be kept alive forever by a steady trickle of tiny nodes.

**Confidence:** Verified for the properties; Judgement for the specific caps.

## 168-R.5 Question 4 — How should decomposition work?

**Evidence.** The Fable 5.2 run did **not** plan the tree up front. The tree grew only when a piece "proved too hard for a single agent," reaching 2,418 tasks up to 10 levels deep. The initial plan was ~10 areas. Anthropic's guidance is the same shape from the prompt side: give the orchestrator objective + output format + tool guidance + **clear task boundaries**, and scale effort to complexity rather than defaulting to maximum.

**Recommendation.**

1. **Split at runtime, by the holder, on evidence of difficulty.** Any holder may `board_split(node_id, children[])`. The lead does not pre-compute depth.
2. **Enforce two caps**: `max_depth` (default 10, matching the observed run) and `max_nodes_per_run`. Both are run config, not constants — a research run and a CI-fix run want different caps.
3. **Children inherit the parent's verifier unless overridden**, and a split that produces children with no verifiable exit is rejected at write time. This is the single most effective guard against tree sprawl.
4. **Do not let a split silently cancel the parent.** Parent stays `working` until its children land; a parent with all children landed but no parent-level verifier is `blocked`, which is a real signal.

**Confidence:** Supported.

## 168-R.6 Question 5 — How do we verify work, and how do we verify the verifier?

**Evidence.** This is where the proposal was weakest, and the literature is blunt about it.

- MAST classifies **task verification** as one of three top-level failure categories, including *incorrect verification* — "an incorrect checker is its own failure mode."
- The Fable 5.2 harness never trusted an agent's claim: work counted as proved "only once Lean's checker accepted a finished proof," and the merge job refused conflicts mechanically.
- Anthropic recommends **end-state evaluation** over step-matching for stateful agents, and notes interventions in MAST-style systems yield only modest gains (the paper reports **+15.6% for ChatDev**) — "simple fixes are still insufficient."

That last number is the honest frame for the whole proposal: the board removes a *class* of failure; it does not make a fleet reliable by itself.

**Recommendation.**

1. Keep the three verifier kinds (`command`, `http`, `judge`) and **rank them**: a run whose landed nodes are >50% `judge`-verified is flagged in the review report. Mechanical beats probabilistic.
2. **Verify the verifier** (MAST FC3):
   - every verifier declares `expected_fail_case` — a fixture that must fail it;
   - CI runs each declared verifier against its fail case and its pass case;
   - a verifier that passes the fail case is disabled, not trusted.
3. **Simulate N** — run the same task set 3× before declaring a run shape reliable. One green run proves nothing; report a pass rate.
4. Evaluate **end-state**: assert on `landed` artifacts and final workspace state, not on tool-call sequences. Sequence assertions will fail on valid alternative paths and teach the team to game them.
5. Budget an **LLM judge** only for genuinely free-form outputs, with a rubric, and log its scores — not as a gate.

**Confidence:** Verified for MAST/Anthropic; Supported for the mitigations.

## 168-R.7 Question 6 — How is delegated authority bounded?

**Evidence.** RFC 8693 is the standard vocabulary and it is unambiguous about direction of travel: delegation vs impersonation, `act` (who is acting) and `may_act` (who may act for whom) claims, and — the operative line in every practical guide — **each exchanged token must carry less authority than the one it replaced, and requests that try to widen it must be refused.**

A2A 1.0 has the protocol-level analogue: `TASK_STATE_AUTH_REQUIRED`, and §7.6.2 explicitly allows a client that "is itself an A2A agent actively processing a Task" to "further delegate the authorization request to its client by transitioning its own Task to `TASK_STATE_AUTH_REQUIRED`" — a *chain* of auth-required tasks. §7.6.3 warns that in-band credential passing "can allow credentials to be passed across chains of multiple A2A agents, exposing those credentials to each agent participating in the chain."

**Recommendation.**

1. Model the delegation credential on RFC 8693 even though we are not issuing OAuth tokens: `run_id`, `node_id`, `role`, `act` (delegating agent), `may_act` (the delegating agent's own authority), `scopes` (the intersection), `exp` (short).
2. **Intersect at mint time** in `WP_MCP_AI_Agent_Communication_Service`; a child request is never evaluated against the child's claim. Refuse widening with a typed error, not a silent clamp — a silent clamp teaches the lead to over-request.
3. **Never accept `role`, `run_id`, or `node_id` from tool arguments.** They come from the envelope. The proposal says this; make it a test (`tests/security/test-delegation-scope-intersection.php`) rather than a convention.
4. For cross-site delegation over A2A, prefer **out-of-band** credential delivery (§7.6.3) and set `AUTH_REQUIRED` rather than embedding credentials in message parts.
5. An escalation path (`request_escalation` → HITL approval) is required, otherwise teams will route around the gate by having the lead do the privileged call itself — which defeats the purpose.

**Confidence:** Verified (RFC 8693, A2A §7.6).

## 168-R.8 Question 7 — Where should the policy gate live, and what should it block?

**Evidence.** The repo already has the interception point and the exception contract:

```php
do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $arguments, $context );
```

called from all three execution paths (`class-wp-mcp-ai-rest.php` ×2, `class-wp-mcp-ai-rest-tools-controller.php`), with `WP_MCP_AI_Chat_Profile_Blocked` caught by every caller. `WP_MCP_AI_Read_Only_Profile_Gate` registers at priority 0 "before the destructive-ops gate"; `WP_MCP_AI_Destructive_Ops_Gate` also at priority 0.

Crucially, **`$context` is already passed** — that is where `run_id`, `role`, and `node_id` live. No new interception layer is needed.

**Recommendation.**

1. Add `WP_MCP_AI_Run_Scope_Gate` on the existing hook, not a new dispatcher. It reads `$context['run']` and denies tool calls outside the role's scope. This is ~1 class, not a subsystem.
2. **Ordering is the design.** Register after the read-only profile gate and before the destructive-ops gate: profile → run scope → destructive confirmation. A run cannot widen a profile, and a destructive action still needs its confirmation even inside a run.
3. **Tier 2 rules are a data file, not code** — `run_id`-aware deny rules over `(tool_slug, argument shape)`: path escape, write above N bytes, private-IP egress, write to plugin/theme roots, and *any write to the board tables by a non-lead*. Versioned with the plugin, validated at boot, so a bad rule fails loudly.
4. **Tier 3 classifier stays off by default** and is opt-in per run. The article's classifier saw a million-plus tool calls and blocked none; the value was proving the boundary, not catching agents. Our structural + rule tiers carry that job at zero latency.
5. **Block semantics must differ by role** (lead freezes the run, helper is removed and its leases released). Implement as a return value from the gate plus a board write, so the audit trail records *who* was blocked and *why*.
6. Adopt MAST FC1 verbatim: **a hard turn cap and a budget cap per node**, because "it turns a silent loop into a caught failure."

**Confidence:** Verified (repo hooks + MAST).

## 168-R.9 Question 8 — How do we prove A2A 1.0 conformance?

**Evidence.** There is a maintained official validator: **`a2a-inspector`** (Apache-2.0, A2A Project Team). It fetches a card and "detects whether it's v1.0 or v0.3, lists its interfaces and checks it against the specification", validates **every response** on the wire, exercises all three bindings, and covers get/list/cancel/subscribe plus push-notification configs and an inbound webhook receiver. It also renders an `ai-catalog.json` and can serve as a host agent that picks agents by their cards.

That last point matters: the inspector's *host agent* is a free test of whether our card is legible enough for another agent to choose us.

**Recommendation.**

1. **Make the inspector the conformance gate**, run against a local or staging site in the P2 acceptance step. Do not hand-roll a conformance suite first; hand-roll only the assertions the inspector cannot see (capability↔route equivalence, authorization scoping).
2. **Assert capability↔route equivalence in a unit test.** For every `capabilities.*` flag emitted, a matching route must exist; for every A2A operation route registered, the card must declare it. This is the defect in §168.1.3 and it will regress without a test.
3. Serve the canonical `/.well-known/agent-card.json` and 301 `/.well-known/agent.json`. Cache with `ETag` from the card `version` and honour `If-None-Match` — the spec asks for it (§8.6) and peers cache cards anyway.
4. Consider JWS signing (§8.4) in P2, not P4. A mesh of mutually-unknown WordPress sites that trusts unsigned cards trusts whoever writes the card. The cost is one canonicalisation helper + a key store; the alternative is a mesh that cannot tell a peer from an impostor.
5. Note the inspector also consumes `/.well-known/ai-catalog.json` — a natural fit for `WP_MCP_AI_Mesh_Router`'s peer list later, and worth an explicit "not in this proposal" note so it does not get invented ad hoc.

**Confidence:** Verified.

## 168-R.10 Question 9 — What telemetry should the board and A2A emit?

**Evidence.** OpenTelemetry GenAI now has a **dedicated A2A convention** (`semantic-conventions-genai/docs/gen-ai/a2a.md`), plus MCP and per-provider conventions. The specifics that matter:

- Span name SHOULD be **`{a2a.method.name}`** — `SendMessage`, `GetTask`, `ListTasks`, `SubscribeToTask`, `CancelTask`, `GetExtendedAgentCard`, and the four push-config methods.
- Required attribute: `a2a.method.name`. Conditionally required: `a2a.task.id`, `a2a.task.state`, `a2a.tenant`, `a2a.message.id`. Recommended: `a2a.protocol.version`, `a2a.message.reference_task_ids`.
- **`gen_ai.conversation.id` is populated from the A2A `contextId`.** Identity mapping is prescribed, not free-form.
- **Server side: enrich the HTTP server span and rename it to the A2A method name** rather than nesting an `a2a.server` span, when instrumentation can see the HTTP server.
- **`error.type` SHOULD carry the A2A error reason** — `google.rpc.ErrorInfo.reason`, domain `a2a-protocol.org`, e.g. `TASK_NOT_FOUND` — and instrumentation "SHOULD NOT infer an A2A error reason from an HTTP or gRPC status code alone."
- Async polling produces **sibling** spans correlated by `a2a.task.id`, not children of `SendMessage`.
- **All of it is marked Development**, not stable.

**Recommendation.**

1. Emit the `a2a.*` attributes as specified rather than inventing `mcp_ai.a2a.*`. Use span name = method name. Since our routes are REST, enrich the request span and rename it.
2. **Because `error.type` is defined as the `ErrorInfo.reason`, the error-envelope work in §168.4.4 is also the telemetry contract.** Emitting `ErrorInfo` is not extra scope; it is the same change counted once.
3. **Pin the conventions version** in a constant and emit behind a mapping layer, because the vocabulary is Development-status and will move. Never scatter `a2a.task.state` string literals through the codebase.
4. Add our own board attributes (`mcp_ai.run_id`, `node_id`, `role`, `depth`, `lease_age`) alongside — the conventions do not cover a private coordination board, and these are what make the run's shape visible.
5. Board-derived metrics are the dashboard: contention, depth histogram, lease expiries, tokens per landed node. Do not build a separate metrics pipeline.

**Confidence:** Verified.

## 168-R.11 Question 10 — Cost, effort, and when not to fan out

**Evidence.** Anthropic's numbers are the only public, honest baseline: a single agent uses ~4× chat tokens, a multi-agent system ~15×; token usage alone explains ~80% of performance variance on BrowseComp, and three factors together explain 95%. The same post is explicit that **most coding tasks have fewer parallelizable pieces than research**, that agents are "not yet great at coordinating and delegating in real time," and that synchronous subagents are a bottleneck. MAST's counterpart is the blunt advice to "prefer one agent until it breaks."

**Recommendation.**

1. **Default to one agent.** The board is for work that is genuinely parallel or exceeds one context window. Ship a guard: a run with fewer than 2 concurrently-claimable nodes at any point is reported, and the admin panel says "this run did not need a team."
2. **Declare the effort tier** (`simple` / `comparison` / `complex`) on the run and derive agent count and per-node tool-call ceilings from it. Do not let the lead choose its own scale — that is the "spawning 50 subagents for a simple query" failure Anthropic names explicitly.
3. **Budget from the existing cost tracker**, not a second ledger. `WP_MCP_AI_Cost_Tracker::record()` already exists; the run budget is a ceiling read from it, and exhaustion is a board state (`blocked` + reason), not an exception mid-turn.
4. Report **tokens per landed node** as the efficiency metric, so the 15× is visible per unit of finished work rather than as a shock at the end.

**Confidence:** Verified for the figures; Judgement for the thresholds.

## 168-R.12 Resolved decisions

The proposal's §168.9 asked five questions. The evidence answers them:

| # | Decision | Recommendation | Why | Confidence |
|---|---|---|---|---|
| 1 | Board storage | **Custom tables** (`{prefix}mcp_run_tasks`, `{prefix}mcp_run_messages`) via `dbDelta` + a version option, mirroring `NV_oOS_Graphify_DB` | Atomic conditional `UPDATE` needs indexes and affected-rows semantics; CCT inherits JetEngine coupling. `WP_MCP_AI_Concurrency_Guard` already ships a plugin-owned table with a TTL sweeper | Verified |
| 2 | Task-store migration | **One-shot migration + read-through shim** | Options store is capped at 500 rows, so the migration surface is provably small; dual-write doubles the inconsistency window for no benefit | Judgement |
| 3 | Legacy `agent.json` window | **One minor version**, then 404 with `VersionNotSupportedError` | Spec asks for legacy anchors to stay resolvable; a permanent 301 hides broken clients forever | Supported |
| 4 | Captain in P0 | **Yes, ship in P0** | The source run promoted captains within the first hour of 28; depth-1 fan-out without a local coordinator is the known bottleneck | Supported |
| 5 | Default lease | **2 h at depth ≤2, 30 min below**, per-run configurable, hard ceiling per node | Agent-board literature's 2 h default; shallow nodes are coordination, deep nodes are work and should recycle faster | Supported |

Three decisions the research **adds**, which the proposal should adopt:

| # | Decision | Recommendation |
|---|---|---|
| 6 | Claim mechanism | Conditional `UPDATE` + affected rows, **never** `SKIP LOCKED`; `KEY claim (state, lease_expires_at)`; `EXPLAIN` assertion in CI |
| 7 | Policy-gate integration | `WP_MCP_AI_Run_Scope_Gate` on the existing `wp_mcp_ai_before_tool_execution` hook + `WP_MCP_AI_Chat_Profile_Blocked`, registered between the read-only and destructive gates |
| 8 | Telemetry vocabulary | Native `a2a.*` / `gen_ai.*` attribute names with a version-pinned mapping; `error.type` = `ErrorInfo.reason` |

### 168-R.12.1 Adopted from Proposal 167 (scoped database toolkit, PR #8431, 2026-11-28)

| # | Decision | Recommendation |
|---|---|---|
| 9 | Board ↔ 167 boundary | **Board stays on `$wpdb`.** 167 is base-files/Pro-runtime and PHP 8.2+; the board is Base and must run on 8.1. Depending on it would break Base installs and put a two-phase approval inside a race |
| 10 | Board integrity | **Assert, do not assume.** `RefusalCode::SITE_DATABASE_REFUSED` already makes the WordPress/Laravel application database unscopable, so `db_apply_write` cannot reach board tables. Add a P0 isolation test, and ask 167 for a reserved-relation prefix for second connections (staging copies, writable replicas) |
| 11 | Reused patterns | Closed refusal enum (`RefusalCode` → `Board_Refusal`), **non-transitive** capabilities (`GrantTier` → board scopes; unknown values narrow, never widen), signed keyset cursors (`CursorCodec` → board paging) |

This is stricter than the industry norm, which points agents at a read replica rather than refusing the application database: *"prompts that say 'only run SELECT' are instructions, not permissions; grants, read-only transactions and row policies are."* For a plugin whose database is the customer's data, outright refusal is the correct call.

## 168-R.13 What the evidence says *not* to do

1. **Do not build a message bus.** Both the source run and Anthropic's production system converge on shared state plus references. Adding chat re-introduces the failure the board exists to remove.
2. **Do not use `SKIP LOCKED` as the default claim.** It is version-gated, it wants a queue-only table, and the portable form is already the house style here.
3. **Do not trust an agent's "done".** Lean checked the proof; our equivalent is a verifier per node, with its own failing fixture.
4. **Do not let the lead size the team.** Declared effort tier, derived agent count (Anthropic's over-spawn failure).
5. **Do not put an LLM classifier on the hot path by default.** A million calls, zero blocks — the boundary did the work, not the classifier.
6. **Do not treat the board as a reliability cure.** MAST's own intervention result (+15.6%) is the ceiling for structural fixes alone; expect fewer specific failures, not a reliable fleet.
7. **Do not scatter a Development-status vocabulary.** Pin the semconv version; the `a2a.*` names will change.
8. **Do not raise the plugin's WordPress floor casually.** `%i` in `$wpdb->prepare()` is WP 6.2+, the header says `Requires at least: 6.0`. New table code should use the constant-table-name + `PreparedSQL.InterpolatedNotPrepared` form already used in `class-nvoos-checkout-api-license-store.php`, or raise the floor deliberately. (Existing `%i` use in `NV_oOS_Graphify_DB::uninstall()` is a latent 6.0/6.1 hazard — flag it, do not fix it in this scope.)

## 168-R.14 Gaps and how to close them

| Gap | Why it matters | Next step |
|---|---|---|
| No public benchmark for *this* workload | Anthropic's 90.2% is research, not WordPress ops; our tasks may be less parallel | Build the 20-scenario MAST-shaped set first, measure single-agent vs team before scaling |
| MariaDB `SKIP LOCKED` floor unverified | Only matters if someone tries to optimise the claim | The recommendation avoids it entirely; if pursued, test on the oldest supported MariaDB |
| A2A 1.0 `ai-catalog.json` not evaluated | Possible future mesh discovery path | Out of scope; note it so it is not invented ad hoc |
| Judge-verifier calibration unknown | A judge that always passes is worse than no verifier | Run the judge against known-bad artifacts during P0; publish agreement rate |
| Semconv churn | Development-status attributes may rename | Mapping layer + pinned version; revisit quarterly with the model-config refresh |
