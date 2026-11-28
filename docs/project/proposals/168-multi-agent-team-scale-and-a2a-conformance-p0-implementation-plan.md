# 168-P0 — Run Board implementation plan (Phase P0)

**Date:** 2026-11-28
**Status:** 📋 Proposed — awaiting approval
**Estimated effort:** ~16 dev-days (14 build + 2 verification buffer)
**Priority:** HIGH — this is the phase that changes what the system can do
**Implements:** [168](./168-multi-agent-team-scale-and-a2a-conformance-proposal.md) §168.5 (P0 row)
**Contract:** [168-S](./168-run-board-schema.md) — schema, indexes, claim SQL, proofs
**Evidence:** [168-R](./168-multi-agent-team-scale-and-a2a-conformance-research.md)

---

## Executive summary

P0 delivers a durable, claimable coordination board — the substrate the Fable 5.2 run built for itself in its first ten minutes and ran on for 28 hours. It is **standalone**: the board exists, is usable through eight tools, and is proven correct under contention. It does not yet rewire `create_agent_team` / `delegate_to_agent` / `manage_autonomous_session` (that is P1), and it does not yet enforce scope intersection (that is P3).

The one rule that matters: **a tool call is a request; a board row is a fact.** P0 is complete when a second process cannot steal a claimed node, an expired lease returns the node to the pool, and a run resumes after a forced restart without duplicating work.

## Scope

### In scope

1. Three InnoDB tables (`mcp_ai_runs`, `mcp_ai_run_tasks`, `mcp_ai_run_messages`) with the indexes specified in [168-S §168-S.2–3](./168-run-board-schema.md).
2. A run envelope (`run_id`, `role`, `crew`, budget, board scopes) that travels in the tool `$context`.
3. A board service with twelve operations (create run, add root tasks, claim, claim-next, renew, release, split, land, note, announce, assign, status).
4. Eight tools exposing the service to agents.
5. Role enforcement at the tool boundary: `lead` vs `captain` vs `helper`.
6. A sweeper (Action Scheduler, cron fallback) that expires leases, blocks three-strike nodes, and prunes by retention.
7. Two proofs: `EXPLAIN` plan assertions and a real two-connection race harness.
8. An asserted isolation invariant against the scoped database toolkit (Proposal 167) — the board tables must be unreachable through `db_*`.

### Out of scope (deferred, with the phase that owns it)

| Deferred | Phase |
|---|---|
| Wiring the existing orchestration tools to seed/claim board rows | P1 |
| Moving team + virtual-agent state off transients | P1 |
| Token and tool-call budget counters (needs the cost tracker) | P1 |
| A2A 1.0 conformance, card, routes, task table | P2 |
| Delegation credentials, scope intersection, Tier-2 deny rules | P3 |
| OTel spans, run dashboard, offline review | P4 |

**P0 is not a security boundary.** Role is enforced against the *authenticated* caller. A caller who can already run tools can claim work, because claiming work is the point. P3 turns "who says you're a helper" into "the platform says so".

---

## Deliverables and file map

```
includes/run-board/
  README.md                                              (folder README — repo convention)
  class-wp-mcp-ai-run-board-schema.php                   DDL, version option, install/upgrade/uninstall
  class-wp-mcp-ai-run-board.php                          the service — 12 public operations
  class-wp-mcp-ai-run-envelope.php                        envelope construction + validation
  class-wp-mcp-ai-board-refusal.php                       closed refusal enum (describe / isRetryable)
  class-wp-mcp-ai-run-board-cursor.php                    HMAC-signed keyset cursor
  class-wp-mcp-ai-run-board-retention.php                 batched prune

includes/security/
  class-wp-mcp-ai-run-scope-gate.php                      role enforcement on the existing tool hook

includes/tools/
  class-wp-mcp-ai-tool-board-announce.php
  class-wp-mcp-ai-tool-board-assign.php
  class-wp-mcp-ai-tool-board-note.php
  class-wp-mcp-ai-tool-board-claim.php
  class-wp-mcp-ai-tool-board-renew.php
  class-wp-mcp-ai-tool-board-split.php
  class-wp-mcp-ai-tool-board-land.php
  class-wp-mcp-ai-tool-board-status.php

bin/
  run-board-race.php                                      real-concurrency harness

tests/
  test-run-board-schema.php                               DDL, upgrade, uninstall, EXPLAIN assertions
  test-run-board.php                                      claim/renew/release/split/land semantics
  test-run-board-idempotency.php                          replayed split + note
  test-run-board-roles.php                                lead/captain/helper refusals
  test-run-board-paging.php                               keyset cursor, limits, replay refusal
  test-run-board-resume.php                               checkpoint/reopen drill
  test-run-board-retention.php                            batched prune, delete order
  tests/security/test-run-scope-gate.php                  hook ordering + block semantics
  tests/security/test-board-db-toolkit-isolation.php      board tables unreachable via db_*
```

