# 168-S — Run Board schema and claim-path proofs

**Status:** Specification (2026-11-28)
**Implements:** [168](./168-multi-agent-team-scale-and-a2a-conformance-proposal.md) §168.4.2
**Evidence:** [168-R](./168-multi-agent-team-scale-and-a2a-conformance-research.md) §168-R.3, §168-R.4
**Plan:** [168-P0](./168-multi-agent-team-scale-and-a2a-conformance-p0-implementation-plan.md)

This document is the contract the board code is written against. If code and this document disagree, the document is wrong only by amendment — not by drift.

## 168-S.1 Naming and constants

Table names follow the repo's `mcp_ai_` prefix (`mcp_ai_concurrency_slots`, `mcp_ai_tenants`), not the proposal's shorthand. The proposal's `{prefix}mcp_run_*` is superseded.

```php
const RUNS_TABLE     = 'mcp_ai_runs';
const TASKS_TABLE    = 'mcp_ai_run_tasks';
const MESSAGES_TABLE = 'mcp_ai_run_messages';

const DB_VERSION_OPTION = 'wp_mcp_ai_run_board_db_version';
const DB_VERSION        = '1';

// Policy defaults (per-run overridable, never hard-coded at the call site).
const DEFAULT_MAX_DEPTH      = 10;
const DEFAULT_MAX_NODES      = 500;
const DEFAULT_LEASE_SECONDS  = 7200;   // depth <= 2
const DEFAULT_DEEP_LEASE     = 1800;   // depth  > 2
const MAX_ATTEMPTS           = 3;
const MAX_LEASE_HOURS        = 12;     // hard per-node ceiling
```

## 168-S.2 DDL

Written for `dbDelta`. Every formatting rule the function requires is load-bearing:

- each field on its own line
- **two spaces** between `PRIMARY KEY` and `(`
- `KEY`, never `INDEX`
- no backticks or quotes around field names
- field types lowercase, SQL keywords uppercase
- at least one `KEY` besides the primary key
- `$wpdb->get_charset_collate()` appended

Alignment padding between `KEY` and the index name is tolerated in practice — `NV_oOS_Graphify_DB` ships it and its tables install cleanly — but the documented rule is a single space, so treat padding as cosmetic and never rely on it.

Plain `CREATE TABLE` (not `IF NOT EXISTS`), matching `NV_oOS_Graphify_DB`, because that is the form with a proven upgrade path in this repo.

### 168-S.2.1 `mcp_ai_runs` — the run header

```sql
CREATE TABLE {$wpdb->prefix}mcp_ai_runs (
    id                char(36)           NOT NULL,
    title             varchar(191)       NOT NULL DEFAULT '',
    goal              longtext,
    status            varchar(20)        NOT NULL DEFAULT 'open',
    assignment_mode   varchar(20)        NOT NULL DEFAULT 'directed',
    runtime_version   varchar(32)        NOT NULL DEFAULT '',
    budget_tokens     bigint(20) unsigned NOT NULL DEFAULT 0,
    used_tokens       bigint(20) unsigned NOT NULL DEFAULT 0,
    budget_tool_calls bigint(20) unsigned NOT NULL DEFAULT 0,
    used_tool_calls   bigint(20) unsigned NOT NULL DEFAULT 0,
    deadline_at       datetime           DEFAULT NULL,
    max_depth         smallint(5) unsigned NOT NULL DEFAULT 10,
    max_nodes         int(10) unsigned   NOT NULL DEFAULT 500,
    checkpoint_at     datetime           DEFAULT NULL,
    created_by        bigint(20) unsigned NOT NULL DEFAULT 0,
    created_at        datetime           NOT NULL DEFAULT '1970-01-01 00:00:00',
    updated_at        datetime           NOT NULL DEFAULT '1970-01-01 00:00:00',
    PRIMARY KEY  (id),
    KEY          status (status, updated_at)
) {$charset_collate};
```

`budget_*`/`used_*` live here rather than being recomputed, because budget enforcement must read one row, not aggregate a board. `0` means "no ceiling", consistent with `WP_MCP_AI_Cost_Tracker`'s zero-means-unset convention.

### 168-S.2.2 `mcp_ai_run_tasks` — the work tree

