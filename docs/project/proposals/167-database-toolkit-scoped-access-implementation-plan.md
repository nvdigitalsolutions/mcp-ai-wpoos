# 167 — Implementation plan: database toolkit (scoped access)

**Companion to:** [`167-database-toolkit-scoped-access-proposal.md`](./167-database-toolkit-scoped-access-proposal.md) (design, standards, threat model, decision log) · [`167-database-toolkit-scoped-access-research.md`](./167-database-toolkit-scoped-access-research.md) (sources, findings, open questions)
**Date:** 2026-11-27
**Status:** Ready to build. Fourteen decisions locked (proposal §167.8). Nothing implemented.
**Size:** ~31–42 engineer-days across 8 phases, plus review.

---

## 0. Ground rules

1. **The database is the last line.** No task here may make a write possible through a role that lacks the grant. Migration-time admission (L2) is a courtesy that turns a hostile request into a clean refusal; it is never the control.
2. **One kernel, two runtimes.** Grammar, admission, shaping, masking and refusal codes live in `lib/core/src/Domain/Service/Database/`. Neither host forks them.
3. **Fail closed.** No connection, no probe, no scope, no mappable filter → refuse with a typed code. Never widen.
4. **Structured by default.** Nothing before P5 may accept a SQL string.
5. **Stream, never `fetchAll()`.** Every result is consumed row-by-row against a deadline and a byte cap.
6. **Tests degrade, guarantees do not.** Postgres-dependent tests call `maybe_skip_postgres()`; the gate they protect is documented as "green where the service exists".
7. **Conventions to load before starting:** `mcp-ai-wpoos-toolkit-creation` (§4 eleven surfaces, §6 phpcs gotchas), `mcp-ai-wpoos-test-suite` (Docker matrix), `wp-plugin-architecture` (one class per file), `wp-security-audit`, `wp-rest-api` (if any route is added).
8. **Folder READMEs are a deliverable** — `composer run docs:check-folder-readmes`.

### 0.1 Amendments to the proposal forced by this research

Three design details changed while writing the code-level contracts. They are corrections, not new decisions, and the proposal should be amended in the same PR that lands P0:

| # | Proposal said | Research/implementation says | Why |
|---|---|---|---|
| **A1** | Probe `can_write` with a `CREATE TEMP TABLE` inside a rolled-back transaction | Probe by **privilege introspection**: `pg_roles.rolsuper`/`rolbypassrls`, `has_schema_privilege(…, 'CREATE')`, `has_table_privilege(…, 'INSERT'/'UPDATE'/'DELETE')` | A temp table proves *TEMP* privilege, not the absence of DML on the scope's relations. Introspection is non-destructive **and** answers the actual question |
| **A2** | Native provider encrypts "by the same scheme" (implied new code) | Reuse **`WP_MCP_AI_Encryption`** (`includes/class-wp-mcp-ai-encryption.php`) — the class Remote Sites already prefers, with master-key support | No second cipher, no second rotation story, and cross-provider import keeps working |
| **A3** | Prepared statements are uniformly good | Prepared statements are required for stacking safety **and** carry an RLS plan-caching caveat: a server-side statement can keep the plan of the tenant it was first planned for | Verified in 2026 RLS measurement work. Mitigation is §4.3.7 — short-lived connections (already D8) plus no server-side statement reuse across tenants |

---

### 0.2 Implementation status (2026-11-28)

**P0, P1 read surface, P3 console (observation + scope editor + preview), P5 raw SQL, P6 write path, P7 export (including the async hand-off) and the P4 Laravel port are implemented. Eleven of the thirteen tools now run on both hosts; the two write tools remain WordPress-only, because a headless API has no approver. Every planned phase is built.**

| Area | State | Evidence |
|---|---|---|
| Kernel: 12 services + 19 value objects + `DatabaseQueryInterface` + 10 collaborator contracts in `addons/pro/lib/database/src/` | ✅ implemented (D15 relocation; P4 extraction) | 151 tests / 211 assertions; purity gate 44 files, 0 violations |
| **`PdoExecutor` in the kernel** (631 lines moved from `WP_MCP_AI_Db_Executor`) | ✅ extracted in P4 | WordPress keeps a 20-line subclass; Postgres gates unchanged |
| **`PdoProbe` in the kernel** (moved from `WP_MCP_AI_Db_Probe`) | ✅ extracted in P4 | WordPress keeps a `run()` alias; live probe test asserts `nvoos_reader` is reachable, cannot write, does not bypass RLS |
| `includes/connections/` — provider interface, resolver, native provider, Remote Sites provider | ✅ implemented | `ConnectionsTest` (resolver namespacing, scope-derived visibility) |
| `includes/database/` — runtime guard, registries, probe binding, catalogue, factory, 4 engine adapters, redactor, audit log, writer, write compiler, proposal store, caches, credential store, site-DB guard | ✅ implemented | portable suite: 59 tests / 151 assertions, 1 skipped |
| Availability guard (D14/D15) | ✅ implemented | false only when the kernel is absent or PHP < 8.2; never reads `enable_oos_engine` |
| 13 Pro tools + `init.php` + settings page + presets + manifest | ✅ implemented | 2 write tools gated by `enable_database_writes` |
| 11 registration surfaces | ✅ implemented | Pro manifest regenerated (+15 entries); folder READMEs green (`--scope=all`) |
| P5 raw SQL + `explain(analyze)` | ✅ implemented | raw path behind the `raw_sql` tier |
| P7 export | ✅ synchronous CSV/JSON with checksum on both hosts, **plus** `async: true` → queue → the same tool read back by `job_id` | `db_export`; the worker re-enters the tool so a narrowed grant refuses at run time; the Laravel store is private-visibility where supported, proven on live masked rows |
| **Audit trail** | ✅ WordPress bounded ring (500) + Laravel append-only ledger (`oos_database_audit`, package migration 000011) behind `AuditReaderInterface` | live write→tail, a recorded refusal, and a literal-free ledger |
| **P4 Laravel port** — `PdoDatabaseConnection`, `EloquentDatabaseConnection`, `DatabaseConfig`, `PdoConnectionFactory`, `InformationSchemaCatalogue`, `LoggerAuditSink`, `TableAuditLog`, `ScopeRedactor`, 4 engine profiles, probe binding | ✅ implemented | 12 live Postgres tests (tenant filtering, masking, write refusal, literal-free audit, probe, export tier gate, real masked export, ledger tail) + 92 structural parity assertions |
| **11 shared tools in `lib/core/src/Tool/Database/`** + 11 readiness rows (`ready`/`port`/`call`) | ✅ implemented | 25 unit tests / 100 assertions; readiness audit 530/530, no violations |
| `oos.database` config + provider binding (`scoped`, fail-closed, kernel-probing guard) | ✅ implemented | config parses and drops a malformed scope rather than widening it; the binding no longer depends on the toggle (§5.4 item 4) |
| `nvoos/database-kernel` declared by `nvoos/laravel-adapter` | ✅ implemented | without it the adapter would have installed no kernel and the `ready` rows would have degraded to "class not found" |
| **Adapter suite** — Testbench (`orchestra/testbench` v11) with in-memory SQLite | ✅ implemented | **695 tests / 3718 assertions** (2 skipped): migration-vs-`COLUMNS` drift gate, ledger round trip + filters + summary + literal-free schema, provider wiring (port, probe, reader), disabled-toolkit refusal, `FileStore` visibility |
| **`ErrorFactory` parity** — status and domain code | ✅ implemented | `create()` honours `$data['status']` (400–599 only) and returns a `DomainErrorException`, so `normalize()` reports `wp_mcp_ai_db_timeout` instead of `500` — the code the tool base had always documented |
| **P3 console** — `mcp-ai-pro/v1/database` + the toolkit `console` screen (four tabs) | ✅ implemented | `overview`, `audit`, `health`, `connections/{id}/test`; credential never serialized; corrupted scopes stay visible with their refusal code; the one socket-opening call is the explicit Test |
| **P3 scope editor** — `POST /scopes`, `DELETE /scopes/{id}` + `ScopeEditor` | ✅ implemented | writes through `WP_MCP_AI_Db_Scope_Registry::save()`, so the registry owns validation and the version bump; a wildcard schema is a 422 `db_scope_not_allowed` mapped onto the schemas field; an unknown connection is a 422 `unknown_connection`; an unchanged save is a successful no-op and does not move the version |
| **P3 live preview** — `POST /preview` + `PdoExecutor::preview()` + `PreviewDescriptor` | ✅ implemented | runs the real compile order (resolve → tier → servability → tenant → catalogue → relation → grammar → admission) and stops before `prepare()`; returns the literal-free statement template, the binding *count*, the projected / masked / excluded / searchable columns and the effective caps; no audit row, because a preview is not a call the assistant made |

**Not built:**

- **Two write tools on Laravel.** `db_propose_write`/`db_apply_write` need a proposal store plus a human approver, which a headless API does not have. A stand-in that returned success without doing the work is exactly what the readiness manifest exists to prevent. Gate 9 covers the eleven shared slugs.