Registration: `includes/bootstrap/loader.php` (eager `require_once` block, as `includes/a2a/` does); tools in `includes/tools-init.php`.

> **Folder README convention.** `includes/run-board/README.md` is required by `composer run docs:check-folder-readmes` (part of `ci:all`). It must declare purpose, public surface, neighbours, and which `.context/*.md` files to load.

---

## Locked decisions

From [168-R §168-R.12](./168-multi-agent-team-scale-and-a2a-conformance-research.md#168-r12-resolved-decisions) — these are not re-litigated here:

| Decision | Value |
|---|---|
| Storage | Custom tables, `dbDelta`, version option |
| Claim | Conditional `UPDATE` + affected rows; **no `SKIP LOCKED`** |
| Lease | 2 h at depth ≤2, 30 min below, per-run override, 12 h hard ceiling |
| Time | UTC, computed in PHP, bound as a value; never `NOW()` |
| Idempotency | `UNIQUE (parent_id, child_key)` for split; `UNIQUE (run_id, node_id, client_msg_id)` for notes |
| Counters | `attempts` and `renewals` are strictly monotonic — they are the correctness signal, not bookkeeping |
| Captain | Ships in P0 |
| Gate | `WP_MCP_AI_Run_Scope_Gate` on `wp_mcp_ai_before_tool_execution`, between the read-only and destructive gates |

---

## Dependency: Proposal 167 (scoped database toolkit, PR #8431)

PR #8431 adds scoped, audited SQL access for agents (`db_select`, `db_query`, `db_propose_write`, `db_apply_write`, …). It does **not** change the board's architecture, and the board must not depend on it:

| Constraint | Proposal 167 | Run Board |
|---|---|---|
| Tier | base files, **Pro runtime** (`WP_MCP_AI_Db_Runtime::load()`) | Base; must run without Pro |
| Connection | its own PDO factory | the WordPress `$wpdb` connection |
| Writes | propose → approve → apply | atomic conditional `UPDATE` on one row |

Depending on it would break Base installs and put a two-phase approval inside a 200-way race. (PHP is a secondary difference — 167 requires 8.2, the plugin floor is 8.1 per [Proposal 170](./170-php-floor-decision.md) — and the argument never needed it.)

What it **does** change is the threat model: a toolkit that can run agent-authored SQL could, in principle, write board rows directly and bypass claim, lease, depth cap and verifier. That is already closed — `RefusalCode::SITE_DATABASE_REFUSED` makes the WordPress application database unscopable at all — but P0 must **assert** it rather than assume it, and record the forward constraint. Full analysis in [168-S §168-S.8](./168-run-board-schema.md).

Three patterns are adopted from 167 because they are better than what this plan originally specified:

1. **A closed refusal enum** (`WP_MCP_AI_Board_Refusal`) with `describe()` and `isRetryable()`, replacing ad-hoc `board_*` strings.
2. **Non-transitive capabilities** — named, never inherited; an unknown capability is dropped, never granted.
3. **Signed keyset cursors** for every list (`WP_MCP_AI_Run_Board_Cursor`), binding `run_id` + order + direction.

---

## Phase P0.1 — Schema and install

**Files:** `class-wp-mcp-ai-run-board-schema.php`

**Tasks**

1. Constant block: table names, `DB_VERSION`, policy defaults ([168-S §168-S.1](./168-run-board-schema.md)).
2. Three `CREATE TABLE` strings, written to `dbDelta`'s formatting rules ([168-S §168-S.2](./168-run-board-schema.md)). Plain `CREATE TABLE`, not `IF NOT EXISTS`.
3. `install()` — `dbDelta` × 3, then `update_option( DB_VERSION_OPTION, DB_VERSION, false )`.
4. `maybe_upgrade()` on `plugins_loaded` — version compare, so a background run upgrades its own schema before writing.
5. `uninstall()` — `DROP TABLE IF EXISTS` × 3 in dependency order.
6. `table_exists( $name )` helper used by the tools' `is_available()`-style guards.

**Gate P0.1**

- `SHOW TABLES` finds all three after activation.
- A second `install()` is a no-op (`dbDelta` idempotency).
- Upgrade from "no version option" to `1` runs cleanly.
- Uninstall drops all three and leaves no orphan option.
- phpcs clean, including `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` annotations on the constant-name DDL.

## Phase P0.2 — Run envelope

**Files:** `class-wp-mcp-ai-run-envelope.php`

**Shape** (per [168 §168.4.3](./168-multi-agent-team-scale-and-a2a-conformance-proposal.md)):

```php
array(
    'run_id'       => 'uuid',
    'role'         => 'lead'|'captain'|'helper',
    'crew'         => '',
    'can_assign'   => bool,
    'can_split'    => bool,
    'can_verify'   => bool,
    'budget'       => array( 'tokens' => 0, 'tool_calls' => 0, 'wall_clock_s' => 0 ),
    'board_scopes' => array( 'read:run', 'write:notes', 'claim:tasks' ),
)
```

**Tasks**

1. `from_run( array $run, string $agent_id ) : array` — build from the run row.
2. `validate( array $envelope ) : true|WP_Error` — reject unknown roles, missing `run_id`, non-array budget.
3. `to_context( array $context, array $envelope ) : array` — attach under `$context['run']`.
4. `agent_id_from_context( array $context ) : string` — resolve from the authenticated caller (assistant id / user id / A2A peer id). Deterministic and stable across requests; never from arguments.

**The one prohibition.** `role` is **never** read from tool arguments. If an argument named `role` arrives it is ignored, and the attempt is logged as a security event. This is the seed of P3's scope intersection, and the behaviour is asserted now so P3 cannot regress it.

**Gate P0.2**

- `from_run()` round-trips the values stored in `mcp_ai_runs`.
- `validate()` rejects a forged role, a missing `run_id`, and a string budget.
- A tool call carrying `role=lead` while the caller is a helper does **not** gain lead authority (asserted in `tests/security/test-run-scope-gate.php`).

## Phase P0.3 — The board service

**Files:** `class-wp-mcp-ai-run-board.php`

**Public operations**

| Operation | Signature | Notes |
|---|---|---|
| `create_run` | `( array $args ) : array\|WP_Error` | inserts the run row; caller becomes `created_by` |
| `add_root_tasks` | `( string $run_id, array $tasks ) : array\|WP_Error` | `child_key` = `root:N`; rejects empty keys |
| `claim` | `( string $task_id, string $agent_id, string $role ) : array\|WP_Error` | the conditional `UPDATE` |
| `claim_next` | `( string $run_id, string $agent_id, string $role ) : array\|WP_Error` | select-then-claim; losing is a normal `WP_Error` with code `board_claim_lost` |
| `renew` | `( string $task_id, string $agent_id ) : array\|WP_Error` | increments `renewals`; enforces the ceiling |
| `release` | `( string $task_id, string $agent_id, string $reason ) : array\|WP_Error` | → `open`, does not reset `attempts` |
| `split` | `( string $task_id, string $agent_id, array $children ) : array\|WP_Error` | enforces depth + `max_nodes`; idempotent on `child_key` |
| `land` | `( string $task_id, string $agent_id, array $result ) : array\|WP_Error` | requires a verifier verdict; stores `artifact_ref`, not payload |
| `note` | `( string $run_id, string $node_id, array $args ) : array\|WP_Error` | idempotent on `client_msg_id` |
| `announce` | `( string $run_id, string $body, string $lead_id ) : array\|WP_Error` | lead only |
| `assign` | `( string $run_id, array $assignments, string $lead_id ) : array\|WP_Error` | lead only; advisory by default |
| `status` | `( string $run_id, array $args ) : array` | read-only: run row, node counts, open nodes, thread for one node |

**Implementation rules**

1. **Canonical envelope.** Every operation returns a success array or `WP_Error` — never `array( 'success' => false, … )`. The `WPMCPAI.Tools.CanonicalReturnEnvelope` sniff enforces this at severity 5.
2. **Two-gate sanitisation.** Sanitise every `$arguments[...]` at entry; escape at exit. `WPMCPAI.Tools.SanitizeAtEntry` enforces it.
3. **No direct SQL outside this class and the schema class.** Tools never touch `$wpdb`.
4. **Prepared statements only**, with the table name from a class constant and the phpcs annotation — the `class-nvoos-checkout-api-license-store.php` form (WP 6.0-safe; `%i` needs 6.2).
5. **`split` and `note` are idempotent by construction**, not by convention: the unique keys do the work, and a duplicate-key error is translated into "already applied" with the existing row returned.
6. **`land` requires a verifier verdict** in `$result['verifier']` (`kind`, `passed`, `evidence`). A `land` without one is a `WP_Error`, never an implicit pass.
7. **Depth and node caps are read from the run row**, not from constants, so a run can be scoped per task class.
8. **Failures are a closed enum.** `WP_MCP_AI_Board_Refusal` mirrors 167's `RefusalCode`: every failure resolves to a stable code with `describe()` and `isRetryable()`. `claim_lost` is retryable — losing a race is normal, the caller re-selects — while `lease_expired`, `not_holder`, `depth_exceeded` and `verifier_required` are not. Tests assert the code, never the message.
9. **Every list is bounded and cursored** ([168-S §168-S.4.4](./168-run-board-schema.md)): row, payload-byte, per-cell-byte and deadline limits, with an HMAC-signed keyset cursor binding `run_id` + order + direction. No operation returns a whole run.

**Gate P0.3**

- All twelve operations covered by `tests/test-run-board.php`.
- `claim` on an `open` node returns the node; a second claim returns `board_claim_lost`.
- `claim` on an expired lease succeeds and bumps `attempts`.
- `renew` twice in the same second returns success both times (the counter rule, [168-S §168-S.3.1](./168-run-board-schema.md)).
- `split` beyond `max_depth` or `max_nodes` is refused with a typed error.
- `land` without a verifier verdict is refused.
- phpcs green on both custom sniffs.

**Note on budget scope.** P0 enforces `max_depth`, `max_nodes`, and `deadline_at`. The token and tool-call counters exist as columns and are written by P1 when the run is wired to `WP_MCP_AI_Cost_Tracker`. Enforcing them in P0 would mean a second cost ledger, which the research explicitly warns against.

## Phase P0.4 — The eight tools

**Files:** one class per tool in `includes/tools/`, each delegating to the service (never re-implementing).

| Slug | Min role | Purpose | Idempotency |
|---|---|---|---|
| `board_status` | helper | run row, open/claimed/blocked counts, one node's thread + lineage — **paged, never whole-run** | read-only |
| `board_claim` | helper | claim a named node, or `claim_next` for the pool | state transition |
| `board_renew` | holder | extend the lease | `renewals` counter |
| `board_note` | helper | plan, progress, difficulty verdict | `client_msg_id` |
| `board_split` | helper (own node) | decompose an owned node into children | `child_key` |
| `board_land` | holder | submit a verified result | state transition |
| `board_assign` | **lead** | tell the team who is expected where | advisory, counted |
| `board_announce` | **lead** | broadcast an order or a block notice | `client_msg_id` |

**Definition shape** (matching `delegate_to_agent`, which is the nearest neighbour):

```php
public function get_definition() {
    return array(
        'name'                  => $this->get_name(),
        'description'           => $this->get_description(),
        'toolkit'               => 'workflow_automation',
        'pattern_compatibility' => array( 'hierarchical' ),
        'profession_tags'       => array( 'project_manager' ),
        'risk_level'            => 'standard',
    );
}

public function get_required_capability() {
    return 'edit_posts';
}
```

**`board_status` must return the lineage**, not just the node: `spec` + ancestors' specs + the node's message thread. This is the MAST FC2 mitigation — a resumed agent must be able to reconstruct intent without asking a peer ([168-R §168-R.2](./168-multi-agent-team-scale-and-a2a-conformance-research.md)).

**Gate P0.4**

- All eight appear in the registry with valid `get_definition()` and `get_parameters_schema()`.
- JSON Schema validates in the repo's schema helper (`WP_MCP_AI_Tool_Schema_Helper`).
- A helper calling `board_assign` / `board_announce` gets a `WP_Error`, not a silent no-op.
- `board_status` on a depth-3 node returns three ancestor specs plus the thread.
- Tool-count bookkeeping updated (`docs/tool-reference.md`, `AGENTS.md` counts) — 8 base tools.

## Phase P0.5 — The run-scope gate

**Files:** `includes/security/class-wp-mcp-ai-run-scope-gate.php`

**Tasks**

1. Register on `wp_mcp_ai_before_tool_execution` **after** `WP_MCP_AI_Read_Only_Profile_Gate` and **before** `WP_MCP_AI_Destructive_Ops_Gate`.
2. If `$context['run']` is absent, return without effect — the gate is inert outside a run.
3. If present, enforce: `can_assign` for `board_assign`/`board_announce`; `claim:tasks` for `board_claim`; `write:notes` for `board_note`; holder-only for `board_renew`/`board_land`/`board_split`.
4. Block by throwing `WP_MCP_AI_Chat_Profile_Blocked` — the exception every caller already catches.
5. Determine block severity by role: a block on the **lead** writes a `control` message and marks the run `frozen`; a block on a **helper** releases that helper's leased nodes back to `open` and removes it from the run.
6. Write every block to `WP_MCP_AI_Security_Audit_Logger` with `run_id`, `agent_id`, `node_id`, `tool_slug`.

**Gate P0.5**

- Gate ordering asserted: a run-scoped helper cannot widen a read-only chat profile, and a destructive tool still prompts for confirmation inside a run.
- Lead block freezes the run; helper block returns nodes to `open` (both asserted, both directions).
- Every refusal produces exactly one audit event and one `control` message.

## Phase P0.6 — Sweeper and retention

**Files:** `class-wp-mcp-ai-run-board.php` (`sweep()`), `class-wp-mcp-ai-run-board-retention.php`

**Tasks**

1. Recurring Action Scheduler action `wp_mcp_ai_run_board_sweep`, every 5 minutes, `function_exists( 'as_schedule_recurring_action' )` guard with the `WP_MCP_AI_Cron_Manager` fallback already used elsewhere in the repo.
2. `sweep()` runs the single-statement expiry update ([168-S §168-S.4.3](./168-run-board-schema.md)) — released nodes go straight to `blocked` at `attempts >= 3`, never back to `open` and then blocked.
3. Retention: batched deletes (`PRUNE_MAX_BATCHES = 50`, `PRUNE_BATCH_SIZE = 500`), messages → tasks → runs, matching `WP_MCP_AI_Execution_History_CCT::run_retention_sweep()`.
4. `sweep()` returns counts (`expired`, `blocked`, `pruned`) and logs them once per run of the sweep, not per row.

**Gate P0.6**

- An expired lease returns to `open`; the third expiry lands on `blocked`, not `open`.
- `sweep()` is idempotent — running it twice changes nothing the second time.
- Retention deletes in batches and never leaves a message orphaned by its task.
- Re-activating the plugin does not duplicate the scheduled action (`as_has_scheduled_action` guard).

## Phase P0.7 — Proofs, drills, and the P0 gate

**Files:** `bin/run-board-race.php`, the six test files

**Tasks**

1. `tests/test-run-board-schema.php` — the `EXPLAIN` assertions from [168-S §168-S.5.1](./168-run-board-schema.md), including 2,000 seeded rows and `ANALYZE TABLE`.
2. `bin/run-board-race.php` — N claimant processes, M nodes, prints `claimed N/N, 0 double-claims, 0 unclaimed`, exit code 1 on any violation.
3. Two-connection PHPUnit race, **both orderings**, with `suspend_temporary_table_rewrite()` (a temporary table is per-connection and would make the test vacuous).
4. Idempotency suite: replayed `split` and replayed `note` produce no duplicates.
5. Resume drill: create a run, claim 5 of 10 nodes, simulate a hard stop (new process, new `$wpdb`), sweep, resume, land everything. Assert: no node landed twice, no node unclaimed, and the final board is identical to a straight-through run.
6. Staging drill: 10 agents, 30 minutes, forced restart at minute 15.
7. Isolation proof: `tests/security/test-board-db-toolkit-isolation.php` — with Proposal 167 present, create a scope holding `apply_write`, attempt a write to `mcp_ai_run_tasks`, and assert `db_site_database_refused` (or `db_scope_table_not_allowed`). Skip with a clear marker when the Pro kernel is absent, so Base CI stays green.

**Gate P0.7 — the P0 acceptance from [168 §168.5](./168-multi-agent-team-scale-and-a2a-conformance-proposal.md)**

- [ ] 200 race iterations, exactly one winner every time
- [ ] lease expiry returns the node to the pool
- [ ] a 10-agent run resumes after a forced restart with **zero duplicate landings**
- [ ] `EXPLAIN` assertions green and stable over 10 consecutive runs
- [ ] `board_status` refuses a cursor replayed against another run or a different sort
- [ ] board tables are unreachable through `db_*` (or the test is skipped with a marker when 167 is absent)
- [ ] staging drill completed with the run report attached to the PR

> The proposal's "10 agents over 6 hours" is the production acceptance criterion. The 30-minute staging drill is the CI proxy; the 6-hour run is scheduled once, against staging, before P1 begins.

---

## Test plan summary

| Suite | Proves | Kind |
|---|---|---|
| `test-run-board-schema.php` | DDL, upgrade, uninstall, **query plans** | Unit + DB |
| `test-run-board.php` | claim / renew / release / split / land semantics | Unit + DB |
| `test-run-board-idempotency.php` | replayed split and note are no-ops | Unit + DB |
| `test-run-board-roles.php` | lead / captain / helper refusals | Unit |
| `test-run-board-resume.php` | checkpoint + reopen, no duplicate landings | Integration |
| `test-run-board-paging.php` | keyset cursor, limits, replay refusal | Unit |
| `test-run-board-retention.php` | batched prune, delete order | Unit + DB |
| `tests/security/test-run-scope-gate.php` | hook ordering, forged role, block semantics | Security |
| `tests/security/test-board-db-toolkit-isolation.php` | board tables unreachable via `db_*` | Security |
| `bin/run-board-race.php` | **real** InnoDB contention | Harness |
| MAST-shaped eval set | end-state success, not step matching | Eval (P0.7 optional) |

Every custom-table suite calls `suspend_temporary_table_rewrite()` and creates its table outside the per-test transaction; both are load-bearing and both have precedent in `plugins/nvoos-content-graph-ai-platform/tests/`.

---

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| `EXPLAIN` test flaky on an empty table | High if unaddressed | Seed 2,000 rows, `ANALYZE TABLE`, assert `possible_keys` as well as `key` |
| Optimiser declines the index on a small staging board | Medium | The `possible_keys` assertion fails the build rather than the query silently scanning |
| A `renew` that returns `0` under load | Medium | The `renewals` counter is mandatory; the same-second test is in the gate |
| Test transaction hides the second connection | High if unaddressed | `suspend_temporary_table_rewrite()` + real table outside the transaction |
| `dbDelta` silently skips an index | Medium | `SHOW INDEX FROM` assertion for all four secondary keys in P0.1 |
| Board drifts into a second source of truth | Low in P0 | A2A task ids are stored as references only; P2 owns peer-visible state |
| Scope creep into P1 wiring | Medium | The in-scope table is binding; `delegate_to_agent` changes are refused in review |
| The scoped DB toolkit exposes board tables | Low (the site DB is refused outright) but high impact | `SITE_DATABASE_REFUSED` is the guarantee; the isolation test asserts it; a reserved-relation prefix is requested from 167 for second connections |

---

## Effort

| Phase | Dev-days |
|---|---:|
| P0.1 Schema and install | 1.0 |
| P0.2 Run envelope | 1.0 |
| P0.3 Board service | 4.5 |
| P0.4 Eight tools | 2.0 |
| P0.5 Run-scope gate | 1.5 |
| P0.6 Sweeper and retention | 1.0 |
| P0.7 Proofs and drills | 3.0 |
| **Build subtotal** | **14.0** |
| Verification buffer (EXPLAIN tuning, engine differences) | 2.0 |
| **Total** | **~16 dev-days** |

The buffer is not padding: the two-engine (`MySQL` / `MariaDB`) `EXPLAIN` difference and the small-table optimiser behaviour are the two most likely sources of a multi-day surprise, and both are known up front.

---

## Approval checklist

- [ ] Scope accepted as P0-only (no P1 wiring in this PR)
- [ ] WordPress floor decision recorded (`6.0` + constant table names, or raise to `6.2` for `%i`)
- [ ] `includes/run-board/README.md` included (folder-README gate)
- [ ] Tool-count bookkeeping included (`docs/tool-reference.md`, `AGENTS.md`)
- [ ] Staging drill scheduled before P1 starts
- [ ] Reviewers confirm the board is explicitly **not** a security boundary until P3
- [ ] Proposal 167's reserved-relation-prefix request is filed (board tables never scopable, even on a second connection)
- [ ] Proposal numbering resolved — `167` is currently used twice (this PR and an untracked `167-jepa-*` draft); one must move to `169` before either is committed