```sql
CREATE TABLE {$wpdb->prefix}mcp_ai_run_tasks (
    id               char(36)            NOT NULL,
    run_id           char(36)            NOT NULL,
    parent_id        char(36)            NOT NULL DEFAULT '',
    depth            smallint(5) unsigned NOT NULL DEFAULT 0,
    child_key        varchar(64)         NOT NULL DEFAULT '',
    title            varchar(191)        NOT NULL DEFAULT '',
    spec             longtext,
    verifier         longtext,
    state            varchar(20)         NOT NULL DEFAULT 'open',
    claimed_by       varchar(64)         NOT NULL DEFAULT '',
    claimed_role     varchar(20)         NOT NULL DEFAULT '',
    lease_expires_at datetime            DEFAULT NULL,
    attempts         smallint(5) unsigned NOT NULL DEFAULT 0,
    renewals         smallint(5) unsigned NOT NULL DEFAULT 0,
    artifact_ref     varchar(191)        NOT NULL DEFAULT '',
    artifact_meta    longtext,
    closed_at        datetime            DEFAULT NULL,
    created_by       varchar(64)         NOT NULL DEFAULT '',
    created_at       datetime            NOT NULL DEFAULT '1970-01-01 00:00:00',
    updated_at       datetime            NOT NULL DEFAULT '1970-01-01 00:00:00',
    PRIMARY KEY  (id),
    UNIQUE KEY   parent_child (parent_id, child_key),
    KEY          claimable (run_id, state, depth, created_at),
    KEY          lease (state, lease_expires_at),
    KEY          board (run_id, depth, created_at)
) {$charset_collate};
```

**States:** `open` → `claimed` → `working` → `landed` | `blocked` | `failed` | `canceled`. `canceled` is terminal; `blocked` is re-openable only by the lead.

**`child_key` is never empty.** Roots get `root:1`, `root:2`, …; split children get a caller-supplied key. This is what makes `UNIQUE KEY parent_child (parent_id, child_key)` work: a retried `split` with the same keys is a no-op instead of duplicating the subtree. An empty-string sentinel on a *unique* key would collide across all roots — which is why roots must be named. (The index is named `parent_child`, not `child_key`, so an index and a column never share a name.)

**`claimed_by` and `child_key` are `varchar`, not nullable.** MySQL treats `NULL`s as distinct inside a unique index, so a nullable column silently defeats deduplication.

### 168-S.2.3 `mcp_ai_run_messages` — orders, notes, verdicts

```sql
CREATE TABLE {$wpdb->prefix}mcp_ai_run_messages (
    id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    run_id        char(36)            NOT NULL,
    node_id       char(36)            NOT NULL DEFAULT '',
    scope         varchar(20)         NOT NULL DEFAULT 'thread',
    intent        varchar(32)         NOT NULL DEFAULT 'note',
    author_id     varchar(64)         NOT NULL DEFAULT '',
    author_role   varchar(20)         NOT NULL DEFAULT '',
    body          longtext,
    client_msg_id varchar(64)         NOT NULL DEFAULT '',
    created_at    datetime            NOT NULL DEFAULT '1970-01-01 00:00:00',
    PRIMARY KEY  (id),
    UNIQUE KEY   client_msg (run_id, node_id, client_msg_id),
    KEY          thread (run_id, node_id, id),
    KEY          run (run_id, id)
) {$charset_collate};
```

- `scope`: `broadcast` (whole run) | `thread` (one node) | `control` (budget, blocks, time)
- `intent`: `order` | `assignment` | `note` | `verdict` | `progress` | `block` | `time`
- `node_id` is `''` for broadcasts — again so the unique key dedupes them.

## 168-S.3 Index rationale, query by query

This is the part that decides whether the board is fast or a table scan. Each index exists for exactly one query; nothing speculative.

| # | Query | Index | Expected plan |
|---|---|---|---|
| Q1 | Claim one node by id | `PRIMARY` | `type=const`/`range`, `key=PRIMARY` |
| Q2 | Pick claimable work (shallowest, oldest) | `claimable` | `key=claimable`, **no filesort** |
| Q3 | Sweep expired leases | `lease` | `key=lease` |
| Q4 | Board status / lineage for a run | `board` | `key=board`, no filesort |
| Q5 | Node thread | `thread` | `key=thread`, no filesort |
| Q6 | Run activity feed | `run` (messages) | `key=run`, no filesort |
| Q7 | Idempotent split | `parent_child` | duplicate-key on replay |
| Q8 | Idempotent note | `client_msg` | duplicate-key on replay |

**Q2 is the only query where the index order is doing real work.** With `claimable (run_id, state, depth, created_at)`, `WHERE run_id = ? AND state = 'open' ORDER BY depth, created_at` fixes the first two columns by equality and reads the remaining two in index order — the textbook ordered-suffix case, so no filesort. Change the `ORDER BY` and you silently lose it, which is why the assertion below exists.

