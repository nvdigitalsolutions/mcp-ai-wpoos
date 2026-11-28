# 167 — Research: secure database access for AI assistants

**Companion to:** [`167-database-toolkit-scoped-access-proposal.md`](./167-database-toolkit-scoped-access-proposal.md) · [`167-database-toolkit-scoped-access-implementation-plan.md`](./167-database-toolkit-scoped-access-implementation-plan.md)
**Researched:** 2026-11-27
**Method:** targeted web search across vendor documentation, primary database documentation, and independent 2026 security write-ups; every finding cross-checked against the repository where it changed a design decision. Source tier is labelled: **[P]** primary (vendor/database documentation, protocol spec), **[S]** secondary (independent analysis, vendor blog), **[R]** repository fact.

---

## 1. Why this research exists

The proposal's central claim is that "read-only" is not a property you configure — it is a property of *where* you enforce it. That claim only holds if the 2026 evidence is read accurately, because the failure modes are specific and the mitigations are asymmetric. This document records what was found, what was rejected, and what remains unproven.

---

## 2. Findings

### 2.1 Read-only enforcement in SQL MCP servers was broken in public during 2026

**Finding.** Two independent studies in 2026 dismantled the "wrap it in a read-only transaction" design:

- **Datadog Security Labs (April 2026)**, as summarised: the official `@modelcontextprotocol/server-postgres` wrapped queries in a read-only transaction but did not reject multi-statement input. A `COMMIT` in the model's input ended the transaction early; everything after it executed with the connection's full privileges. The package had been archived as production-unready in May 2025 and was still averaging roughly 21,000 weekly npm downloads when the study landed. **[S — read via secondary summary; primary report not retrieved]**
- **Maximilian Hildebrand (July 2026)**, as summarised: read-only bypasses in **14 SQL MCP servers**, clustering into three patterns — insufficient denylists (missing `SET`, which can change a role's password), insufficient validation (multiple statements, or a `WITH` clause chaining into a follow-up statement), and unprepared statements (tool arguments concatenated into SQL). At least one chain reached **remote code execution** on PostgreSQL. The affected list included the official MariaDB MCP server. **[S — read via secondary summary; primary report not retrieved]**

**Consequence.** The kernel cannot be a flag. Layer 1 is a role with `SELECT`-only grants; layer 2 is admission; the test that proves the design *deletes layer 2* and asserts the database still refuses the write. This is implementation-plan gate 6.

**Also adopted from the same body of guidance:** reject `SET ROLE` / `SET SESSION AUTHORIZATION`, reject `COPY … FROM PROGRAM`, `pg_read_file`, `lo_import`, `dblink`; log and alert on refusal spikes, because that is the signal the researchers used to find vulnerable servers.

### 2.2 The strongest structural control is the prepared statement, and it is engine-specific

**Finding (PostgreSQL).** PostgreSQL itself rejects multiple commands inside a single prepared statement — the error is `cannot insert multiple commands into a prepared statement` (SQLSTATE `42601`). The "World of SELECT-only PostgreSQL Injections" paper (Phrack #71) treats this as the reason native prepares are a stacked-query barrier: *"the PostgreSQL database itself does not allow multiple queries inside a single prepared statement — so we are suddenly constrained to a single SELECT query."* **[P/S]**

**Finding (PDO).** Two PDO behaviours decide whether that protection is actually in play:

- `pdo_pgsql` defaults to native prepares; emulation routes through `PQexec`, which **does** accept multiple statements. Emulation must therefore be explicitly `false`, not merely assumed off.
- `pdo_mysql` **emulates prepares by default**, and `PDO::MYSQL_ATTR_MULTI_STATEMENTS => false` must be passed in the **constructor**; setting it later with `setAttribute()` has no effect. The MariaDB knowledge base documents stacked queries working through `query()` when emulation is on. **[P]**

**Consequence.** The executor must use `PDO::prepare()` + `execute()` for every scoped statement and must never use `PDO::exec()`/`PDO::query()`. Connection options are engine-specific and asserted in a unit test (plan §4.2). This is a *correction* to a naive reading of "PDO with emulation off" as a portable setting.

### 2.3 Tenant scope must be transaction-local

**Findings.** Consistently across 2026 RLS write-ups:

- `FORCE ROW LEVEL SECURITY` plus a **non-owner, non-`BYPASSRLS`** role is required; otherwise the table owner bypasses policies entirely.
- Tenant context must be set with `SET LOCAL` or `set_config(…, true)` **inside an explicit transaction**. A plain `SET` persists on the session, and under PgBouncer's default transaction pooling the next client inherits it — one measurement showed the next client, which set no tenant, counting the largest tenant's rows.
- `NULLIF(current_setting('app.tenant_id', true), '')` makes a reset connection return **zero rows** rather than raising. Fail closed, not fail loud-and-leaky.
- The tenant must not be a value the model supplies; it comes from the authenticated session. **[S]**

**Consequence.** Proposal §167.4.2 and §167.6.5; plan §4.3.1. The row filter exists so the same guarantee holds on MySQL/MariaDB, which have no RLS.

### 2.4 RLS costs performance in two specific ways, and one of them interacts with prepared statements

**Findings.**

- **Leakproofness.** PostgreSQL will not push a non-`LEAKPROOF` function below the policy filter, because the function could leak hidden rows through errors or timing. `lower()` and `LIKE` are the common offenders, so an index on `lower(email)` is not used under RLS. Measured fixes: make the comparison leakproof, or store a generated column and index `(tenant_id, generated_column)`. **[S]**
- **Plan caching.** A prepared statement plans once; under RLS the tenant predicate comes from `current_setting()`. A server-side statement can therefore keep the plan — and the statistics — of the tenant it was first planned for. Measured on tenants of very different sizes. **[S]**

**Consequence.** Bootstrap guidance asks for composite `(tenant_id, …)` indexes with the tenant first; `db_search` escapes wildcards rather than wrapping columns in functions; plan §4.3.7 records the plan-cache caveat with `plan_cache_mode = force_generic_plan` as an operator option, not a default. Correctness is not affected — the policy applies at execution regardless of plan choice — so this is deliberately a performance note, not a safety control.

### 2.5 "Curated, parameterised queries" is the industry shape

**Findings.**

- **Google MCP Toolbox for Databases** declares tools in `tools.yaml`, each mapping to a parameterised statement; the model fills in blanks and *"performs no SQL"*. Only a prebuilt tool allows arbitrary SQL. It is described as a control plane: centralised tool definitions, auth, query safety, observability. **[P/S]**
- **`datomatic/laravel-database-mcp`** (2026) takes the strict reading: a read-only Laravel DB MCP server exposing two tools with **structured parameters, never raw SQL**. **[S]**
- **Bytebase's** comparison of multi-database MCP servers: *"a raw server lets the agent compose any statement; here you write and parameterise the queries, and the agent only fills in the blanks."* **[S]**

**Consequence.** The default surface is `db_select` / `db_aggregate` / `db_search` over a closed operator and metric vocabulary. Raw SQL is a separate tier, off by default, last to ship (D3, P5).

### 2.6 Statement timeouts are not portable — the units and coverage differ

**Findings.**

| Engine | Setting | Unit | Applies to |
|---|---|---|---|
| PostgreSQL | `statement_timeout` | milliseconds (integer or `'5s'`) | any statement |
| MySQL | `max_execution_time` | **milliseconds** | **`SELECT` only** |
| MariaDB | `max_statement_time` | **seconds (fractional)** | any statement |

The MariaDB documentation states explicitly that "the MySQL version of `max_statement_time` (`max_execution_time`) is defined in milliseconds, not seconds." MySQL's own blog describes `max_execution_time` as a millisecond limit for `SELECT`. **[P]**

**Consequence.** Plan §4.4 translation table, plus a **PHP-side deadline in the fetch loop** — mandatory for MySQL (because `max_execution_time` cannot stop a slow non-`SELECT`) and for SQLite (which has none). A swapped unit is a 1000× bug, so there is one unit test per engine. Also: `fetchAll()` is banned, because a cap applied after materialisation is not a cap.

### 2.7 Errors must be mapped by code, never by message

**Findings.** PostgreSQL assigns a five-character SQLSTATE to every error, and its documentation is explicit that applications "should usually test the error code, rather than looking at the textual message": `42501` insufficient privilege, `57014` statement timeout, `42P01` missing relation, `25006` read-only transaction, `28P01` bad password, `3D000` unknown database, `53300` too many connections, `55P03` lock not available. **[P]**

**Consequence.** `RefusalCode` is a backed enum; `ErrorMapper` holds per-engine tables; the driver message is never surfaced (it can echo the query or the data). MySQL codes are read from `errorInfo[1]` (1142/1143/1044 privilege, 3024 timeout, 1205 lock wait, 1045 auth, 1049 unknown DB, 1146 missing table, 1054 missing column, 1040 connection limit).

### 2.8 `search_path` is a security boundary, and `SECURITY DEFINER` is a footgun

**Findings.** `SECURITY DEFINER` functions run with the owner's privileges but the caller's path. The documented repair is a function-level `SET search_path` listing only trusted schemas with `pg_temp` **last** — omitting it puts `pg_temp` implicitly first, which is the CVE-2007-2138 class. The community review heuristic: *"any `SECURITY DEFINER` function without an explicit `search_path` should be treated as a finding."* **[P/S]**

**Consequence.** The generator ships **no** `SECURITY DEFINER` objects; views use `security_barrier` and pin their path; the session pins `search_path` and always emits schema-qualified identifiers so the path is a safety net rather than a resolution mechanism.

### 2.9 Audit is two layers because they see different things

**Findings.** pgAudit produces a database-level trail that captures direct `psql`, ETL and DBA sessions — traffic an application log cannot see — and is the recognised route to SOC 2 / HIPAA / PCI evidence. The recommended baseline is low-volume/high-value (`pgaudit.log = 'ddl, role'`), scoped to the application role; `log_parameter` captures actual values and is therefore a privacy decision, not a default. **[P/S]**

**Consequence.** Application audit for every call (including refusals) with a literal-free SQL template; commented pgAudit lines in the bootstrap with the trade-off stated; neither is presented as a substitute for the other.

### 2.10 Agent authorization is about the token's audience and per-tool scope

**Findings.** MCP servers are OAuth **Resource** Servers, not Authorization Servers (June 2025 revision); the 2025-11-25 revision carries per-tool scopes; the 2026-07-28 revision requires Protected Resource Metadata (RFC 9728), PKCE S256, and the resource parameter (RFC 8707) on authorization and token requests, with the rule that a server must not accept tokens not issued for it. Independent analyses add the practical failure: agents inherit the orchestrator's full scope by default, and tool chaining composes privileges nobody granted. **[P/S]**

**Consequence.** Per-tool scopes so a host can auto-allow `db_select` and prompt for `db_apply_write`; scope bound to a principal set; `apply_write` never implied by `propose_write`. Publishing scopes to the gateway is deferred (D13), but scope ids are kept stable and audience-safe so they can become an RFC 8707 resource identifier without a rename.

### 2.11 Connection economics in PHP

**Findings.** No connection pooler exists in a PHP request lifecycle; read replicas are the standard offload for analytics; query-stampede guidance recommends tightening thresholds gradually and revisiting as data grows. **[S]**

**Consequence.** One lazily-opened, non-persistent PDO handle per connection per request (also removes the RLS plan-cache and GUC-leak hazards); optional `read_replica` per connection. Note recorded in the plan: Laravel *can* split `read`/`write` hosts inside a connection, but the app skeleton's `pgsql` block does not use it today, so replica routing on Laravel comes from `oos.database`, not from Laravel's built-in split — keeping two mechanisms from being confused later. **[R]**

### 2.12 Repository facts that changed the design

| Fact | Evidence | Effect |
|---|---|---|
| A base+pro install (WordPress.org base package + this add-on) has **no `lib/` at all** — the base build passes `--exclude '/lib'`, and the Pro zip carries no `lib/` either | `bin/build-plugin-zip.sh` **[R]** | **D15**: kernel ships inside the add-on (`addons/pro/lib/database/`), so complete and base+pro both work |
| `bin/check-domain-purity.php` gates `includes/domain`, `includes/application`, `includes/services`, `includes/repositories` and the WiFi addon's three layers — not `lib/core` | the script's `$layers` map **[R]** | The kernel ships in the add-on; its layer is enrolled strict (gate 13) |
| `WP_MCP_AI_Encryption` already exists with master-key support, and Remote Sites' `encrypt_value()` prefers it | `includes/class-wp-mcp-ai-encryption.php`, `class-wp-mcp-ai-pro-remote-site-manager.php::encrypt_value()` **[R]** | A2: no new cipher; native records reuse it |
| Remote Sites is the de-facto Pro connection registry, extended by `connection_type` across nine toolkits | manager + CRM/F&B/EZuite/FlowHub/Chat Channels/PM call sites **[R]** | D11: provider contract + `database` connection type |
| Per-assistant connection enablement already exists | `WP_MCP_AI_Pro_Metabox_Remote_Connections::META_KEY` **[R]** | D12: scopes narrow that list |
| No SQL parser is a dependency anywhere | root and Pro `composer.json` **[R]** | D3 + §2.2: the DB role is the control; admission is a courtesy |

---

## 3. Deliberately not adopted

| Option | Why not |
|---|---|
| Ship a hosted SQL MCP server (Toolbox, Draxlr, `mcp-postgres`) as the integration | Moves scope, audit and credentials outside the product; the gateway could then no longer reason about per-assistant scope. Borrow the pattern, not the process. |
| Depend on a SQL parser library as the primary control | No parser exists in the dependency tree (F17); a parser bug still reaches the database. Adoption would also be a new dependency for a control that cannot be the control. |
| `pg_read_all_data` for the agent role | Precisely the unrestricted scope this proposal avoids. Explicit per-relation/column grants only. |
| Tenant id as a tool parameter | Omittable, guessable, prompt-injectable. Excluded by design. |
| Prompt-level "only read" instructions | Not a control. Layer 5 is explicitly advisory. |
| A WordPress-side reimplementation for `lib/`-less builds | Two kernels is how the flag-spelling and cast-type divergences in the repo happened. `unavailable` instead. |
| `OFFSET` pagination | Linear scan; a latency cliff and a cheap denial-of-service. Keyset only. |

---

## 4. What this research could **not** settle

Recorded so nobody mistakes a decision for a measurement.

1. **The primary Datadog and Hildebrand reports were not retrieved.** Both are described here from a detailed secondary summary that quotes their findings. The *design consequence* does not depend on the exact count of affected servers — a single reproducible `COMMIT` bypass is enough — but if either primary report becomes available, re-read it before citing specific server names.
2. **No benchmark of this kernel's overhead.** The proposal's "< 15 ms p95 shaping overhead for a 500-row result" is a target, not a measurement. P2 measures it (plan D8 note).
3. **No test of MariaDB `max_statement_time` behaviour inside a read-only transaction** on the fleet's pinned 10.11. The unit semantics are documented; the interaction is assumed and will be confirmed by the P2 integration test.
4. **`plan_cache_mode` guidance is inferred from measurement write-ups**, not from a tenant-skewed benchmark run on this fleet. Treated as documentation, never as a default.
5. **Cloudways-managed Postgres `pgbouncer` mode is unverified.** The design is correct under both session and transaction pooling because every tenant value is transaction-local; confirming the actual pool mode is an operational task, not a code one.
6. **No legal/compliance review.** pgAudit and retention guidance is engineering-level; SOC 2 / HIPAA applicability depends on the deployment and is out of scope here.

---

## 5. Sources

Primary documentation
- PostgreSQL 18 — Predefined Roles: https://www.postgresql.org/docs/18/predefined-roles.html
- PostgreSQL — Error Codes (Appendix A): https://www.postgresql.org/docs/current/errcodes-appendix.html
- PostgreSQL — Row Security Policies; Client Connection Defaults (`statement_timeout`, `search_path`)
- MariaDB — Aborting Statements that Exceed a Certain Time to Execute: https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/query-optimizations/aborting-statements
- MySQL Server Blog — Server-side SELECT statement timeouts: https://dev.mysql.com/blog-archive/server-side-select-statement-timeouts/
- pgAudit — README: https://github.com/pgaudit/pgaudit
- MCP authorization specification revisions (2025-11-25, 2026-07-28); RFC 8707; RFC 9728
- Phrack #71 — "World of SELECT-only PostgreSQL Injections": https://phrack.org/issues/71/8_md

Secondary analysis
- Draxlr — "MCP Servers for SQL: Read-Only, Tenant-Aware Access" (27 Aug 2026): https://www.draxlr.com/blogs/mcp-server-read-only-tenant-aware-sql-access/
- Microsoft Community Hub — "Building MCP servers for your database: flexibility, safety, and tradeoffs"
- Google — MCP Toolbox for Databases: https://github.com/googleapis/mcp-toolbox
- Bytebase — "Top 3 Open Source Multi-Database MCP Servers in 2026"
- `datomatic/laravel-database-mcp`: https://packagist.org/packages/datomatic/laravel-database-mcp
- Now-Next — PostgreSQL RLS measurement series (tenant context, PgBouncer, performance): https://now-next.nl/en/insights/
- Cybertec — "Abusing SECURITY DEFINER functions in PostgreSQL"
- MariaDB KB — Stacked Queries (PDO): https://websec.ca/sql-injection-knowledge-base/mariadb/stacked-queries/
- Stack Overflow — "Can PHP's PDO be limited to a single query?" (`MYSQL_ATTR_MULTI_STATEMENTS` semantics)
- Andrew Baker — "How to Prevent PostgreSQL Query Stampedes" (2026)