**Lint posture.** The whole database-toolkit surface is phpcs-clean — exit 0 at the strictest setting (`--error-severity=1 --warning-severity=1`) across `includes/database/`, `includes/connections/`, `addons/pro/includes/tools/database/`, the settings page, the console REST controller and its test. Three sniffs are narrowly exempted in the ruleset, each with a stated reason and a file scope — `EscapeOutput.ExceptionNotEscaped` (every refusal is `DatabaseRefusal::of( RefusalCode::X, '…' )`, and a message is never echoed), `DB.RestrictedClasses` for `includes/database/` only (the toolkit reaches an *external* database by PDO; `$wpdb` cannot), and `UsedPropertyNotSnakeCase` (the kernel's PSR camelCase value-object properties, whose tree is already excluded as `*/lib/*`). `PreparedSQL`, `DirectDatabaseQuery`, `NonceVerification` and the input sniffs stay on.

Two suppressions are inline and deliberate: the console's `{ success: false, error }` REST envelope (the `WPMCPAI.Tools.*` sniff targets *tool* returns, not a REST contract) and `fopen( 'php://temp' )` in the CSV exporter (`WP_Filesystem` cannot address an in-memory stream). The settings page carries one file-level `NonceVerification` directive because every handler verifies through `self::authorise()` → `check_admin_referer()` before the first `$_POST` read, which WPCS cannot follow across a call. Two real defects were fixed while clearing it: `$_GET['db_notice_type']` was sanitised without a shape guard (a crafted `?db_notice_type[]=x` would have raised a `TypeError`), and `text_field()` took a `bool $readonly` parameter, which collides with the PHP 8.1 keyword and breaks named arguments.

**Gates.** 6, 7 and both halves of 9 are green: 39 tests / 160 assertions against `postgres:18-alpine` (including the Laravel binding, the probe and the ledger), plus 92 structural parity assertions without a database (contract identity for 11 tools, session-SQL identity for 4 engines across 36 cases). The job body was reproduced on Linux (`php:8.3-cli` + `pdo_pgsql`) before `continue-on-error` came off, so the gate that proves gates 6 and 7 is itself blocking. Gate 13 (purity) is green at 45 files; the portable gate and the folder-README gate are green.

**Verification note (pre-existing harness defect).** The WordPress async-export test was run as a single file — `vendor/bin/phpunit tests/test-database-export-async.php` — because the repository's root `tests/` scan does not collect root-level `test-*.php` classes in this Docker harness: 1,205 such files exist and none are discovered, while subdirectories (`tests/rest-api/`, `tests/verifiers/`) are collected normally, and the same content under a different filename *is* collected. That is a test-harness issue, not a P7 regression, and it is recorded here so it is not misread later; the file passes 4/4 / 15 assertions when run directly.

The adapter suite is now part of the same CI contract: `lib/laravel-adapter` runs `php vendor/bin/phpunit --no-coverage` on PHP 8.3 with `pdo_sqlite` and **no services**, so the surface that the Postgres fixture cannot reach — the package's own migration, the ledger, the provider wiring and the file store — is gated on every pull request. It was reproduced locally at **695 / 3718** before the job was added.

---

### 0.3 Distribution finding — resolved by D15 (accepted 2026-11-27)

Verified in `bin/build-plugin-zip.sh`: there are **three** artefacts, not two.

| Artefact | `lib/`? | Pro? | Toolkit today |
|---|---|---|---|
| `mcp-ai-wpoos-base` (WordPress.org) | **no** (`--exclude '/lib'`, `--exclude 'addons'`) | no | absent — correct |
| `mcp-ai-wpoos-pro` (add-on zip, built from `addons/pro/`) | **no** (there is no `addons/pro/lib`) | yes | — |
| `mcp-ai-wpoos` (complete/combined) | **yes** (explicit: "the engine requires lib/core/src + lib/wordpress-adapter/src") | yes | works |

So a **base + Pro add-on install** — the configuration the changelog calls "base+pro installs" (PR #6561) — has Pro but **no `lib/`**, because Pro unzips into `addons/pro/` inside a base package that stripped `lib/` entirely. Under D14 that install reports `unavailable`, which is almost certainly the wrong outcome for a Pro product.

**Proposed D15 (not yet accepted):** ship the portable kernel **inside the Pro add-on** rather than `lib/core`.

- Move `lib/core/src/Domain/Service/Database/` + `ValueObject/Database/` + the port to `addons/pro/lib/database/src/` under a distinct namespace (`Nvoos\Database\`), so it never collides with `Nvoos\Core\`.
- Pro registers a four-line PSR-4 autoloader for that prefix; Laravel loads the same files through a Composer path repository (the `addons/nvoos-wifi` precedent).
- Consequence: **all 13 tools become Pro.** D1's "7 Base tools" premise no longer holds — a database toolkit is a Pro capability, and the base build (which has no Pro) correctly has none.
- Availability becomes "Pro active", not "lib/ present", so the complete build and base+pro both work.
- The purity-gate layers move with the code (`addons/pro/lib/database/src`), and the kernel's PHP floor becomes a property of the Pro add-on.

**D15 is accepted.** The kernel has been relocated to `addons/pro/lib/database/` (namespace `Nvoos\Database\`), with `autoload.php`, `composer.json`, a standalone `phpunit.xml.dist` and a host-free `tests/bootstrap.php`. The suite is green at the new path (151 tests / 211 assertions) and the purity gate scans 32 files with 0 violations. **D1 is amended** (all 13 tools are Pro) and **D14 is superseded** (availability is "the Pro add-on is active", not "`lib/` is present").

---

## 1. Research applied

Researched 2026-11-27. Each row is a finding that changed a line of code or a test, not background reading.

| # | Finding | Source | Consequence in this plan |
|---|---|---|---|
| **R1** | A single `COMMIT` in model input ended the read-only transaction in the official Postgres MCP server; the remainder ran at full privilege | Datadog Security Labs, April 2026 | `COMMIT`/`ROLLBACK`/`END` are refused at L2 **and** the role has no write grant; gate 6 removes L2 to prove it |
| **R2** | Read-only bypasses in 14 SQL MCP servers across three patterns: denylist gaps (`SET`), multi-statement / `WITH` chaining, unprepared concatenation; RCE on Postgres in one chain | Hildebrand, July 2026 | §7.3 corpus is enumerated per pattern; identifiers resolved from the catalogue; values always bound |
| **R3** | **PostgreSQL itself rejects multiple commands in a prepared statement** (`cannot insert multiple commands into a prepared statement`, SQLSTATE 42601). This is why "SELECT-only injection" research treats native prepares as the stacked-query barrier | Phrack #71 "World of SELECT-only PostgreSQL Injections"; PostgreSQL docs | The executor **must** use `PDO::prepare()` + `execute()` and **must never** use `PDO::exec()`/`PDO::query()` for a scoped statement. `ATTR_EMULATE_PREPARES` must be explicitly `false` — emulation routes through `PQexec`, which accepts stacks |
| **R4** | `pdo_mysql` **emulates prepares by default**, and `PDO::MYSQL_ATTR_MULTI_STATEMENTS` must be set in the constructor (PHP ≥ 5.5.21/5.6.5); `setAttribute()` afterwards does nothing | PHP/PDO docs, MariaDB KB on stacked queries | Connection factory passes both options in the 4th constructor argument; a unit test asserts `getAttribute(PDO::ATTR_EMULATE_PREPARES) === false` |
| **R5** | Tenant context must be set with `SET LOCAL` / `set_config(…, true)` **inside** an explicit transaction; a plain `SET` leaks across PgBouncer transaction pooling | 2026 RLS measurement write-ups; PgBouncer docs | Executor always opens a transaction first; no code path sets a session-level GUC for tenant |
| **R6** | `FORCE ROW LEVEL SECURITY` plus a non-owner, non-`BYPASSRLS` role — otherwise owners bypass policies; `NULLIF(current_setting(…, true), '')` makes a reset connection return zero rows instead of erroring | same | Bootstrap generator emits `FORCE`; probe asserts `rolbypassrls = false`; policy template uses `NULLIF` |
| **R7** | Under RLS, **non-`LEAKPROOF` functions cannot be pushed below the policy**, so `lower()`, `LIKE` and similar prevent index use; composite `(tenant_id, …)` indexes are what make policies cheap; a cached plan can carry one tenant's statistics | 2026 RLS performance measurements | Bootstrap guidance asks for `(tenant_id, …)` first; `db_search` escapes wildcards rather than wrapping columns in functions where avoidable; §4.3.7 documents the plan trap |
| **R8** | MySQL's `max_execution_time` is **milliseconds and SELECT-only**; MariaDB's `max_statement_time` is **seconds and applies to any statement** | MySQL Server Blog; MariaDB KB | §4.4 translation table. MySQL gets a PHP-side deadline as well, because `max_execution_time` cannot stop a slow non-SELECT |
| **R9** | SQLSTATE is the stable contract, not message text; `42501` privilege, `57014` statement timeout, `42P01` missing relation, `25006` read-only transaction | PostgreSQL Appendix A | §4.5 maps SQLSTATE → `RefusalCode`; messages are never surfaced |
| **R10** | pgAudit gives a DB-level trail that captures direct `psql`, ETL and DBA sessions; baseline should be low-volume/high-value, scoped to the application role | pgAudit README; 2026 compliance write-ups | Bootstrap emits commented `pgaudit.log = 'ddl, role'` plus a per-role `read` option, with the `log_parameter` privacy trade-off stated |
| **R11** | An MCP server is an OAuth **Resource** Server; tokens must be issued for it (RFC 8707 audience), and per-tool scopes are the mechanism a host uses to auto-allow reads and prompt for writes | MCP authorization revisions 2025-11-25 / 2026-07-28 | Tools declare per-tool scopes; `db_select` is auto-allowable, `db_apply_write` is not |
| **R12** | A base+pro install (WordPress.org base package + this add-on) has **no `lib/` at all** — the build script passes `--exclude '/lib'`, and the Pro zip carries no `lib/` either | `bin/build-plugin-zip.sh`, verified 2026-11-27 | **D15**: the kernel ships inside the add-on (`addons/pro/lib/database/`), so the complete build and base+pro both work |

---

## 2. Locked decisions → build consequences

| # | Locked | Consequence |
|---|---|---|
| **D1** *(amended by D15)* | All Pro | Kernel in `addons/pro/lib/database/`; all 13 tools in `addons/pro/includes/tools/database/`; one `enable_database_toolkit` flag. The base package has none — it also has no Pro, so no connection can be authored |
| **D2** | Postgres first, MySQL/MariaDB P2, SQLite tests | Adapter interface in P0; `PgsqlAdapter` P1; `MysqlAdapter` + `MariadbAdapter` P2; `SqliteAdapter` test-only |
| **D3** | No raw SQL in v1 | `db_query` is P5; the classifier still ships in P0 |
| **D4** | Reuse `TenantResolverInterface` | `scope_tenant` resolves through the port; no `tenant_id` tool parameter exists anywhere |
| **D5** | Record's own store, encrypted | Reuse `WP_MCP_AI_Encryption` (A2) for native records; Remote Sites records use its existing encryptor |
| **D6** | Write path in scope, P6, default-off | `max_affected_rows` in the scope schema from P0; proposal store + approval in P6 |
| **D7** | Scope-filtered catalogue | System schemas never emitted; test asserts absence |
| **D8** | Accept `set_config` cost | Executor is always transactional; `ATTR_PERSISTENT` false |
| **D9** | No `ProductCatalog` migration | Commerce contract tests untouched |
| **D10** | Graphify convergence opportunistic | Ticket only |
| **D11** | Provider contract + `database` type | `includes/connections/` P0; Remote Sites adapter P1; type + field block P3 |
| **D12** | Reuse `_wp_mcp_ai_pro_remote_connections` | `principals` seeded from meta |
| **D13** | Gateway out of scope | No code; scope ids stable |
| **D14** *(superseded)* | `unavailable` without the kernel, or on PHP < 8.2 | Availability guard + purity gate (gates 13, 14) |
| **D15** | Kernel ships with Pro | `addons/pro/lib/database/`, namespace `Nvoos\Database\`, PSR-4 autoloader, standalone suite; Laravel via Composer path repository |

---

## 3. Component contracts

### 3.1 The port

`lib/core/src/Domain/Contract/DatabaseQueryInterface.php`

```php
interface DatabaseQueryInterface
{
    /** Connections visible to a principal, redacted. */
    public function connections( PrincipalRef $principal ): array;

    /** Scope-filtered catalogue for one connection. */
    public function describe( string $connection, ?string $relation = null ): CatalogueDescriptor;

    public function select( string $connection, string $scope, SelectQuery $q ): ResultSet;
    public function aggregate( string $connection, string $scope, AggregateQuery $q ): ResultSet;
    public function search( string $connection, string $scope, SearchQuery $q ): ResultSet;

    /** Plan only. `$analyze` is refused unless the scope holds `explain_analyze`. */
    public function explain( string $connection, string $scope, SelectQuery $q, bool $analyze = false ): PlanDescriptor;

    /** P5 only; refused unless the scope holds `raw_sql`. */
    public function query( string $connection, string $scope, RawQuery $q ): ResultSet;
}
```

Value objects (`SelectQuery`, `AggregateQuery`, `SearchQuery`, `RawQuery`, `ResultSet`, `PlanDescriptor`, `CatalogueDescriptor`, `PrincipalRef`) live in `lib/core/src/Domain/ValueObject/Database/` and are `final readonly` classes — PHP 8.2, matching `lib/core/composer.json`.

### 3.2 Refusal codes

`RefusalCode` is a **backed enum** (`string`), not a class of constants, so `match` over it is exhaustive and a new code cannot be silently unhandled:

```php
enum RefusalCode: string {
    case NOT_CONFIGURED        = 'db_not_configured';
    case UNKNOWN_CONNECTION    = 'db_unknown_connection';
    case CONNECTION_UNAVAILABLE= 'db_connection_unavailable';
    case NOT_A_DATABASE        = 'db_connection_not_a_database';
    case SCOPE_NOT_ALLOWED     = 'db_scope_not_allowed';
    case TABLE_NOT_ALLOWED     = 'db_scope_table_not_allowed';
    case COLUMN_NOT_ALLOWED    = 'db_scope_column_not_allowed';
    case FILTER_UNMAPPABLE     = 'db_scope_filter_unmappable';
    case TIER_REQUIRED         = 'db_tier_required';
    case STATEMENT_NOT_ADMITTED= 'db_statement_not_admitted';
    case UNSUPPORTED_METRIC    = 'db_unsupported_metric';
    case LIMIT_EXCEEDED        = 'db_limit_exceeded';
    case TIMEOUT               = 'db_timeout';
    case RESULT_TOO_LARGE      = 'db_result_too_large';
    case APPROVAL_REQUIRED     = 'db_approval_required';
    case WRITE_AFFECTED_MISMATCH = 'db_write_affected_mismatch';
    case SITE_DATABASE_REFUSED = 'db_site_database_refused';
    case EXTENSION_MISSING     = 'db_extension_missing';
    case PRIVILEGE_DENIED      = 'db_privilege_denied';
    case RELATION_NOT_FOUND    = 'db_relation_not_found';
    case COLUMN_NOT_FOUND      = 'db_column_not_found';
    case AUTH_FAILED           = 'db_auth_failed';
    case UNKNOWN_DATABASE      = 'db_unknown_database';
    case CONNECTION_LIMIT      = 'db_connection_limit';
    case LOCK_TIMEOUT          = 'db_lock_timeout';
    case READ_ONLY_VIOLATION   = 'db_read_only_violation'; // should be unreachable; a bug if seen
}
```

`PRIVILEGE_DENIED`, `RELATION_NOT_FOUND`, `COLUMN_NOT_FOUND`, `AUTH_FAILED`, `UNKNOWN_DATABASE`, `CONNECTION_LIMIT`, `LOCK_TIMEOUT`, `READ_ONLY_VIOLATION` are **new** relative to the proposal's appendix: they are the database's own answers, and mapping them keeps error text out of the response.

### 3.3 Executor state machine

```
resolve descriptor ──▶ check availability ──▶ check scope ──▶ build statement
       │                     │                    │                 │
       └─ UNKNOWN_CONNECTION └─ UNAVAILABLE       └─ SCOPE_*        ▼
                                                             admit (L2)
                                                                    │
                                             STATEMENT_NOT_ADMITTED ┘
                                                                    ▼
                                                        open connection (PDO)
                                                                    │
                                              BEGIN [READ ONLY] ────┤
                                              SET LOCAL … ──────────┤  (tenant, search_path,
                                              SET LOCAL application_name  timeouts, read-only)
                                                                    ▼
                                                       prepare + execute (bound)
                                                                    │
                                          stream rows: deadline + byte cap + cell cap
                                                                    │
                                                    ROLLBACK ───────┤
                                                    closeCursor     │
                                                                    ▼
                                                     shape ──▶ mask ──▶ audit ──▶ ResultSet
```

Failure at any step after the transaction opens still executes `ROLLBACK` in a `finally`; the connection is closed in the same block. There is no code path that leaves a transaction open.

---

## 4. Engine contracts

### 4.1 DSN construction

Credentials go in the constructor arguments, **never** in the DSN string, so they cannot appear in a driver error message. The DSN itself is built only from descriptor fields — never from caller input.

| Engine | DSN |
|---|---|
| `pgsql` | `pgsql:host={host};port={port};dbname={db};sslmode={sslmode};connect_timeout=5;application_name=nvoos-agent` |
| `mysql` / `mariadb` | `mysql:host={host};port={port};dbname={db};charset=utf8mb4` |
| `sqlite` | `sqlite::memory:` (tests) or `sqlite:{absolute path}`; path must sit under a declared allowlist root |

For `sslmode=verify-full`, the descriptor must also carry `sslrootcert`; a descriptor that asks for `verify-full` without a CA path is refused at registration (`CONNECTION_UNAVAILABLE`), not at connect time — failing early keeps the error attributable to configuration.

`application_name` is overwritten per call via `SET LOCAL` to `nvoos:{scope}:{audit_id}` (≤ 63 bytes, truncated), so `pg_stat_activity` attributes a running query to a specific audited call.

### 4.2 Connection options

| Attribute | pgsql | mysql/mariadb | sqlite | Why |
|---|---|---|---|---|
| `ATTR_ERRMODE` | `ERRMODE_EXCEPTION` | same | same | Catchable failures |
| `ATTR_EMULATE_PREPARES` | **`false`** (explicit) | **`false`** (default is `true`) | n/a | Emulation routes through a stacking-capable exec path (R3, R4) |
| `ATTR_DEFAULT_FETCH_MODE` | `FETCH_ASSOC` | same | same | Predictable shaping |
| `ATTR_STRINGIFY_FETCHES` | `false` | `false` | `false` | Numeric fidelity is handled by the shaper, not the driver |
| `ATTR_PERSISTENT` | **`false`** (D8) | `false` | `false` | Prevents GUC leakage and the RLS plan trap (R5, R7) |
| `MYSQL_ATTR_MULTI_STATEMENTS` | — | **`false`** (constructor only) | — | R4 |
| `ATTR_TIMEOUT` | — (use `connect_timeout`) | connect timeout | busy timeout | Driver semantics differ; do not pretend it is a statement timeout |

A unit test asserts, for each adapter, that `getAttribute(PDO::ATTR_EMULATE_PREPARES) === false` and — for MySQL — that a stacked input is refused by the driver.

### 4.3 Session SQL

All of this runs inside the transaction opened by the executor. `SET LOCAL` is used for every value so nothing survives the `ROLLBACK`.

#### 4.3.1 `pgsql`

```sql
BEGIN READ ONLY;
SET LOCAL statement_timeout = 5000;                    -- integer milliseconds
SET LOCAL idle_in_transaction_session_timeout = 10000;
SET LOCAL lock_timeout = 2000;
SET LOCAL search_path = "reporting", pg_catalog;       -- scope-pinned, pg_catalog last-ish
SET LOCAL application_name = 'nvoos:analytics-readonly:aud_01J…';
SELECT set_config('app.tenant_id', $1, true);          -- only when the scope declares a tenant
-- … the scoped statement …
ROLLBACK;
```

Notes that matter:

- **`BEGIN READ ONLY` before any other statement.** `SET TRANSACTION READ ONLY` must come before the first query; combining it into `BEGIN` removes the ordering hazard entirely.
- **`pg_catalog` is in the path explicitly** so an unqualified built-in resolves to the system function even if a scope schema later grows a same-named object. `pg_temp` is deliberately absent from the front of the path (§167.4.6).
- **`statement_timeout` accepts `5000` or `'5s'`**; integers are milliseconds, which is what `DatabaseLimits` stores, so no unit conversion is needed.
- `idle_in_transaction_session_timeout` exists since 9.6; the adapter feature-detects the server version at probe time and omits it below that.

#### 4.3.2 `mysql`

```sql
START TRANSACTION READ ONLY;
SET SESSION max_execution_time = 5000;                 -- MILLISECONDS, SELECT only (R8)
SET SESSION innodb_lock_wait_timeout = 2;              -- seconds
SET SESSION application_name = 'nvoos:…';              -- no-op below MySQL 8.0.14? guard on version
-- … statement …
ROLLBACK;
```

`max_execution_time` applies to `SELECT` only, so the executor also enforces a **PHP-side deadline** (§4.7). There is no `idle_in_transaction_session_timeout` equivalent; the rollback-in-`finally` is the control.

#### 4.3.3 `mariadb`

```sql
START TRANSACTION READ ONLY;
SET SESSION max_statement_time = 5.000;                -- SECONDS, fractional (R8)
SET SESSION innodb_lock_wait_timeout = 2;
-- … statement …
ROLLBACK;
```

Unit conversion is the only difference from MySQL, and getting it wrong is a 1000× timeout bug — hence the explicit table in §4.4 and a unit test per engine.

#### 4.3.4 `sqlite`

```sql
BEGIN DEFERRED;
-- no server-side read-only variant, no statement timeout, no RLS
```

SQLite is a test engine. The adapter refuses a tenant-scoped scope outright (`FILTER_UNMAPPABLE`) rather than implying RLS it cannot provide.

#### 4.3.5 `search_path` and unqualified identifiers

The grammar always emits **schema-qualified** identifiers (`"reporting"."orders"`), so `search_path` is a safety net rather than a resolution mechanism. That means a scope cannot be redirected by a maliciously named relation in another schema.

#### 4.3.6 Statement admission is engine-specific

`AdmissionClassifier` takes an engine, because the dangerous surface differs:

| Check | pgsql | mysql/mariadb |
|---|---|---|
| Single statement | Enforced by the server for prepared statements (R3) **and** by L2 | Enforced only by `MULTI_STATEMENTS=false` (R4) **and** by L2 |
| `SELECT`/`WITH … SELECT` only | yes | yes |
| DDL / DML keywords | refused | refused |
| `SET ROLE` / `SET SESSION AUTHORIZATION` | refused | refused (`SET ROLE` exists) |
| `COPY … FROM PROGRAM`, `lo_import`, `pg_read_file`, `dblink` | refused | n/a |
| `LOAD_FILE`, `INTO OUTFILE`, `LOAD DATA` | n/a | refused |
| Semicolon inside a literal | permitted (normaliser strips literals before matching) | same |

#### 4.3.7 The RLS plan-cache caveat (R7)

A server-side prepared statement can keep the plan — and therefore one tenant's statistics — for the tenant that first planned it. Two mitigations, both already implied by other decisions, plus one note:

1. **No persistent connections** (D8), so statements do not outlive the request.
2. **A fresh `prepare()` per call**; the executor never caches `PDOStatement` objects.
3. **Documented, not enforced:** for very large RLS tables, an operator may set `ALTER ROLE … SET plan_cache_mode = force_generic_plan;` if they observe tenant-skewed plans. This is guidance in the bootstrap template, not a default — forcing generic plans costs performance for the common case.

Correctness is unaffected: the policy predicate is applied at execution regardless of which plan is chosen.

### 4.4 Timeout translation

`DatabaseLimits::timeout_ms` is the single source. Each adapter converts:

| Engine | Server-side statement | Unit | Scope | PHP-side deadline |
|---|---|---|---|---|
| pgsql | `statement_timeout` | milliseconds | any statement | yes (belt) |
| mysql | `max_execution_time` | **milliseconds** | **SELECT only** | **required** |
| mariadb | `max_statement_time` | **seconds, fractional** | any statement | yes |
| sqlite | — | — | — | **required** |

Plus, where supported: `idle_in_transaction_session_timeout` (pgsql), `lock_timeout` (pgsql) / `innodb_lock_wait_timeout` (mysql/mariadb, seconds).

### 4.5 Error mapping

PDO exposes the SQLSTATE as `$e->getCode()` and the driver code as `$e->errorInfo[1]`. The mapper reads `errorInfo` and never the message.

#### 4.5.1 `pgsql`

| SQLSTATE | RefusalCode |
|---|---|
| `25006` | `READ_ONLY_VIOLATION` (a bug if reached — L2 and the role both failed) |
| `42501` | `PRIVILEGE_DENIED` |
| `57014` | `TIMEOUT` |
| `55P03` | `LOCK_TIMEOUT` |
| `42P01` | `RELATION_NOT_FOUND` |
| `42703` | `COLUMN_NOT_FOUND` |
| `28P01` | `AUTH_FAILED` |
| `3D000` | `UNKNOWN_DATABASE` |
| `53300` | `CONNECTION_LIMIT` |
| `08000`,`08003`,`08006`,`08001`,`08004` | `CONNECTION_UNAVAILABLE` |
| `42601` (multi-command in prepared statement) | `STATEMENT_NOT_ADMITTED` |
| `40001` | `TIMEOUT` (retryable; surfaced, never retried automatically) |
| anything else | `CONNECTION_UNAVAILABLE` with the SQLSTATE logged server-side |

#### 4.5.2 `mysql` / `mariadb`

| Driver code (`errorInfo[1]`) | RefusalCode |
|---|---|
| `1142`, `1143`, `1044` | `PRIVILEGE_DENIED` |
| `1045` | `AUTH_FAILED` |
| `1049` | `UNKNOWN_DATABASE` |
| `1146` | `RELATION_NOT_FOUND` |
| `1054` | `COLUMN_NOT_FOUND` |
| `3024` (MySQL query timeout) | `TIMEOUT` |
| `1205` | `LOCK_TIMEOUT` |
| `1040` | `CONNECTION_LIMIT` |
| `2002`, `2006`, `2013` | `CONNECTION_UNAVAILABLE` |
| anything else | `CONNECTION_UNAVAILABLE` with the code logged server-side |

The mapping tables are data (`RefusalCode::fromPgsql()` / `::fromMysql()`), so a test can assert every row, and adding a code does not require touching the executor.

### 4.6 Identifier rules

| Rule | Reason |
|---|---|
| Every identifier is **resolved against the introspected catalogue** and the catalogue's stored name is emitted — the caller's string is never interpolated | T5. A name that is not in the catalogue cannot be emitted, so `users; DROP TABLE x` never reaches the grammar as syntax |
| pgsql: wrap in `"`, double embedded `"`, reject > 63 **bytes** | `NAMEDATALEN-1`; silently truncated identifiers are a correctness bug |
| mysql/mariadb: wrap in `` ` ``, double embedded `` ` ``, reject > 64 **characters** | identifier limit |
| No identifier may be empty, `.`-containing after splitting, or start with `pg_` for a writable target | schema and system-name hygiene |

### 4.7 Fetch loop

```php
$deadline = microtime(true) + ($limits->timeoutMs / 1000);
$bytes    = 0;
$rows     = [];

$stmt->execute($bindings);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (microtime(true) > $deadline) { $truncated = 'deadline'; break; }
    $bytes += $shaper->rowBytes($row);
    if ($bytes > $limits->maxBytes) { $truncated = 'max_bytes'; break; }
    $rows[] = $shaper->row($row);
    if (count($rows) >= $limits->maxRows) { $truncated = 'max_rows'; break; }
}
$stmt->closeCursor();
```

- Never `fetchAll()`: it materialises the whole result before any cap can apply.
- The row cap, byte cap and deadline are checked **before** appending, so a single hostile row cannot exceed the cap.
- `closeCursor()` matters: without it a MySQL connection can hold the result set open and the next statement fails with "commands out of sync".

### 4.8 Keyset cursor

```json
{ "v":1, "o":["placed_at","order_id"], "d":"desc", "k":[1730419200, 88123],
  "h":"hmac-sha256:…", "s":"analytics-readonly", "t":"reporting.orders" }
```

- Base64url of the JSON, then an HMAC over the payload with a per-site key (`WP_MCP_AI_Encryption` master key on WordPress, `APP_KEY` on Laravel).
- `h` binds the cursor to the scope + relation + ordering, so a cursor cannot be edited to widen a query.
- Keyset only: `OFFSET` is refused above a small threshold (default 1 000) because it is a linear scan, and refused entirely when the ordering is not backed by an index the catalogue declares.

---

## 5. File map

### 5.1 New — host-free kernel (`addons/pro/lib/database`)

```
addons/pro/lib/database/
├── autoload.php                                # PSR-4 for Nvoos\Database\
├── composer.json                               # path repo for the Laravel host
├── README.md
├── phpunit.xml.dist
├── src/Domain/Contract/DatabaseQueryInterface.php
├─ src/Domain/ValueObject/Database/            # descriptors + query objects (20)
├── src/Domain/Service/Database/                # kernel services (12)
└── tests/                                      # host-free suite (151 tests)
lib/core/src/Tool/Database/                     # Laravel-side tools (parity slugs)
```

### 5.2 New — Base adapters (`includes`)

```
includes/connections/
├── README.md
├── class-wp-mcp-ai-connection-provider-interface.php
├── class-wp-mcp-ai-connection-resolver.php
├── class-wp-mcp-ai-native-connection-provider.php
└── class-wp-mcp-ai-remote-site-connection-provider.php   # no-ops when Pro absent
includes/database/
├── README.md
├── class-wp-mcp-ai-db-runtime.php              # is_available(), kernel bootstrap (D14)
├── class-wp-mcp-ai-db-connection-registry.php  # CRUD, site-DB refusal, probe state
├── class-wp-mcp-ai-db-scope-registry.php       # CRUD, version bump
├── class-wp-mcp-ai-db-probe.php                # privilege introspection (A1)
├── class-wp-mcp-ai-db-catalogue.php            # introspection, cached, scope-filtered
├── class-wp-mcp-ai-db-connection-factory.php   # DSN + options (§4.1, §4.2)
├── class-wp-mcp-ai-db-executor.php             # §3.3 state machine
├── class-wp-mcp-ai-db-engine-pgsql.php         # session SQL (§4.3.1)
├── class-wp-mcp-ai-db-engine-mysql.php         # (§4.3.2)
├── class-wp-mcp-ai-db-engine-mariadb.php       # (§4.3.3)
├── class-wp-mcp-ai-db-engine-sqlite.php        # tests only
├── class-wp-mcp-ai-db-redactor.php             # masker bridge + PII filter
├── class-wp-mcp-ai-db-audit-log.php
├── class-wp-mcp-ai-db-executor-builder.php     # the one executor assembly (tools + preview)
├── class-wp-mcp-ai-db-limit-defaults.php       # gatherer promoted out of the admin page
└── class-wp-mcp-ai-db-proposal-store.php       # P6
```

### 5.3 New — Pro toolkit

```
addons/pro/includes/tools/database/
├── init.php
├── README.md
├── TOOLS_LIST.txt
├── TOOL_INDEX.md
├── class-wp-mcp-ai-database-settings.php
├── class-wp-mcp-ai-database-tool-base.php
├── class-wp-mcp-ai-database-presets.php
├── class-wp-mcp-ai-tool-db-query.php
├── class-wp-mcp-ai-tool-db-propose-write.php
├── class-wp-mcp-ai-tool-db-apply-write.php
├── class-wp-mcp-ai-tool-db-export.php
├── class-wp-mcp-ai-tool-db-test-connection.php
├── class-wp-mcp-ai-tool-db-audit-tail.php
└── tests/
addons/pro/includes/admin/class-wp-mcp-ai-database-settings-page.php
addons/pro/includes/toolkits/manifests/database.json
```

### 5.4 New — Laravel

```
lib/core/src/Tool/Database/                      # 11 shared tools + DatabaseTool base
├── DatabaseTool.php
├── ListConnectionsTool.php
├── DescribeTool.php
├── SelectTool.php
├── AggregateTool.php
├── SearchTool.php
├── ExplainTool.php
├── QueryTool.php
├── ProfileTableTool.php
├── TestConnectionTool.php
├── ExportTool.php
└── TailAuditTool.php
lib/laravel-adapter/src/Database/
├── DatabaseConfig.php                           # ConnectionRegistry + ScopeRegistry from config
├── PdoConnectionFactory.php                     # DSN + options (§4.1, §4.2)
├── InformationSchemaCatalogue.php               # one query set for pgsql + mysql-family
├── ScopeRedactor.php                            # kernel ColumnMasker + detail scrubbing
├── LoggerAuditSink.php                          # records to a callable / PSR-3
├── PdoDatabaseConnection.php                    # the port; assembles the seven collaborators
├── EloquentDatabaseConnection.php               # same port, app-supplied PDO
├── TableAuditLog.php                            # append-only ledger: sink + reader
└── Engine/                                      # §4.3 profiles, parity-tested against the WP engines
    ├── AbstractProfile.php
    ├── PgsqlProfile.php
    ├── MysqlProfile.php
    ├── MariadbProfile.php
    └── SqliteProfile.php
lib/laravel-adapter/database/migrations/
└── 2026_01_01_000011_create_oos_database_audit_table.php
```

**Where the orchestration lives.** P4 began by moving the read state machine out of
`WP_MCP_AI_Db_Executor` and into the kernel as `PdoExecutor`. The WordPress class is
now a 20-line subclass; both hosts run the same ordering, caps, shaping and audit.
Without that move the Laravel port would have been a second copy of the safety
argument — and a second place for it to rot.

**Why the engine profiles are still duplicated.** Moving `WP_MCP_AI_Db_Engine_*` as
well would have meant moving the factory and the probe in the same change; that was
too much churn for one phase. The duplication is guarded instead:
`tests/database/parity/EngineParityTest.php` asserts byte-identical `BEGIN`,
session SQL, bindings and `EXPLAIN` wrapping for all four engines across 36
combinations. Duplication under an equivalence test is a maintenance cost;
duplication without one is a defect waiting to happen.

**Two deliberate deviations from the original P4 wording:**

1. `EloquentDatabaseConnection` does not use `DB::transaction()` or the query
   builder. `DB::transaction()` opens a *writable* transaction, which would defeat
   `BEGIN READ ONLY`; and identifier safety already comes from the scope-filtered
   catalogue and the grammar, so the builder would add a second quoting path. The
   Eloquent variant supplies the handle and nothing else.
2. Eleven of the thirteen tools are ported. The two that are not —
   `db_propose_write`, `db_apply_write` — need a proposal store and a human
   approver. The write path stays WordPress-only because a headless API has no one
   to approve a proposal, and a two-phase write with a missing second phase is
   precisely what this design exists to prevent.
3. The audit trail differs by nature, deliberately. WordPress is a **bounded
   ring** (500 records in an option, `retained()` returns the cap); Laravel is an
   **append-only ledger** (`oos_database_audit`, `retained()` returns 0) that the
   library never prunes — an audit trail that deletes itself is not one. The
   ledger is bound unconditionally: an earlier draft made it a choice between the
   table and a PSR-3 line, which made `db_audit_tail` buildable only when the
   table was selected — and a `ready` readiness row that is conditional is a row
   that lies. `oos.database.audit.table` remains, for an estate with its own naming
   convention.
4. The toolkit toggle gates **what the port can see**, not whether the port
   exists. `oos.database.enabled` was originally read while binding
   `DatabaseQueryInterface`, so with the toggle off the eleven `db_*` rows were
   `ready` but unbuildable — the manifest promised tools the container would skip.
   It also could not work: a provider's `register()` runs before a Laravel app's
   runtime settings are known (and before Testbench's `defineEnvironment()`). The
   binding is now unconditional whenever the kernel is present, and the toggle is
   read when the port is *built*: disabled means an empty connection set, which the
   tools refuse with `db_not_configured` (404). Fail-closed, honest and observable.

### 5.5 Modified

| File | Change |
|---|---|
| `addons/pro/mcp-ai-wpoos-pro.php` | Gated block for all 13 Pro tools; `require_once` the kernel autoloader |
| `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php` | `$conditional_toolkits['database']`, `$dir_map['database']` |
| `includes/admin/sections/class-wp-mcp-ai-section-tools.php` | field + `features` subtab group + memory estimate |
| `includes/admin/class-wp-mcp-ai-pro-settings.php` | label map + status block (`tools_count` 13) |
| `addons/pro/includes/cli/class-wp-mcp-ai-pro-cli-toolkit-command.php` | `TOOLKITS` entry |
| `addons/pro/includes/cli/class-wp-mcp-ai-pro-cli-status-command.php` | `$toolkit_keys` entry |
| `addons/pro/includes/class-wp-mcp-ai-pro-schedule-presets.php` | merge `get_database_presets()` |
| `addons/pro/includes/class-wp-mcp-ai-pro-workflow-presets.php` | merge, **with `edges`** |
| `addons/schedule-anything-platform/includes/class-sa-toolkit-manager.php` | `TOOLKIT_FLAGS` + `TOOLKIT_ORDER` |
| `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php` | `database` validation branch, `test_connection()` dispatch, secret field, skip `trailingslashit` |
| `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php` | `database` option, field block, list badge |
| `bin/check-domain-purity.php` | two strict layers (D14) |
| `lib/core/resources/tool-readiness.php` | 13 rows with `evidence` |
| `lib/laravel-adapter/src/ServiceProvider/NvoosServiceProvider.php` | bind `DatabaseQueryInterface` |
| `includes/class-wp-mcp-ai-endpoint-capabilities.php` | console key `database` (+ `PRO_ONLY`, reason `route`) |
| `docker/laravel/app-skeleton/app/Support/EndpointCapabilities.php` | same key, reason `runtime-adaptable` |
| `docker/laravel/app-skeleton/contracts/console-endpoints.json` | key + fragment + the WordPress reason override |
| `addons/pro/assets/spa-v2/src/api/config.ts` | `database` endpoint key |
| `examples/nvoos-complete-velocity/src/runtime-config.ts` | `database` value (admin + WordPress only) |
| `examples/nvoos-pro-spa-vite/src/runtime-config.ts` | `database` value |
| `lib/core/src/Domain/ValueObject/ToolkitManifest.php` | `console` in `SCREENS` |
| `includes/database/class-wp-mcp-ai-db-runtime.php` | load `class-wp-mcp-ai-db-limit-defaults.php` |
| `includes/database/class-wp-mcp-ai-db-registry.php` | `put()` treats a byte-identical record as a successful no-op |
| `phpcs.xml.dist` | three file-scoped exemptions for the database subsystem, each with a stated reason (see §0.2 “Lint posture”) |
| `addons/pro/lib/database/src/Domain/Service/Database/PdoExecutor.php` | `preview()` — the compile half of `read()`, no transaction, no rows, no audit |
| `addons/pro/includes/tools/database/class-wp-mcp-ai-database-tool-base.php` | `executor()` and the three value helpers delegate to `WP_MCP_AI_Db_Executor_Builder` |
| `tests/database/portable/ExecutorSqliteTest.php` | 3 preview tests: compiles without executing, out-of-scope relation refused, foreign-connection scope refused |
| `addons/pro/lib/database/src/Domain/Service/Database/RefusalCode.php` | `EXPORT_LIMIT` (`db_export_limit`, 429, retryable) for the async queue-depth cap |
| `lib/core/src/Tool/Database/ExportTool.php` | optional queue port + `async` flag; `enqueue()`/`pendingExports()` |
| `lib/core/src/Tool/Database/DatabaseTool.php` | `EXPORT_LIMIT => 429` in the refusal status map |
| `addons/pro/includes/tools/database/class-wp-mcp-ai-tool-db-export.php` | `async` flag; the enqueue branch |
| `addons/pro/includes/tools/database/class-wp-mcp-ai-database-tool-base.php` | `assert_tier()` distinguishes “no grant” from “grant lacks the tier”; `EXPORT_LIMIT => 429` |
| `addons/pro/includes/tools/database/init.php` | require the async job class and register its hooks (cron context too) |
| `docker/laravel/app-skeleton/config/oos.php` | `oos.database` |
| `docker-compose.yml` | `postgres` test service |
| `.github/workflows/phpunit.yml` | Postgres service + **blocking** `database-postgres` job; **blocking** standalone `laravel-adapter` job |
| `tests/tools/.coverage-manifest.txt` | regenerated |
| `addons/pro/tests/tools/.coverage-manifest.txt` | regenerated |

### 5.6 New — P3 console, editor and preview

```
addons/pro/lib/database/src/Domain/ValueObject/Database/PreviewDescriptor.php   # literal-free compile result
addons/pro/includes/rest/class-wp-mcp-ai-pro-database-console-rest-controller.php
tests/rest-api/test-rest-database-console.php                                   # 17 tests / 96 assertions
addons/pro/assets/spa-v2/src/api/database.ts                                    # + saveScope, deleteScope, previewScope
addons/pro/assets/spa-v2/src/features/toolkits/database/
├── DatabaseConsole.tsx                # tabs; a background refresh keeps the panel mounted
├── ScopesPanel.tsx                    # list + New/Edit/Preview/Delete + the saved-version flash
├── ScopeEditor.tsx                    # id/label/connection, allowlist, tiers, column rules, row filters, limits
├── PreviewPanel.tsx                   # relation + optional columns → statement, masks, caps
└── __tests__/database-console.test.tsx # 9 tests
```

**P3 scope boundary (decision record).** The console is Observation (read-only GETs) +
Editor (scope CRUD) + Preview (compile, never execute). Scopes write through the
registry, never a hand-rolled validator, so the console and wp-admin cannot disagree
about what a valid scope is and the version bump has one owner. Preview goes through
`PdoExecutor::preview()`, never a controller-side reimplementation of the compile
order. A preview is **not** a tool call: no audit row. Refusal statuses mirror the tool
surface (`RefusalCode` → 404/403/504/503/429/422) through one `status_for()`.

### 5.7 New — P7 async export

```
addons/pro/includes/tools/database/class-wp-mcp-ai-db-export-job.php   # enqueue, run, route
lib/core/src/Tool/Database/DatabaseExportHandler.php                   # queue handler (core)
lib/core/tests/Support/FakeQueueClient.php
lib/core/tests/Unit/Tool/Database/ExportToolAsyncTest.php              # 5 tests / 31 assertions
tests/test-database-export-async.php                                   # 4 tests / 15 assertions
```

The handle is the existing job system: `WP_MCP_AI_Job_Store` + Action Scheduler on
WordPress, `NvoosToolJob` + `nvoos_jobs` on Laravel. `db_export` is its own poll
surface (`job_id`), so there is no new tool slug, readiness row or parity fixture.

---

## 6. Phase plans

### P0 — Kernel, providers, refusal codes (4–5 days)

**Goal:** a provable safety kernel with no tools, no UI, no network surface.

| # | Task | Acceptance |
|---|---|---|
| 0.1 | `RefusalCode` enum + `ErrorMapper` tables | every SQLSTATE/driver row in §4.5 has a test |
| 0.2 | Value objects, strict coercion | bad-shape input returns a `RefusalCode`, never a `TypeError` |
| 0.3 | `StatementGrammar` (identifiers §4.6, operators, metrics, binds) | identifier injection corpus refused; `%`/`_` escaped |
| 0.4 | `AdmissionClassifier` (engine-aware §4.3.6) | one assertion per corpus entry |
| 0.5 | `ResultShaper` (§4.7) | cell bomb, byte cap, numeric-as-string, ISO timestamps |
| 0.6 | `ColumnMasker` + `ScopePolicy` | `sensitive` masked; `exclude` absent; tier gate per tool |
| 0.7 | `CursorCodec` (§4.8) | tampered cursor refused; round-trip stable |
| 0.8 | `includes/connections/*` (interface, resolver, native provider) | two providers merge; unknown id refused; no Pro class loaded |
| 0.9 | `DbRuntime::is_available()` + reason (D15) | false **only** when the add-on's kernel is absent or PHP < 8.2 — never on `enable_oos_engine`, which routes chat rather than loading classes |
| 0.10 | `DbProbe` by introspection (A1) | detects a writable role, a `BYPASSRLS` role, a missing `SELECT` |
| 0.11 | `DbConnectionFactory` (§4.1, §4.2) | option assertions per engine (R4) |
| 0.12 | `DbEngine*` session SQL (§4.3) | unit conversion per engine (§4.4) |
| 0.13 | `DbExecutor` (§3.3) | rollback in `finally`; no open transaction after any failure |
| 0.14 | `DbAuditLog` | one record per call incl. refusals; `sql_template` literal-free |
| 0.15 | Site-DB refusal | `(host,port,db)` match refused; `allow_site_db` audited |
| 0.16 | Purity gate (D15) | `--strict` green on `addons/pro/lib/database/src` (32 files) |
| 0.17 | Postgres test service + `maybe_skip_postgres()` | suite passes with and without the service |
| 0.18 | Folder READMEs | `docs:check-folder-readmes` green |

**Gate:** `phpcs` clean; PHPUnit green both ways; **L2-removal test** passes against a `SELECT`-only role.

**Rollback:** delete `includes/database/`, `includes/connections/`, the two `lib/core` folders and the compose service.

---

### P1 — Base read surface on Postgres (6–8 days)

| # | Task | Acceptance |
|---|---|---|
| 1.1 | `db_list_connections` | lists only principal-visible connections, redacted |
| 1.2 | `db_describe` | system schemas absent; excluded table/column absent |
| 1.3 | `db_profile_table` | bounded (null %, approx distinct, min/max, top-5) |
| 1.4 | `db_explain` | `FORMAT JSON`; `analyze` refused without the tier |
| 1.5 | `db_select` | projection/filters/order/limit/cursor; refusal on disallowed relation |
| 1.6 | `db_aggregate` | each metric; `UNSUPPORTED_METRIC` on sqlite |
| 1.7 | `db_search` | declared searchable columns only; wildcard escaped |
| 1.8 | Parameter schemas | closed enums via `array_map('strval', …)`; `additionalProperties false` |
| 1.9 | Canonical envelopes | success array or `WP_Error`; two-gate sanitisation |
| 1.10 | Pro toolkit registration | kernel available + toggle + clean probe, all three |
| 1.11 | Pro toolkit scaffold + 11 surfaces | §8 checklist complete |
| 1.12 | Settings page (native connections) | CRUD, encryption (A2), `pdo_pgsql` probe |
| 1.13 | Remote Sites adapter (`database` type read) | `connection_type = database` resolved; others ignored |
| 1.14 | `principals` from `_wp_mcp_ai_pro_remote_connections` | scope narrows, never widens |
| 1.15 | Coverage manifests | only new classes in the diff |

**Gate:** an assistant answers a real question against Docker Postgres; every call audited; zero Pro classes on a Base-only run.

---

### P2 — RLS, row filter, MySQL/MariaDB (3–4 days)

| # | Task | Acceptance |
|---|---|---|
| 2.1 | `set_config('app.tenant_id', …, true)` in the transaction | two-tenant fixture cannot leak |
| 2.2 | Row-filter injection (always ANDed) | a request that "forgets" the tenant still filters |
| 2.3 | `FILTER_UNMAPPABLE` refusal | relation without the column refuses |
| 2.4 | `MysqlAdapter` + `MariadbAdapter` | §4.3.2/§4.3.3 session SQL; §4.4 conversion |
| 2.5 | MySQL PHP-side deadline | `SELECT SLEEP(30)` is cut at the limit |
| 2.6 | RLS bootstrap generator | `FORCE`, non-owner, `NULLIF`, `(tenant_id, …)` index note, `plan_cache_mode` note |
| 2.7 | Refusal-rate counter → posture signals (016) | a refusal spike is visible |

**Gate:** cross-tenant probe returns zero rows; a filterless scope refuses; the MySQL timeout unit test fails if milliseconds/seconds are swapped.

---

### P3 — Console, audit, Remote Sites type (4–5 days) — **shipped: observation + editor + preview**

| # | Task | Acceptance |
|---|---|---|
| 3.1 | `database.json` manifest, 4 screens | ✅ one `console` screen with four tabs (Connections / Scopes / Activity / Health) plus the settings screen; the engine's closed vocabulary gained `console`, dispatched by slug |
| 3.2 | Scopes screen | ✅ read-only tree, masks, tiers, filter list, principals and limit overrides, **plus** `New scope` / `Edit` / `Delete` through `POST /scopes` and `DELETE /scopes/{id}`; server refusals map back onto the offending field; `Preview` per saved scope |
| 3.3 | Live preview | ✅ `POST /preview` runs `PdoExecutor::preview()` — resolve, tier, servability, tenant, catalogue, relation, grammar, admission — then stops; renders the statement template, binding count, projected / masked / excluded / searchable columns and effective caps. Embedded in the editor so a draft can be compiled before it is saved. Executes nothing and writes no audit row |
| 3.4 | `db_audit_tail` + Activity screen | ✅ filters (connection, refusal, tool, action), refusal codes highlighted, client-side CSV, 24 h summary, ring capacity stated |
| 3.5 | `database` type in Remote Sites | ✅ done in P1 |
| 3.6 | Health screen | ✅ kernel availability + reason, PDO drivers, stored probes (reachable / role / can_write / bypasses_rls / read-safe), hourly call + refusal sparkline |

**Gate:** provider equivalence (proposal gate 12) — Remote Sites record and native record behave identically.

**Observation is read-only; the grant is editable.** Every observation route is a GET; `POST /connections/{id}/test` is the only call that opens a socket, and only because a human clicked Test. The console writes exactly one kind of record — a scope — through `POST /scopes` / `DELETE /scopes/{id}`, i.e. the same registry wp-admin uses, because a scope is a security boundary and the rule about what a valid one is must have one owner. It never writes a connection and never writes a database row: `POST /preview` runs the executor's compile order and stops before `prepare()`. The route family is `mcp-ai-pro/v1/database`, gated on `manage_options`, and the endpoint key is `runtime-adaptable` on Laravel (config files, no console) so the shared `gaps` invariant stays empty.

---

### P4 — Laravel port (4–6 days) — **done, with two documented deviations**

| # | Task | Acceptance |
|---|---|---|
| 4.1 | `DatabaseQueryInterface` + value objects | ✅ already in the kernel; no WordPress type in any signature |
| 4.2 | `EloquentDatabaseConnection` | ✅ supplies the app's PDO; `DB::transaction()` and the builder deliberately not used (see §5.4) |
| 4.3 | `PdoDatabaseConnection` | ✅ external DSNs; §4.3 session SQL, parity-tested |
| 4.4 | 13 tools in `lib/core/src/Tool/Database/` | ⚠️ 11 of 13; `db_propose_write` and `db_apply_write` are WordPress-only by decision |
| 4.5 | `oos.database` config | ✅ connections, scopes, limits, `write_path` false, `raw_sql` false; a malformed scope is dropped, not widened |
| 4.6 | Provider binding + gates | ✅ `scoped` binding, fail-closed without an authenticated user, `oos.read_only` still wins |
| 4.7 | 13 readiness rows with `evidence` | ✅ 11 rows (`ready`/`port`/`call`); audit 530/530 |
| 4.8 | Parity fixtures | ✅ 92 structural assertions (contracts + engine profiles) and 12 live Postgres tests |
| 4.9 | Purity gate on both new layers | ✅ kernel 41 files, 0 violations |

**Gate:** proposal gate 9 green in both halves — structural (no database, 92
assertions) and live (PostgreSQL with a SELECT-only role, 39 tests).
`grep -r WP_MCP_AI_Pro_ lib/` returns nothing.

Note for replica routing: Laravel supports `read`/`write` host splitting inside a connection definition, but the app skeleton's `pgsql` block does **not** use it today (`docker/laravel/app-skeleton/config/database.php`). Replica routing on Laravel therefore comes from `oos.database.connections.*.read_replica` through `PdoDatabaseConnection`, not from Laravel's built-in split — documented so the two mechanisms are not confused later.

---

### P5 — Raw SQL + explain-analyze (3–4 days)

| # | Task | Acceptance |
|---|---|---|
| 5.1 | `db_query` (tier `raw_sql`) | prepared only; stacked input refused by L2 **and** by the server (R3) |
| 5.2 | `db_explain(analyze)` | tier `explain_analyze`; refused on sqlite |
| 5.3 | Per-tool MCP scopes | `db_select` auto-allowable, `db_apply_write` not |
| 5.4 | Corpus extended to 60+ | one assertion each |

**Gate:** proposal gates 6 and 7.

---

### P6 — Write path (4–5 days)

| # | Task | Acceptance |
|---|---|---|
| 6.1 | `DbProposalStore` | proposal with statement, binds, estimate, rationale, scope version, expiry |
| 6.2 | `db_propose_write` | never executes; refuses when `max_affected_rows` is 0 |
| 6.3 | `db_apply_write` | re-counts inside the transaction; refuses on drift; `ApprovalTicketInterface` |
| 6.4 | Default-off gates both layers | a test asserts neither tool registers by default |
| 6.5 | Conditional `financial-impact` flag | necessity gate raises `approval` |

**Gate:** unapproved proposal cannot execute; count-shifted proposal refuses; disabling the gate removes both tools everywhere.

---

### P7 — Exports + presets (2–3 days) — **shipped**

| # | Task | Acceptance |
|---|---|---|
| 7.1 | `db_export` async → file registry (162) | ✅ `async: true` returns a `job_id`; passing `job_id` back to the same tool reads the export (`poll_with: db_export`, `poll_arguments`); the worker re-enters the tool so the grant in force *then* applies; the checksum, file and audit id are stored as the job result; queue depth is capped at enqueue — per user on WordPress, per handler on Laravel, whose job port exposes no owner (`db_export_limit`, 429, retryable) |
| 7.2 | Schedule + workflow presets | ✅ `get_database_presets()` merged into the schedule presets, and the workflow preset carries valid `edges` |
| 7.3 | CLI surfaces | ✅ `wp mcp-ai toolkit` / `status` list the toolkit |

**Async export — decision record.** The handle reuses the existing queue port and job
store rather than adding an export-jobs table. **The poll surface is `db_export`
itself**: `async: true` returns a `job_id`, and passing that `job_id` back (instead of
`relation`) returns the job's status and, when finished, its file, checksum and audit
id. That costs one argument instead of a fourteenth tool slug, a readiness row and a
parity fixture — and it is necessary, not merely tidy: the plugin's engine bridge maps
WordPress tools *into* the core registry, never the reverse, so the generic
`get_job_status` tool is not callable from a WordPress assistant, and the toolkit
deliberately does not depend on the engine. Status vocabulary follows MCP Tasks
(SEP-1686: `queued` … `completed`/`failed`/`cancelled`) so a future `ext-tasks` adapter
maps the handle onto a `taskId` instead of redesigning it; the transport extension
itself is out of scope. Two properties are non-negotiable and tested on both hosts:
**the payload carries arguments and a principal, never a resolved scope** (a narrowed
or deleted grant refuses at run time), and **enqueue asserts the tier** so an assistant
without `export` cannot create a job that will fail unattended. Reading a job back
also asserts the tier, so revoking a grant revokes the artefact; another principal's
job answers 404, not 403. The queue-depth cap is a safety valve rather than a quota,
and it is asymmetric on purpose: `WP_MCP_AI_Job_Store` has a `user_id` column, so
WordPress caps per user, while the Laravel job port carries no owner, so its cap is
per handler (the stricter reading). On WordPress the dispatcher prefers Action
Scheduler and falls back to WP-Cron; that fallback previously had no listener in the
plugin, so `WP_MCP_AI_Db_Export_Job::route()` now dispatches any named handler that
registered itself. The Laravel side refuses async with a 501 when no queue port is
bound, which keeps the published `nvoos/core` constructor contract intact.

---

## 7. Test strategy

### 7.1 Fixtures

One Postgres database, two tenants with deliberately skewed sizes (e.g. 200 k rows and 400 rows), plus:

- a `financial` table for the necessity-gate flag,
- a relation **without** the tenant column (for `FILTER_UNMAPPABLE`),
- a view with `security_barrier`,
- a `text` column holding a 4 MiB value (cell bomb),
- a table owned by the agent role (to prove `FORCE` matters).

Committed as SQL under `tests/database/fixtures/`; loaded once per suite. A `MySQL` fixture mirrors it minus RLS.

### 7.2 Harness

```php
trait PostgresFixture {
    protected static function maybe_skip_postgres(): void {
        if ( ! getenv( 'NVOOS_TEST_PGSQL_DSN' ) ) {
            self::markTestSkipped( 'NVOOS_TEST_PGSQL_DSN not set.' );
        }
    }
}
```

The same pattern for MySQL. The suite passes on a laptop with neither service; the gates are explicitly "green where the service exists".

### 7.3 Hostile corpus (committed, one assertion each)

| Family | Entries |
|---|---|
| Stacking | `SELECT 1; DROP TABLE t`, `SELECT 1;COMMIT`, `SELECT 1;--` + newline + `DELETE` |
| Transaction escape | `COMMIT`, `ROLLBACK`, `END`, `ABORT` as the whole statement |
| CTE write | `WITH d AS (DELETE FROM t RETURNING *) SELECT * FROM d` |
| Privilege | `SET ROLE app_admin`, `SET SESSION AUTHORIZATION`, `SET search_path = public`, `RESET ROLE` |
| File / OS (pgsql) | `COPY t FROM PROGRAM 'id'`, `pg_read_file('/etc/passwd')`, `lo_import`, `dblink` |
| File / OS (mysql) | `LOAD_FILE('/etc/passwd')`, `INTO OUTFILE`, `LOAD DATA` |
| Identifier | relation `users; DROP TABLE x`, column `a" , (SELECT 1)`, 64-byte name, `pg_catalog.pg_authid` |
| Value | `' OR 1=1 --`, `%`, `_`, backslash, NUL byte, 4 MiB string |
| Volume | unbounded `SELECT`, `OFFSET 100000`, `SELECT SLEEP(30)`, cartesian join |
| Scope | disallowed relation, disallowed column, unmappable filter, missing tier, foreign cursor |

### 7.4 Suite placement

| Suite | Location | Job |
|---|---|---|
| Portable kernel | `addons/pro/lib/database/tests/Unit/Domain/Service/Database/` | every PHP job (no WordPress, no Composer) |
| WordPress kernel | `tests/database/portable/` | PHPUnit |
| Pro toolkit | `addons/pro/tests/` + `tests/pro/tools/database/` | Pro job |
| Base+Pro gate | `tests/basepro/` | `phpunit-basepro.xml.dist` |
| **Postgres live (gates 6, 7)** | `tests/database/postgres/` | `database-postgres` job |
| **Cross-host parity (gate 9)** | `tests/database/parity/` | `database-postgres` job, no service needed |
| Laravel tools | `lib/core/tests/Unit/Tool/Database/` | core job (`--no-configuration --bootstrap`) |

### 7.5 Gates

1 `php -l` + `phpcs` · 2 folder READMEs · 3 full PHPUnit · 4 basepro init gate · 5 preset sanity (`edges`) · 6 L2-removal · 7 hostile corpus · 8 scope-preview fidelity · 9 cross-runtime parity · 10 admin-surface smoke · 11 provider independence · 12 provider equivalence · 13 domain purity (`--strict` on `addons/pro/lib/database/src`) · 14 availability guard.

Gates **6**, **11** and **13** are the ones that make the design true rather than merely documented. A phase does not merge with any of them red.

**Status (2026-11-28).** 6 and 7 are green against `postgres:18-alpine` with `nvoos_reader` (SELECT only, NOBYPASSRLS) — the suite proves a write still fails at the role with admission bypassed. 9 is green in both halves: `tests/database/parity/` compares tool contracts and engine profiles without a database, and `LaravelAdapterTest` runs the Laravel binding against the same fixture. 13 is green at 41 files. The Postgres job in `.github/workflows/phpunit.yml` runs non-blocking while it beds in; remove `continue-on-error` after a few consecutive green runs.

---

## 8. Registration checklist (P1)

| # | Surface | Exact edit |
|---|---|---|
| 1 | Pro Module Registry | `$conditional_toolkits['database'] = 'Database'`; `$dir_map['database'] = 'database'` |
| 2 | Central registry | gated block, 6 class → file pairs |
| 3 | Tools section | field in `get_fields()` **and** `features` subtab group; memory estimate |
| 4 | Settings page | `WP_MCP_AI_Database_Settings_Page::init()` inside the enable gate |
| 5 | Pro settings map | label + status block |
| 6 | `wp mcp-ai toolkit` | `TOOLKITS` entry |
| 7 | `wp mcp-ai status` | `$toolkit_keys` entry |
| 8 | Schedule presets | merge + method |
| 9 | Workflow presets | merge + method **with `edges`** |
| 10 | SA Toolkit Manager | flag + order |
| 11 | Coverage manifests | regenerate, commit both |

Surfaces **1** and **3** are the documented silent-failure modes. Pin both with tests in the same PR.

---

## 9. CI changes

| Job | Change |
|---|---|
| `phpunit.yml` | add a `postgres:18` service; export `NVOOS_TEST_PGSQL_DSN`; keep the Postgres-dependent tests in a separate, non-blocking step until P2 lands |
| new `purity.yml` step (or existing PHP job) | `php bin/check-domain-purity.php --strict --only=lib/core/src/Domain/Service/Database` and the same for `lib/core/src/Tool/Database` |
| tool-readiness | unchanged; picks up the 13 rows |
| coverage manifests | unchanged script; diff must be additions only |

---

## 10. Effort

| Phase | Days | Notes |
|---|---:|---|
| P0 | 4–5 | Most of the value; most of the review |
| P1 | 6–8 | UI + registration surfaces dominate |
| P2 | 3–4 | Engine asymmetry is the risk |
| P3 | 4–5 | Console, audit and health, scope editor and compile-only preview |
| P4 | 4–6 | Parity fixtures are the work |
| P5 | 3–4 | Corpus expansion |
| P6 | 4–5 | Approval + drift check |
| P7 | 2–3 | Sync export, async hand-off through the queue port, presets, CLI |
| **Total** | **~31–42** | plus review |

---

## 11. Risk register

| Risk | Likelihood | Mitigation |
|---|---|---|
| A Pro class creeps into the kernel | Medium | Gate 11; grep `lib/` for `WP_MCP_AI_Pro_` |
| WordPress or I/O creeps into the portable kernel | Medium | Gate 13 (`--strict`); PDO only in `includes/database/`, `Illuminate` only in `lib/laravel-adapter/` |
| Toolkit half-serves on a `lib/`-less or PHP 8.1 build | Medium | Gate 14; `unavailable`, never partial |
| Admission drifts from DB behaviour | Medium | Gate 6 makes the role the control; corpus is committed |
| MySQL/MariaDB timeout unit swapped | **High** if untested | §4.4 table + one unit test per engine; a wrong unit is a 1000× bug |
| `pdo_pgsql` / `pdo_mysql` absent on a host | Medium | Extension probe + typed `EXTENSION_MISSING`; Cloudways path documented |
| Console ships before enforcement is right | Medium | P2 before P3, explicitly |
| Write path shipped enabled | Low | Two independent default-false gates; asserted by test |
| Token blowout from a wide result | Medium | Byte cap + keyset cursor + streaming fetch; cell-bomb test |
| Remote Sites refactor breaks other toolkits | Low | Additive `connection_type` branch; existing type tests untouched |
| RLS plan caching degrades a large tenant | Low | Documented (§4.3.7); `plan_cache_mode` guidance, not default |
| Postgres absent in CI | High | `maybe_skip_postgres()`; gate documented as "green where available" |

---

## 12. Rollout and rollback

- **Rollout:** P0 merges inert. P1–P2 behind `enable_database_toolkit` (default false) and a clean probe. The P3 console renders an empty state (with a link to wp-admin) when nothing is declared, rather than disappearing — a page that vanishes reads as a bug, and the route stays useful on a fresh install. P5/P6 behind their own tiers **and** default-false gates.
- **Rollback:** disable the toggle → every tool unregisters, the console disappears, scopes and audit remain readable through WP-CLI. No data migration to reverse; connections and scopes are options/settings, proposals are records.
- **Data retained on uninstall** unless the operator opts into deletion, matching the plugin's lifecycle discipline.

---

## 13. Definition of done

- Eight phases merged in order, each with its gate green.
- Gates 1–14 green, with 6, 11 and 13 cited in the PR that closes P2.
- `167-*` status updated per phase; amendment rows for A1–A3 applied to the proposal.
- Operator runbook: "scoped Postgres access in ten minutes" — matching the proposal's success metric.
- Follow-up tickets: D9 (`ProductCatalog`), D10 (Graphify), D13 (gateway).

---

## 14. The first PR

P0 only, nothing else:

1. `addons/pro/lib/database/` — package scaffold (`autoload.php`, `composer.json`, `phpunit.xml.dist`, `tests/bootstrap.php`) with the 31 classes and their standalone suite.
2. `includes/connections/` (4 classes + README) + resolver test.
3. `includes/database/` runtime, probe, factory, engines, executor, audit + README, with site-DB refusal and the L2-removal test.
4. Purity-gate layers + availability guard.
5. Postgres test service + `maybe_skip_postgres()`.
6. `phpcs` clean; no tools registered; no UI; no Pro reference.

That PR is independently reviewable and proves the security claim before any surface exists.

---

## Appendix A — PostgreSQL bootstrap (generated, abridged)

```sql
CREATE ROLE nvoos_agent_ro LOGIN PASSWORD :'password'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;

ALTER ROLE nvoos_agent_ro SET default_transaction_read_only = on;
ALTER ROLE nvoos_agent_ro SET statement_timeout = '5s';
ALTER ROLE nvoos_agent_ro SET idle_in_transaction_session_timeout = '10s';
ALTER ROLE nvoos_agent_ro SET lock_timeout = '2s';
ALTER ROLE nvoos_agent_ro SET search_path = reporting, pg_catalog;
-- Large RLS tables only; costs performance for the common case:
-- ALTER ROLE nvoos_agent_ro SET plan_cache_mode = force_generic_plan;

GRANT CONNECT ON DATABASE analytics TO nvoos_agent_ro;
GRANT USAGE ON SCHEMA reporting TO nvoos_agent_ro;
GRANT SELECT (order_id, tenant_id, total_minor, placed_at)
  ON reporting.orders TO nvoos_agent_ro;

ALTER TABLE reporting.orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE reporting.orders FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON reporting.orders
  USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

-- Index that makes the policy cheap; tenant first.
CREATE INDEX orders_tenant_placed_idx ON reporting.orders (tenant_id, placed_at DESC);

-- pgAudit baseline (commented). log_parameter captures VALUES — a privacy decision.
-- ALTER ROLE nvoos_agent_ro SET pgaudit.log = 'read, ddl, role';
-- Monitoring is a different role; never blend it with the agent role.
```

## Appendix B — MySQL/MariaDB bootstrap (abridged)

```sql
CREATE USER 'nvoos_agent_ro'@'%' IDENTIFIED BY :'password';
GRANT SELECT (order_id, tenant_id, total_minor, placed_at)
  ON `analytics`.`orders` TO 'nvoos_agent_ro'@'%';
-- No RLS. The kernel's row filter is the only tenant control, and the toolkit
-- refuses a tenant scope whose relation has no tenant column.
-- MariaDB: ALTER USER ... SET MAX_STATEMENT_TIME = 5;
-- MySQL:   ALTER USER ... SET MAX_EXECUTION_TIME = 5000;  -- milliseconds, SELECT only
```

## Appendix C — Probe queries (A1)

```sql
-- pgsql
SELECT current_user,
       version()                                   AS server_version,
       current_setting('transaction_read_only')     AS transaction_read_only,
       r.rolsuper, r.rolbypassrls, r.rolcreatedb
FROM pg_roles r WHERE r.rolname = current_user;

SELECT has_schema_privilege(current_user, 'reporting', 'CREATE') AS can_create;
SELECT has_table_privilege(current_user, 'reporting.orders', 'INSERT')  AS can_insert,
       has_table_privilege(current_user, 'reporting.orders', 'UPDATE')  AS can_update,
       has_table_privilege(current_user, 'reporting.orders', 'DELETE')  AS can_delete;
```

`can_write` is true if any of the above is true. A connection whose probe reports `can_write` is registered as `unavailable` for any scope that does not hold `propose_write`/`apply_write`, and the reason is recorded — it is never silently downgraded.

## Appendix D — Cursor payload

```json
{ "v": 1, "s": "analytics-readonly", "t": "reporting.orders",
  "o": ["placed_at", "order_id"], "d": "desc", "k": [1730419200, 88123],
  "h": "hmac-sha256:<hex>" }
```

Decode order: base64url → JSON → verify `h` over the canonical payload → compare `s`/`t`/`o`/`d` with the live request; any mismatch is `STATEMENT_NOT_ADMITTED`, not a silent restart.