**Q1 does not use `claimable`.** The by-id claim is a primary-key lookup; proposing a `claim (state, lease_expires_at)` index for it (as an earlier draft did) was wrong — a PK lookup cannot filesort. `lease` serves the sweeper, which is the query that actually scans by state.

### 168-S.3.1 The affected-rows soundness rule

`$wpdb->query()` returns the count of rows **actually changed**, and MySQL reports `0` when an `UPDATE` sets a row to the values it already holds. That makes "did I win the race?" wrong for any operation that might not modify bytes.

| Operation | Changes `state`? | Sound on affected rows alone? |
|---|---|---|
| `claim` | `open`/expired → `claimed` | Yes — state always changes |
| `land` | → `landed` | Yes |
| `release` | → `open` | Yes |
| **`renew`** | **No** | **No** — needs a monotonic counter |
| `assign` (advisory) | No | No — needs a counter or an unconditional return |

**Rule:** every operation whose verdict is read from the affected-row count must change at least one *strictly monotonic* column. `renew` therefore increments `renewals`; it must not rely on `updated_at`, because two renewals in the same second produce an identical timestamp and therefore `0` affected rows. This is the single most likely "works locally, fails under load" bug in the whole board.

### 168-S.3.2 Time is UTC, computed in PHP

Every lease timestamp is computed in PHP and bound as a value:

```php
$now       = gmdate( 'Y-m-d H:i:s' );
$expires   = gmdate( 'Y-m-d H:i:s', time() + $lease_seconds );
```

Never `NOW()` or `CURRENT_TIMESTAMP` inside board SQL. WordPress does not guarantee the MySQL session timezone, so `NOW()` is server-local while `gmdate()` is UTC; mixing them produces leases that are hours wrong on exactly the hosts nobody tests. `DEFAULT '1970-01-01 00:00:00'` on `created_at`/`updated_at` is a schema default only — application writes always supply a value. This also keeps MySQL 8 strict mode happy, the same trap documented in `class-nvoos-checkout-api-license-store.php`.

`DATETIME(6)` is available on MySQL 5.6.4+/MariaDB 5.3+ and would add sub-second resolution, but it buys nothing once §168-S.3.1 is obeyed, and it risks `dbDelta` type parsing. Stay on `DATETIME`; revisit only if ordering within a second ever matters.

## 168-S.4 The claim statement

```php
$sql = $wpdb->prepare(
    "UPDATE {$tasks}
        SET state = 'claimed',
            claimed_by = %s,
            claimed_role = %s,
            lease_expires_at = %s,
            attempts = attempts + 1,
            updated_at = %s
      WHERE id = %s
        AND ( state = 'open'
           OR ( state = 'claimed' AND lease_expires_at < %s ) )",
    $agent_id,
    $role,
    $expires,
    $now,
    $task_id,
    $now
);
$won = ( 1 === $wpdb->query( $sql ) );
```

`1` → claimed. `0` → someone else has it. Nothing else is a valid outcome; a value `> 1` means `id` is not unique, which is a schema fault, not a race, and must raise.

### 168-S.4.1 Claimable-work selection

```sql
SELECT id
  FROM {$tasks}
 WHERE run_id = %s AND state = 'open'
 ORDER BY depth ASC, created_at ASC
 LIMIT 1
```

Then claim that id with §168-S.4. Losing the race is expected and cheap — the agent re-selects. Do **not** attempt a single-statement `UPDATE … ORDER BY … LIMIT 1`: it cannot report which row it took.

`FOR UPDATE SKIP LOCKED` is deliberately absent: MySQL 8.0+/recent-MariaDB only, restricted by MySQL's own manual to queue-like tables, and unnecessary given the conditional update (see [168-R §168-R.3](./168-multi-agent-team-scale-and-a2a-conformance-research.md)).

### 168-S.4.2 Renew

```sql
UPDATE {$tasks}
   SET lease_expires_at = %s,
       renewals = renewals + 1,
       updated_at = %s
 WHERE id = %s AND state IN ('claimed','working') AND claimed_by = %s
```

`1` → renewed. `0` → not yours, or already terminal. The ceiling check is done in PHP before the write (`renewals >= MAX_LEASE_HOURS * 3600 / $lease_seconds`), so a wedged agent cannot hold a node forever.

### 168-S.4.3 Sweep

One statement, so a released node cannot be re-claimed between two writes:

```sql
UPDATE {$tasks}
   SET state = IF( attempts >= %d, 'blocked', 'open' ),
       claimed_by = '',
       claimed_role = '',
       lease_expires_at = NULL,
       updated_at = %s
 WHERE state = 'claimed' AND lease_expires_at < %s
```

`attempts` is not reset — that is what makes the third expiry terminal.

### 168-S.4.4 Paging and limits

`board_status` must not return a whole run. The source run reached 2,418 nodes and a thread can be longer still, so every list is bounded and cursored:

| Surface | Order | Cursor |
|---|---|---|
| node thread | `id ASC` | `id > $cursor` |
| run activity feed | `id ASC` | `id > $cursor` |
| open work | `depth ASC, id ASC` | `(depth, id) > ($d, $i)` |

Defaults, aligned with Proposal 167's `DatabaseLimits` so operators learn one vocabulary:

| Limit | Default |
|---|---:|
| rows per page | 500 |
| payload bytes | 2 MiB |
| per-cell bytes | 16 KiB |
| statement deadline | 5 s |

These are the same defaults Proposal 167 ships, so operators learn one vocabulary. The deadline is a **ceiling, not a target**: the claim in §168-S.4 is a single-row primary-key `UPDATE` and should complete in single-digit milliseconds. Track it at p95 and alert above 100 ms — a claim that approaches the deadline is a lock-wait problem, and a timeout would report it as a generic failure instead of pointing at the lock.

The cursor is opaque to the model and HMAC-signed with the run's secret, binding `run_id`, order and direction — the `CursorCodec` pattern from Proposal 167. A cursor replayed against another run, or against a different sort, is refused rather than silently reinterpreted.

`ORDER BY id` rather than `ORDER BY created_at` is deliberate: `id` is auto-increment and monotonic, so it is a stable keyset; `created_at` comes from several writers' clocks and is not.

## 168-S.5 Proving it: the two harnesses

Two different claims need two different proofs. Do not let one stand in for the other.

### 168-S.5.1 Plan proof — `EXPLAIN` assertions

`EXPLAIN` accepts `SELECT`, `UPDATE` and `DELETE` on both MySQL and MariaDB, and does not execute the statement. Build the statement with `$wpdb->prepare()` (which interpolates), then prefix it:

```php
private function explain_update( string $sql ): array {
    global $wpdb;
    $rows = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
    $this->assertNotEmpty( $rows, 'EXPLAIN returned no plan' );
    return $rows[0];
}
```

Assertions, chosen to survive both engines:

```php
// Q1 — by-id claim is a primary-key lookup.
$plan = $this->explain_update( $claim_sql );
$this->assertSame( 'PRIMARY', $plan['key'] ?? null );

// Q2 — claimable selection uses the ordered index and does not sort.
$rows = $wpdb->get_results( 'EXPLAIN ' . $pool_select_sql, ARRAY_A );
$plan = $rows[0];
$this->assertSame( 'claimable', $plan['key'] ?? null );
$this->assertStringNotContainsStringIgnoringCase(
    'filesort',
    (string) ( $plan['Extra'] ?? '' )
);

// Q3 — the sweeper uses the lease index.
$this->assertSame( 'lease', $this->explain_update( $sweep_sql )['key'] ?? null );
```

Deliberately **not** asserted:

- **`type`** — MariaDB annotates join types with `|filter` suffixes; MySQL does not. Equality on `type` is engine-specific and will break on the other stack.
- **`Using index`** — a covering-index optimisation that varies with `id` projection and engine version.
- **`rows`** — an estimate; unstable between `ANALYZE` runs.

**The flakiness trap, and how to avoid it.** On a nearly-empty table the optimiser may prefer a scan and report `key = NULL` even when the index exists. Before asserting, seed **2,000 rows** in chunked multi-row `INSERT`s and run `ANALYZE TABLE {table}` so statistics are not the default 8-page guess. Then assert `key` **and** — as a belt-and-braces check that survives an optimiser that still declines the index — that `possible_keys` contains it:

```php
$this->assertContains(
    'claimable',
    array_map( 'trim', explode( ',', (string) ( $plan['possible_keys'] ?? '' ) ) )
);
```

### 168-S.5.2 Behaviour proof — the race harness

`EXPLAIN` proves the plan; it proves nothing about atomicity.

**PHPUnit (deterministic, two connections).** The WP test framework rewrites every `CREATE TABLE` through `$wpdb->query()` into `CREATE TEMPORARY TABLE`. **A temporary table is per-connection — the second connection cannot see it at all**, so the race test is impossible until the rewrite is suspended. This is the same reason the Content Graph suites call `suspend_temporary_table_rewrite()`:

```php
private function suspend_temporary_table_rewrite(): void {
    remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
    remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
    global $wpdb;
    $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$wpdb->prefix}mcp_ai_run_tasks" );
}
```

Then open a genuinely independent connection:

```php
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$other->suppress_errors( true );
```

and assert `1 === $wpdb->query($claim)` and `0 === $other->query($claim)`. Also assert the mirror case in the same test (reverse the order) so an accidental "always the first caller wins" implementation cannot pass.

Test hygiene, all learned from existing suites in this repo:

- Create the real table **outside** the per-test transaction; MySQL 8's atomic DDL rolls a `CREATE TABLE` back with the transaction, and the table path then becomes untestable.
- Drop any `TEMPORARY` shadow first — MySQL prefers the temporary table over a real one of the same name and will silently divert the real `CREATE TABLE`.
- Truncate in `tearDown()`; the test transaction does not cover the second connection.

**CLI (real concurrency).** A `bin/` script spawns N processes against the dev database, each claiming the same node, and asserts exactly one winner:

```
$ php bin/run-board-race.php --tasks=50 --claimants=16
claimed 50/50 nodes, 0 double-claims, 0 unclaimed
```

Run it in CI's integration stage and once against MariaDB if that engine is supported. This is the only test that exercises real InnoDB contention; the PHPUnit pair exercises the SQL contract.

## 168-S.6 Install, upgrade, uninstall

Follow `NV_oOS_Graphify_DB` exactly:

```php
public static function install() {
    if ( ! function_exists( 'dbDelta' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    // dbDelta each of the three CREATE TABLE statements.
    update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
}

public static function maybe_upgrade() {
    if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
        self::install();
    }
}
```

`maybe_upgrade()` on `plugins_loaded` — not `admin_init` only, because a background run must upgrade its own schema before it writes.

`uninstall.php` drops the three tables via `DROP TABLE IF EXISTS`. Use `$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table )` **only if the plugin floor is raised to WP 6.2**; on `Requires at least: 6.0` use the constant-table-name form with the `PreparedSQL.InterpolatedNotPrepared` annotation already used in `class-nvoos-checkout-api-license-store.php`. (Existing `%i` use in `NV_oOS_Graphify_DB::uninstall()` is a latent 6.0/6.1 hazard — out of scope here, worth its own issue.)

## 168-S.7 Retention

Batched, never one huge `DELETE`, matching `WP_MCP_AI_Execution_History_CCT::run_retention_sweep()`:

```php
const PRUNE_MAX_BATCHES = 50;
const PRUNE_BATCH_SIZE  = 500;
```

- `landed`/`canceled` tasks older than the run's retention window are deleted
- messages follow their node, then their run
- whole runs are deleted only when `status` is terminal and past retention

Delete order is messages → tasks → runs. WordPress tables do not get real foreign keys here, so cascade is explicit code with a test.

## 168-S.8 Board integrity against the scoped database toolkit (Proposal 167)

PR #8431 (`167-database-toolkit-scoped-access`) adds scoped, audited SQL access for agents: thirteen `db_*` tools over connections, scopes, column masking, read-only transactions, and a two-phase write path (`db_propose_write` → human approval → `db_apply_write`). It is the right place for agent SQL, and the board must not become a second one.

### Why the board does not use it

| Constraint | 167 | Run Board |
|---|---|---|
| Tier | Base files, **Pro runtime** (`WP_MCP_AI_Db_Runtime::load()`) | Base; must run without Pro |
| Connection | its own PDO factory | the WordPress `$wpdb` connection |
| Writes | propose → approve → apply | atomic conditional `UPDATE` on one row |
| Purpose | read-biased exploration | contended claim/lease |

PHP is a secondary difference at most — 167 requires 8.2, while the plugin floor is 8.1 ([Proposal 170](./170-php-floor-decision.md) holds it there because four production servers still run it). It was never load-bearing: the tier, connection and write-model rows above are sufficient on their own, and they are what the argument rests on.

A board that depended on the toolkit would break Base installs and put a two-phase approval in the middle of a 200-way race for one node.

### The integrity invariant

**Board state changes only through the board service.** No agent may mutate `mcp_ai_run_tasks` or `mcp_ai_run_messages` by any other route, because a direct write bypasses the claim, the lease, the depth cap and the verifier.

That is guaranteed by 167 rather than by the board:

- **L1** — the toolkit's own connection identity and grants.
- **`RefusalCode::SITE_DATABASE_REFUSED`** (`db_site_database_refused`) — "The target resolves to the WordPress or Laravel application database." The site database is **not scopable at all**, so `db_apply_write` cannot reach board tables even with an `apply_write` tier.

This is stricter than the industry norm, which is to point agents at a read replica — *"prompts that say 'only run SELECT' are instructions, not permissions; grants, read-only transactions and row policies are."* Refusing the application database outright is the correct call for a plugin whose database *is* the customer's data.

### Two things the board must still do

1. **Assert the invariant, do not assume it.** `tests/security/test-board-db-toolkit-isolation.php` (P0.7) creates a scope holding `apply_write`, attempts a write to `mcp_ai_run_tasks`, and asserts `db_site_database_refused` (or `db_scope_table_not_allowed`). If 167 ever relaxes its refusal, this test fails first.
2. **Defend against second connections.** `SITE_DATABASE_REFUSED` keys on the *endpoint*, not the *relation*. A staging copy or a writable replica of the site database is a different endpoint and would be scopable. Recommendation to 167: extend the catalogue's existing system-schema exclusion (`mysql`, `performance_schema`, `sys`, `sqlite_*` are already excluded in SQL) with a **reserved relation prefix** — `mcp_ai_run_*`, `mcp_ai_concurrency_slots`, and the security audit table — refused as `TABLE_NOT_ALLOWED` at admission. One list, no new refusal code.

### Three patterns adopted from 167

| Pattern | 167's version | Board application |
|---|---|---|
| **Closed refusal enum** | `RefusalCode` — exhaustive `match`, `describe()`, `isRetryable()` | `WP_MCP_AI_Board_Refusal`: `claim_lost` (retryable), `lease_expired`, `not_holder`, `depth_exceeded`, `nodes_exhausted`, `verifier_required`, `role_denied`, … Replaces ad-hoc `board_*` strings so tests assert the reason, not the wording |
| **Non-transitive capabilities** | `GrantTier` — "`apply_write` does not imply `propose_write`"; unknown tiers dropped, never widen | Board capabilities are **named, not inherited**: `can_assign` does not imply `can_verify`; an unknown capability string is dropped, never granted |
| **Signed keyset cursors** | `CursorCodec` — HMAC-SHA256 over canonical JSON binding scope + relation + order + direction, `hash_equals` | Board cursors bind `run_id` + order + direction, so a cursor cannot be replayed against another run or a different sort (§168-S.4.4) |

`GrantTier`'s stated rationale is the same failure P3 exists to prevent — *"an agent inherits its orchestrator's scope by default, and transitivity turns one over-broad grant into a write primitive."* Adopting the shape now means P3 adds enforcement, not a redesign.

## 168-S.9 Acceptance checklist

- [ ] `dbDelta` produces all three tables; a second `install()` changes nothing
- [ ] `SHOW INDEX` reports all four secondary keys (`parent_child`, `claimable`, `lease`, `board`) and both message keys (`client_msg`, `thread`, `run`)
- [ ] `Q1` plan: `key = PRIMARY`
- [ ] `Q2` plan: `key = claimable`, `Extra` contains no `filesort` (2,000 seeded rows, `ANALYZE TABLE` run)
- [ ] `Q3` plan: `key = lease`
- [ ] 2,000-row `Q2` assertion is stable across 10 consecutive runs
- [ ] two-connection claim: exactly one winner, both orderings
- [ ] `bin/run-board-race.php --tasks=50 --claimants=16` → 50/50, 0 double-claims
- [ ] retried `split` with identical `child_key`s creates no duplicates
- [ ] retried `note` with an identical `client_msg_id` creates no duplicates
- [ ] `renew` twice in the same second returns `1` both times
- [ ] expired lease returns to `open`; third expiry lands on `blocked`
- [ ] every stored timestamp is UTC (`gmdate`), never `NOW()`
- [ ] `board_status` pages by signed keyset cursor and refuses a cursor replayed against another run or sort
- [ ] a page never exceeds the row / byte / cell / deadline limits in §168-S.4.4
- [ ] `mcp_ai_run_tasks` is unreachable through the scoped database toolkit (`db_site_database_refused` or `db_scope_table_not_allowed`)
- [ ] uninstall drops all three tables
