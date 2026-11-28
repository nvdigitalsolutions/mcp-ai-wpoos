# 167 — Database toolkit: scoped, auditable SQL access for assistants

**Status:** **Decided** (2026-11-27) — fifteen decisions (§167.8) accepted; D14 superseded by **D15** after the distribution review. **Partially implemented:** the host-free kernel (31 classes + the port) is built, tested and now relocated to `addons/pro/lib/database/`; the PDO adapters, tools and P1–P7 are not — see the implementation plan §0.2 for the exact state.
**Companions:** [`167-database-toolkit-scoped-access-research.md`](./167-database-toolkit-scoped-access-research.md) (sources, findings, what was rejected, what research could not settle) · [`167-database-toolkit-scoped-access-implementation-plan.md`](./167-database-toolkit-scoped-access-implementation-plan.md) (file map, engine contracts, phases, gates)
**Surfaces:** `addons/pro/lib/database/` (new host-free kernel + port, D15), `includes/database/` (new PDO adapter layer: probe, catalogue, connection factory, engine adapters, executor, audit), `includes/connections/` (new provider contract + resolver), `addons/pro/includes/tools/database/` (new Pro toolkit — all 13 tools), `addons/pro/includes/toolkits/manifests/database.json` (new console family), `addons/pro/includes/admin/class-wp-mcp-ai-database-settings-page.php` (new), `lib/core/src/Tool/Database/` (Laravel tools for parity), `lib/laravel-adapter/src/Adapter/` (Eloquent/PDO adapter), `lib/core/resources/tool-readiness.php` (manifest rows), `docker/laravel/app-skeleton/config/oos.php` (`oos.database.*`)
**Related:** [007](./007-multi-tenant-database-isolation.md) (tenant isolation: pool/bridge/silo, fail-closed, RLS), [023](./023-database-connection-pooling-stance.md) (no pooler in the request lifecycle; PDO persistence stance), [041](./041-mcp-apps-remote-sites-connection-type.md) (the `connection_type` extension pattern; the Pro-dependency cost of reference mode), [093](./093-fnb-multi-connection-source-routing-proposal.md) (never let the model resolve infrastructure; deterministic source catalogue), [148](./148-commerce-contract-postgres-backend-proposal.md) (Postgres as the documented end-state backend), [162](./162-file-storage-and-visibility.md) (exports land in the file registry), [164](./164-laravel-production-toolkit-parity-proposal.md) (long work is a job; capability registry; manifest port), [149](./149-datasets-readiness-worksheet.md) (readiness vocabulary — `unverified` is not served), [090-M](./090-m-tool-registration-and-readiness.md) (tool readiness manifest), [016](./016-security-architecture-hardening-code-review-2026-08.md) (security posture signals)

---

## 167.1 Executive summary

The ask: **give an assistant scoped access to a PostgreSQL database (and the other engines the fleet runs), from the Base+Pro plugin, and then from the Laravel runtime.**

The tempting implementation is one tool — `db_query( sql )` — behind a prompt that says "please only read". That implementation is known-broken in production, not hypothetically. In 2026 two published security studies dismantled exactly that design:

- A single `COMMIT` in the model's input ended the read-only transaction in the official Postgres MCP server, and everything after it ran with full privileges.
- Across 14 SQL MCP servers, read-only was bypassed in three repeatable ways: denylists that miss `SET`, multi-statement / `WITH`-chained input, and tool arguments concatenated into SQL. At least one chain reached **remote code execution** on PostgreSQL.

Both failure families share a root cause: **enforcement lived somewhere a crafted input could route around.** The only layer that fails safe is the one that has no write grants to exploit.

Proposal answer, in one line:

> **Ship a Pro `database` toolkit whose default surface is a *structured* query grammar over an operator-authored connection catalogue; make the database role — not the prompt, not the parser — the thing that cannot write; resolve tenant and table scope server-side from the authenticated session; and put the safety kernel in `lib/core` so the Laravel runtime enforces byte-identical rules.**

Four consequences worth stating up front, because they shape the whole build:

1. **The toolkit is a governed data-access product, not a SQL console.** Most calls never contain SQL. Raw SQL is a separate, opt-in grant tier that a single administrator can withhold entirely.
2. **The WordPress site database is not a legal target.** A connection whose host+database resolves to `DB_NAME` on `DB_HOST` is refused unless an administrator sets an explicit, warning-bearing override. An assistant that can `SELECT` from `wp_users` is a privilege-escalation primitive, and this toolkit should not be one.
3. **Scope is data, not a parameter.** The model never chooses the tenant, the schema, or the row filter. It chooses a *connection id* from a catalogue it can see, and the kernel applies the rest.
4. **Connections are integrated, scopes are new.** A database connection is a `connection_type` in the store operators already use (Remote Sites), with a Base-only fallback for standalone and Laravel; the *grant* — tables, columns, tiers, row filter — is the object this proposal actually introduces (§167.6.13).

---

## 167.2 Problem statement

### 167.2.1 What exists today

The repository has no general-purpose database tool. What it has is adjacent, and the adjacency is the problem:

| Existing capability | What it does | Why it is not this |
|---|---|---|
| `WP_MCP_AI_Pro_Remote_Site_Manager` | 25+ operator connections (WordPress, MCP servers, Gmail, Drive, Telegram, QuickBooks, Upwork, …) with encrypted secrets, test, CLI, REST and export/import | HTTP-endpoint shaped: `url` is required and `AUTH_TYPES` is HTTP auth. There is no non-HTTP record, no grant model and no query surface. This proposal adds a `database` type and a scope layer **on top of** it (§167.6.2) rather than replacing it |
| `WP_MCP_AI_Tool_Cloudways_App_MySQL_Analytics` | Reads Cloudways' *analytics API* for slow-query / running-query panels | It queries a vendor API about MySQL, never the database |
| `NV_oOS_Graphify_Remote_Generic_SQL` | Read-only PDO driver (MySQL/MariaDB/Postgres/SQLite) that maps a configurable `SELECT` onto graph fields | Purpose-built for Graphify's node/edge import; not an assistant tool, no scope model, no per-call audit |
| `WP_MCP_AI_Vector_Store_Adapter` (`pgvector` backend) | Stub that will one day store embeddings in Postgres | Store-specific; no ad-hoc reads |
| `ProductCatalog` (Laravel) | Hand-written portable SQL for the commerce contract | One domain, one schema, no assistant surface |
| `WP_MCP_AI_Pro_Google_Drive_Client` + toolkit readers | Read tabular files from Drive/Sheets/CSV | File-shaped data, not a live relational database |

So an operator who wants "let the analyst assistant read our warehouse" has no supported path. In practice that gap gets filled the worst possible way: a custom snippet, a shared `postgres` superuser URI pasted into a prompt or an option, and no audit trail.

### 167.2.2 What the fleet actually runs

Verified from the deployment surface, 2026-11-27:

| Target | Where it appears | Notes |
|---|---|---|
| **PostgreSQL** | Cloudways Velocity managed Postgres; `148` end-state (`Laravel → PostgreSQL · pgvector`); `pgvector` backend stub | Primary target for this proposal |
| **MariaDB 10.11 / MySQL 8** | Cloudways PHP servers (`ProductCatalog` header records MariaDB 10.11 verified 2026-11-27); WordPress itself | Secondary target; no native RLS, so app-layer scope matters more |
| **SQLite** | Laravel test suite (`RefreshDatabase`, in-memory) | Required for tests, not for production assistants |

### 167.2.3 Why "just add `run_sql`" is not acceptable

Three independent reasons, each sufficient:

1. **It has been broken in public.** §167.4.1 documents the 2026 bypass research. Shipping the same shape would be knowingly shipping a known-vulnerable pattern.
2. **It cannot express scope.** A raw-SQL tool has exactly one scope: whatever the credential can reach. Every attempt to narrow it afterwards is a string-matching exercise that the research above says loses.
3. **It is unusable by a model.** A model does not know the schema, will hallucinate columns, and will burn turns on syntax errors. The MCP Toolbox pattern exists precisely because "the model fills in the blanks of a query the operator wrote" outperforms "the model writes SQL" on both correctness and safety.

### 167.2.4 What "scoped access" has to mean, concretely

A scope in this proposal is a named object that answers all of these, and is enforced server-side:

```text
scope = {
  connection       : which endpoint (host/port/db/engine) — credentials by handle, never inline
  schemas          : allowlist of schemas
  tables           : allowlist of relations (or "*" within the schema allowlist)
  columns          : per-table projection; sensitive columns masked unless read_unmasked
  row_filter       : a mandatory predicate ANDed into every query (the RLS substitute)
  tiers            : [ metadata, read, read_unmasked, raw_sql, explain_analyze,
                       export, propose_write, apply_write, admin ]
  limits           : { max_rows, max_bytes, timeout_ms, max_affected_rows }
  principals       : which assistants / users / API clients may use it
}
```

Anything not in the object is denied. There is no "default allow".

---

## 167.3 Findings — repository facts this proposal is scoped against

| # | Fact | Evidence |
|---|---|---|
| F1 | There is **no general database tool** in Base or Pro; the closest is a Cloudways *analytics API* reader | `addons/pro/includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-mysql-analytics.php` |
| F2 | A **read-only PDO driver already exists** and accepts `mysql`, `pgsql`, `sqlite` DSNs, opens with `ATTR_EMULATE_PREPARES=false` and a hard timeout | `addons/graphify/includes/remote/drivers/class-nvoos-graphify-remote-generic-sql.php` |
| F3 | A **credential store already exists**: the Password Vault (`mcp_vault_item` CPT + admin manager + Bitwarden import/export) | `addons/pro/includes/vault/class-wp-mcp-ai-vault-item-cpt.php`, `addons/pro/includes/admin/class-wp-mcp-ai-password-vault-admin.php` |
| F4 | Capability flags are **closed and normalised**; `read-only`, `state-changing`, `write`, `data-destruction`, `irreversible`, `financial-impact`, `access-control-change` are the gated vocabulary | `lib/core/src/Domain/Service/Safety/CapabilityFlagVocabulary.php` |
| F5 | A **read-only profile guard** already denies at the last moment, before the tool body | `lib/core/src/Application/Tool/ReadOnlyToolGuard.php` |
| F6 | Tool readiness is a **closed vocabulary**; `unverified` is *not served* | `lib/core/resources/tool-readiness.php` header |
| F7 | MCP annotations (`readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`) are **already recorded per tool** | same file, e.g. `add_watermark_to_pdf` |
| F8 | Tenant resolution is a **port**, not a global: `TenantResolverInterface::current()` | `lib/core/src/Domain/Contract/TenantResolverInterface.php` |
| F9 | Multi-tenant isolation is already researched: pool/bridge/silo, defence in depth, **fail-closed**, session-scoped context | `docs/project/proposals/007-multi-tenant-database-isolation.md` §2 |
| F10 | The house stance is **no connection pooling** in the request lifecycle; PDO persistence is a deliberate decision, not a default | `docs/project/proposals/023-database-connection-pooling-stance.md` §1, §3 |
| F11 | "**Never let the model resolve infrastructure**" is already the house rule for multi-source routing; connection ids live in a deterministic catalogue | `docs/project/proposals/093-…` §"Industry research" |
| F12 | PII redaction already exists twice (WP and core) and already detects **connection strings** | `includes/harness/class-wp-mcp-ai-pii-filter.php`, `lib/core/src/Domain/Service/Harness/PiiFilter.php` |
| F13 | Laravel's `config/database.php` defines `sqlite`, `mysql`, `mariadb`, `pgsql` connections; default is `sqlite` | `docker/laravel/app-skeleton/config/database.php` |
| F14 | Runtime gates live under `oos.*` (`read_only`, `necessity_gate`, `chat_profiles`) and default **on** where measured safe | `docker/laravel/app-skeleton/config/oos.php` |
| F15 | A Pro toolkit requires **eleven** registration surfaces; missing the module-registry or admin-page one makes tools silently invisible | `.agents/skills/mcp-ai-wpoos-toolkit-creation/SKILL.md` §4 |
| F16 | Async is the house pattern for long work: operation id + status contract | `164` §164.4.1(3), media-worker jobs |
| F17 | There is **no SQL parser** in any `composer.json` (root or Pro) | root `composer.json` `require` block; Pro has no parser dependency |
| F18 | Postgres is a permitted DSN scheme in the only existing remote driver, so `pdo_pgsql` availability is already assumed | F2 |
| F19 | **Remote Sites is the de-facto Pro connection registry**: one option, AES-256-CBC secret fields with `_*_encrypted` markers, per-type validation, `test_connection()` dispatch, export/import provider, WP-CLI command, REST controller, SPA page, Filament page | `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php` (`OPTION_NAME`, `AUTH_TYPES`, `encrypt_value`), `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`, `addons/pro/includes/rest/class-wp-mcp-ai-pro-remote-sites-rest-controller.php` |
| F20 | `connection_type` is **already the platform's extension point**: toolkits read the store and filter by type — CRM (`gmail`, `google_workspace`, `email_imap`, `upwork`, `linkedin`), F&B (`drive`), EZuite (`ezuite_erp`), FlowHub (`flowhub_pos`), Chat Channels (`telegram`), PM (`upwork`, `gmail`) | CRM command centre `get_crm_source_count()`, `class-wp-mcp-ai-fnb-settings-page.php::drive_connections()`, PM command centre `ajax_ingest_work_items()` |
| F21 | **Per-assistant connection enablement already exists** as post meta, surfaced by a dedicated metabox | `WP_MCP_AI_Pro_Metabox_Remote_Connections::META_KEY = '_wp_mcp_ai_pro_remote_connections'` |
| F22 | The connection record is **URL-centric**: `url` is required and `esc_url_raw( trailingslashit( … ) )` is applied to every type except `quickbooks_mcp`; `AUTH_TYPES` is HTTP-shaped | same manager, `save_connection()`; `AUTH_TYPES` const |
| F23 | Remote Sites is **Pro-only and absent from Laravel**; 041 §3 records "registry gains a Pro-manager dependency" as the explicit cost of reference mode | `docs/project/proposals/041-mcp-apps-remote-sites-connection-type.md` §3 Option B |

---

## 167.4 Standards applied

Researched 2026-11-27. Each standard drives a specific decision below; the decision is named so it can be traced.

### 167.4.1 Read-only must be a role, not a transaction wrapper

The 2026 evidence is unambiguous:

- **Datadog Security Labs (April 2026)** showed that the official `@modelcontextprotocol/server-postgres` wrapped queries in a read-only transaction but never rejected multi-statement input; a `COMMIT` inside the model's input ended the transaction early and the remainder ran at full privilege. Anthropic archived the package as production-unready in May 2025, yet it was still pulling ~21,000 weekly npm downloads when the study landed.
- **Maximilian Hildebrand (July 2026)** found read-only bypasses in **14 SQL MCP servers**, clustering into three patterns: insufficient denylists (missing `SET`, which can change a password), insufficient validation (multi-statement, `WITH`-chained follow-ups), and unprepared statements (tool arguments concatenated into SQL). At least one chain reached **RCE on PostgreSQL**.

The consistent conclusion across Microsoft's Azure engineering guidance, Google's MCP Toolbox, and independent write-ups is the same ordering: **a `SELECT`-only role first, a parser second, timeouts third.** A parser bug still reaches a database that refuses the write.

→ **Decision:** grant-based enforcement is layer 1 of five (§167.6.5). Every connection used by this toolkit is required to be a *dedicated* role with `SELECT` only and `default_transaction_read_only = on`. The toolkit refuses to register a connection whose role can write unless the scope explicitly holds `propose_write`/`apply_write` — and even then the role is the ceiling.

### 167.4.2 Tenant scope must be injected server-side, transaction-locally

PostgreSQL RLS guidance is consistent and specific:

- `FORCE ROW LEVEL SECURITY` **plus** a non-owner, non-`BYPASSRLS` role, or the table owner bypasses policies entirely.
- Tenant context must be set with **`SET LOCAL`** or **`set_config('app.current_tenant', $1, true)`** inside an **explicit transaction**. A plain `SET` persists for the session, and under PgBouncer's default transaction pooling that session is handed to another tenant's request.
- Wrap reads in `NULLIF(current_setting('app.tenant_id', true), '')` so a reset connection returns **zero rows** rather than erroring — fail closed, not fail loud-and-leaky.

→ **Decisions:** (a) every query runs inside a transaction that begins with `SET LOCAL`; (b) a **row filter** is ANDed into every statement server-side (§167.6.4) so scope also holds on MariaDB, which has no RLS; (c) if the filter cannot be applied to a requested relation, the query is **refused**, never silently widened.

**One measured caveat, recorded so it is not a surprise.** Under RLS, a server-side prepared statement can retain the plan — and therefore the statistics — of the tenant it was first planned for, because the policy's `current_setting()` is folded in at plan time. Correctness is unaffected: the predicate still applies at execution. It is a performance effect on tenant-skewed tables. The mitigations are already in the design — no persistent connections (D8) and a fresh `prepare()` per call — and the bootstrap template documents `plan_cache_mode = force_generic_plan` as an operator option for large RLS tables, never as a default. Separately, non-`LEAKPROOF` functions (`lower()`, `LIKE`) cannot be pushed below the policy filter, so the bootstrap guidance asks for a composite `(tenant_id, …)` index and the search tool escapes wildcards rather than wrapping columns in functions.

### 167.4.3 Curated, parameterised queries beat model-authored SQL

Google's MCP Toolbox for Databases is the reference implementation: tools are declared in `tools.yaml`, each mapping to a **parameterised SQL statement**; the model fills in the blanks and "performs no SQL"; only a prebuilt tool allows arbitrary SQL. The Bytebase survey of multi-database MCP servers makes the same point bluntly: "a raw server lets the agent compose any statement; here you write and parameterise the queries, and the agent only fills in the blanks."

`datomatic/laravel-database-mcp` (2026) takes the strictest reading: a read-only Laravel DB MCP server exposing two tools with **structured parameters — "never raw SQL"**.

→ **Decision:** the default surface is a structured grammar (`db_select`, `db_aggregate`, `db_search`) over an introspected catalogue, not a SQL string. Raw SQL exists but is a separate tier (`raw_sql`), off by default, single-statement, read-only-transaction, role-capped.

### 167.4.4 Least privilege for tool chains is about *who issued the token*

The MCP authorization track settled this: servers are **OAuth Resource Servers, not Authorization Servers** (June 2025 revision); the 2025-11-25 revision carries per-tool scopes; the 2026-07-28 revision requires **Protected Resource Metadata (RFC 9728)**, **PKCE S256**, and the **resource parameter (RFC 8707)** on every authorization *and* token request, with the rule that a server **MUST NOT accept tokens not expressly issued for it**. Independent analyses add the practical failure mode: agents inherit their orchestrator's full scope by default, and tool chaining then composes privileges nobody granted.

→ **Decisions:** (a) each scope can be bound to a **principal set** (assistant id, user id, API client) so a scope is not ambient; (b) the toolkit's MCP surface must declare per-tool scopes so a host can auto-allow `db_select` and still prompt for `apply_write`; (c) `apply_write` never inherits from `propose_write` — they are separate tiers.

### 167.4.5 Timeouts and row caps are part of the contract

The text-to-SQL production guidance converges on `statement_timeout` + `idle_in_transaction_session_timeout` set **on the role**, plus a server-injected row limit (`FETCH FIRST n ROWS ONLY` / `LIMIT n`). Analytics against OLTP also needs a "query stampede" posture: thresholds tightened gradually, reviewed as data volume grows.

→ **Decision:** limits are properties of the scope and are applied by the kernel; the role also carries server-side defaults so a kernel bug is contained (§167.6.5 layer 1 and layer 4).

### 167.4.6 `search_path` is a security boundary

`SECURITY DEFINER` functions run with the owner's privileges but the caller's path. The documented repair is a function-level `SET search_path` with `pg_temp` **last** — omitting `pg_temp` puts it implicitly first, which is the CVE-2007-2138 class. The review heuristic in the PostgreSQL community is blunt: "any `SECURITY DEFINER` function without an explicit `search_path` should be treated as a finding."

→ **Decision:** the toolkit ships **no** `SECURITY DEFINER` objects in its bootstrap SQL. Where a view or function is operator-authored for a scope, the bootstrap template pins `search_path` and §167.6.12 states the rule. Agent sessions additionally run with a scope-pinned `SET LOCAL search_path`.

### 167.4.7 Audit exists at two levels, and they are not substitutes

pgAudit produces a database-level audit trail that captures direct `psql` sessions, ETL jobs and DBA queries — the things an application log cannot see — and is the recognised route to SOC 2 / HIPAA / PCI-DSS evidence. The sane baseline is low-volume/high-value (`pgaudit.log = 'ddl, role'`) scoped to the application role, plus `read` on the agent role when read auditing is required; `log_parameter` captures actual values and should be a deliberate choice, because it also captures the data.

→ **Decisions:** (a) the toolkit writes an application audit record for **every** call regardless of outcome (§167.6.8); (b) the bootstrap SQL emits the recommended pgAudit settings as commented, copy-paste lines with the trade-off stated; (c) the toolkit never claims pgAudit replaces the application trail, or vice versa.

### 167.4.8 Predefined roles: useful, and too broad for an agent

PostgreSQL 14+ ships `pg_read_all_data` / `pg_write_all_data`; `pg_monitor`, `pg_read_all_settings`, `pg_read_all_stats` are the monitoring trio. They are the right tool for a `pg_dump`-capable backup role and the **wrong** tool for an agent: `pg_read_all_data` is exactly the "read everything" scope this proposal exists to avoid.

→ **Decision:** the bootstrap template uses **explicit per-relation grants** and mentions `pg_read_all_data` only as a labelled anti-pattern. Monitoring is a separate role, and `db_test_connection` reports which one it is.

### 167.4.9 Connection economics in a PHP request lifecycle

House stance (F10) is no pooler and no persistent-connection default. External analytics also wants a read replica where one exists, so an assistant's aggregate does not contend with OLTP writes.

→ **Decisions:** one lazily-opened PDO handle per connection per request, closed at shutdown; `ATTR_PERSISTENT` off; scope-level `read_replica: true` selects the replica DSN when configured; per-connection concurrency capped by the existing concurrency guard.

---

## 167.5 Threat model

Each row is a class that has produced a real vulnerability somewhere, and the layer that answers it here.

| # | Attack class | Example | Answered by |
|---|---|---|---|
| T1 | **Unbounded read** | `SELECT * FROM orders` on 40 M rows | Scope `max_rows` + injected `LIMIT` + `statement_timeout` |
| T2 | **Cross-tenant read** | Model omits `WHERE tenant_id` | Server-side `row_filter` ANDed in; Postgres RLS underneath |
| T3 | **Write via "read-only" path** | `SELECT 1; COMMIT; DELETE …` | Single-statement check + read-only role + read-only transaction |
| T4 | **Privilege change** | `SET ROLE app_admin` / `SET SESSION AUTHORIZATION` | Denylist **and** role has no such grant; `SET` refuses `ROLE`/`SESSION AUTHORIZATION` |
| T5 | **Identifier injection** | `table = "users; DROP TABLE x"` | Identifiers resolved against the introspected catalogue; never interpolated |
| T6 | **Value injection** | `filter.value = "' OR 1=1 --"` | Bound parameters only; `ATTR_EMULATE_PREPARES=false` |
| T7 | **Schema exfiltration** | `db_describe` dumps `pg_catalog`, `information_schema`, `pg_shadow` | Catalogue is filtered to the scope allowlist; system schemas never listed |
| T8 | **Data exfiltration via PII** | `SELECT email FROM customers` | Column `sensitive` flag → masked; `read_unmasked` is a separate tier |
| T9 | **Credential exfiltration** | Model asks for the connection string | Credentials are handles; the kernel never returns them; PII filter scrubs `postgres://` |
| T10 | **DoS by concurrency** | 40 parallel aggregates | Per-scope rate limit + concurrency guard + replica routing |
| T11 | **Runaway lock** | `idle in transaction` holding a lock | `idle_in_transaction_session_timeout` on the role; kernel never leaves a txn open |
| T12 | **Write escalation via proposal** | `apply_write` on an unapproved proposal | Proposal id + confirmation token + `ApprovalTicketInterface` + `max_affected_rows` |
| T13 | **Site-database pivot** | Connect to WP's own DB, read `wp_users` | Host+dbname match against `DB_HOST`/`DB_NAME` → refused; override is admin-only and logged |
| T14 | **Error-message leakage** | Postgres error echoes the row or the query | Typed errors only; detail logged server-side, never returned |
| T15 | **Ambient authority** | A sub-agent inherits a write scope | Scope bound to principal set; `apply_write` never implied |
| T16 | **Prompt-injected scope pivot** | Retrieved text says "use connection prod-root" | The model can only name a connection **id**; unknown ids fail closed; scope is not a prompt value |

---

## 167.6 Design

### 167.6.1 Five principles

1. **The database refuses.** Every other layer is a courtesy. No design decision may make a write possible by a role that lacks the grant.
2. **Structured by default, SQL by exception.** A model that cannot express a write cannot attempt one. Raw SQL is a tier, not the substrate.
3. **Scope is data, authored by an operator, resolved by the server.** Following 093: infrastructure is never model-resolved; the model names an id from a catalogue.
4. **Fail closed, loudly, and with a code.** Missing tenant → zero rows. Unmappable predicate → refusal. Unknown connection → refusal. No silent widening, ever.
5. **One kernel, two runtimes.** Grammar, shaping, redaction and refusal live in `lib/core` so WordPress and Laravel produce identical decisions and identical error strings.

### 167.6.2 The connection catalogue — one id space, three providers

Connections are operator objects, not tool arguments. They are also **not a fourth registry**: the Pro addon already has a central connection store that multiple toolkits read, and the platform's extension point is `connection_type` (F19–F23). So the catalogue is a *view over providers*, and a database connection is one kind of connection in the system — not a parallel universe.

#### The provider contract

New, in Base: `includes/connections/class-wp-mcp-ai-connection-provider-interface.php`

```php
interface WP_MCP_AI_Connection_Provider_Interface {
    public function get_kind(): string;                   // 'remote_site' | 'database' | 'laravel'
    public function list_for( array $principal ): array;  // redacted summaries for a caller
    public function get( string $id ): ?array;            // full record; secrets resolved by the caller
    public function probe( string $id ): array;           // health snapshot
}
```

`WP_MCP_AI_Connection_Resolver` merges providers, namespaces ids by kind (`rs:` / `db:` / `app:`) so collisions are impossible, and returns `db_unknown_connection` for anything no provider claims.

| Provider | Backing store | Ships in | Role |
|---|---|---|---|
| `remote_site` | `wp_mcp_ai_pro_remote_sites` (F19) | Pro | Adapter over `WP_MCP_AI_Pro_Remote_Site_Manager`. Hosts the `database` connection type. |
| `database` | `wp_mcp_ai_database_settings['connections']` | Base | Native records — the Base-only, standalone and no-Pro path |
| `laravel` | `oos.database.connections` + `config/database.php` | Laravel | References first-party connections by name; externals declared in `oos.database` |

**The kernel resolves only through the interface.** It never names `WP_MCP_AI_Pro_Remote_Site_Manager`. That is what keeps Base, standalone and Laravel working when Remote Sites is absent — the same dependency cost 041 §3 recorded against Option B ("registry gains a Pro-manager dependency").

#### The `database` connection type in Remote Sites

Because `connection_type` is already the platform's extension point, a database connection authored in Remote Sites is a first-class record there:

```json
{
  "id": "warehouse",
  "name": "Analytics warehouse",
  "connection_type": "database",
  "engine": "pgsql",
  "host": "db.internal",
  "port": 5432,
  "database": "analytics",
  "role": "nvoos_agent_ro",
  "sslmode": "require",
  "password": "v2.<aes-256-cbc ciphertext>",
  "_password_encrypted": true,
  "enabled": true
}
```

The payoff is the whole Remote Sites substrate, inherited rather than rebuilt: AES-256-CBC encryption at rest, the Connections admin screen, `test_connection()` dispatch, the export/import provider, the WP-CLI command, the REST controller, the SPA page and the Filament page. Two adjustments make it work:

- `url` is **not** required for this type. `validate_connection_data()` already branches per type (the `quickbooks_mcp` branch is the precedent); the `database` branch validates `engine`/`host`/`port`/`database` and a credential instead of a URL.
- `save_connection()`'s `esc_url_raw( trailingslashit( $url ) )` must be skipped for `database`, or a DSN is mangled. The `quickbooks_mcp` verbatim-URL branch is the same shape of fix.

#### The native record (Base, standalone)

When Remote Sites is absent the same fields live in `wp_mcp_ai_database_settings['connections']`, with the secret encrypted by the same scheme. The shape is identical apart from the secret field name each store's encryptor expects (`credential` vs `password` + `_password_encrypted`), so a connection exports from one provider and imports into the other.

```php
connection = array(
    'id'            => 'warehouse',            // [a-z0-9-], stable, the only thing the model sees
    'label'         => 'Analytics warehouse',
    'engine'        => 'pgsql',                // pgsql | mysql | mariadb | sqlite
    'host'          => 'db.internal',
    'port'          => 5432,
    'database'      => 'analytics',
    'sslmode'       => 'require',              // require | verify-full | disable (sqlite ignores)
    'credential'    => 'secret:…',             // WP_MCP_AI_Encryption ciphertext, or a vault: handle (F3)
    'role'          => 'nvoos_agent_ro',       // asserted at probe time
    'read_replica'  => null,                   // optional secondary DSN handle
    'allow_site_db' => false,                  // T13 override; admin + warning + audit
    'probe'         => array( 'checked_at' => '…', 'server_version' => '18.1',
                              'transaction_read_only' => true, 'can_write' => false ),
);
```

Rules:

- **Secrets never leave their store in clear.** A Remote Sites record uses `encrypt_value()`/`decrypt_value()`; a native record uses the same scheme or a Vault handle. One store per record, resolved at connect time, dropped after use, never returned by a tool, never logged — and a `postgres://` / `mysql://` string in *output* is already caught by F12.
- **Per-assistant binding is already built.** `_wp_mcp_ai_pro_remote_connections` (F21) is the existing "which assistant may use which connection" surface. The scope's `principals` list **defaults to that meta** and narrows it; it does not introduce a second binding UI.
- **`probe` is mandatory before a connection is servable.** `db_test_connection` records `server_version`, the effective `transaction_read_only`, and `can_write` — determined by **privilege introspection** (`pg_roles.rolsuper`/`rolbypassrls`, `has_schema_privilege(…, 'CREATE')`, `has_table_privilege(…, 'INSERT'/'UPDATE'/'DELETE')`), not by attempting a write. Introspection is non-destructive and answers the real question; a `CREATE TEMP TABLE` probe proves TEMP privilege, not the absence of DML. A connection without a passing probe is `unavailable` in the readiness vocabulary (F6) and its tools are not served.
- **The site database is refused.** At registration the kernel compares `(host, port, database)` against `DB_HOST`/`DB_PORT`/`DB_NAME` (WordPress) and the app's default connection (Laravel). A match is a hard error unless `allow_site_db` is true, which requires `manage_options` + a typed confirmation and writes an audit event.
- **Engine capability is declared, not assumed.** `pgsql` supports RLS and `EXPLAIN (FORMAT JSON)`; `mysql`/`mariadb` support neither; `sqlite` supports neither and is test-only unless explicitly enabled.

### 167.6.3 The scope record

Scopes are named, versioned, and attached to connections.

```php
scope = array(
    'id'          => 'analytics-readonly',
    'label'       => 'Analytics — read only',
    'connection'  => 'warehouse',
    'schemas'     => array( 'reporting' ),                 // allowlist, never '*'
    'tables'      => array( 'reporting.orders', 'reporting.customers', 'reporting.v_kpi_daily' ),
    'columns'     => array(
        'reporting.customers' => array(
            'exclude'  => array( 'internal_notes' ),
            'sensitive'=> array( 'email', 'phone', 'tax_id' ),  // masked unless read_unmasked
        ),
    ),
    'row_filter'  => array(
        // ANDed into every statement. Multiple predicates AND together.
        array( 'column' => 'tenant_id', 'op' => 'eq', 'value_from' => 'scope_tenant' ),
    ),
    'tiers'       => array( 'metadata', 'read', 'export' ),
    'limits'      => array(
        'max_rows'          => 500,
        'max_bytes'         => 2097152,   // 2 MiB shaped payload
        'timeout_ms'        => 5000,
        'max_affected_rows' => 0,          // 0 = writes impossible
    ),
    'principals'  => array( 'assistant:42', 'user:7' ), // defaults from _wp_mcp_ai_pro_remote_connections (F21), then narrows
    'version'     => 3,                    // bump on change; audit records the version used
);
```

`row_filter` values come from two sources only: `scope_tenant` (resolved from `TenantResolverInterface`, F8) or a literal authored in the scope. **Never from a tool argument.**

### 167.6.4 Grant tiers

| Tier | Enables | Default | Notes |
|---|---|---|---|
| `metadata` | `db_list_connections`, `db_describe`, `db_profile_table`, `db_explain` | **on** | Cheapest, safest; lets the model ground itself before querying |
| `read` | `db_select`, `db_aggregate`, `db_search` | **on** | The product's core |
| `read_unmasked` | see `sensitive` columns in clear, honour `exclude` as-is | off | Requires justification text on the scope |
| `explain_analyze` | `db_explain` with `ANALYZE` | off | It **executes** the query; treat as `read` with side effects |
| `export` | `db_export` (async → file registry, 162) | off | Bypasses the payload cap by design; rate-limited separately |
| `raw_sql` | `db_query` | off | Single statement, read-only txn, role-capped |
| `propose_write` | `db_propose_write` | off | Produces a reviewable proposal; does not execute |
| `apply_write` | `db_apply_write` | off | Executes an approved proposal only |
| `admin` | connection/scope CRUD, `db_test_connection`, `db_audit_tail` | n/a | `manage_options` only; never granted to an assistant by default |

Tiers are **not transitive**: `apply_write` does not imply `propose_write`, `raw_sql` does not imply `export`, and `admin` is not assignable to an assistant without an explicit two-field confirmation.

### 167.6.5 The five-layer enforcement stack

Ordered from the layer that fails safe outward.

```
L5  Prompt / tool description      — advisory only; never counted as a control
L4  Kernel (lib/core)              — tiers, catalogue resolution, statement classifier,
                                     row_filter injection, LIMIT injection, shaping,
                                     redaction, audit
L3  Connection / transaction       — dedicated role, default_transaction_read_only,
                                     BEGIN … SET LOCAL … ROLLBACK for every read,
                                     statement_timeout, idle_in_transaction_session_timeout,
                                     lock_timeout, search_path pinned
L2  Statement admission            — single statement, no COMMIT/ROLLBACK/END,
                                     no SET ROLE / SESSION AUTHORIZATION,
                                     no DDL, no COPY … FROM PROGRAM,
                                     no pg_read_file / lo_import / dblink
L1  Database grants                — SELECT on the scope's relations only;
                                     no INSERT/UPDATE/DELETE/TRUNCATE/DDL;
                                     no membership in pg_read_all_data, no BYPASSRLS,
                                     not the table owner (or FORCE RLS)
```

**The kernel never trusts L2 for safety.** L2 exists to turn a malformed or hostile request into a clean typed refusal *before* it reaches the database, not to make an unsafe request safe. If L2 is removed entirely, L1 still prevents writes — that is the acceptance test for the design (§167.11 gate 6).

`BEGIN … ROLLBACK` around every read is deliberate, for the reason the industry write-ups give: it makes `default_transaction_read_only` explicit rather than inherited, and it makes `SET LOCAL` possible.

### 167.6.6 The tool surface

Thirteen tools: eleven assistant-facing, two admin-only (`db_test_connection`, `db_audit_tail`). Seven ship in Base, six in Pro (§167.6.9). Names are the canonical slugs; `parity` and `annotations` are the manifest fields (F7).

#### Discovery (`metadata`)

| Slug | Purpose | Key params |
|---|---|---|
| `db_list_connections` | Which connections this principal may use, with a one-line capability summary | — |
| `db_describe` | Tables/columns/types/PK/FKs/relations, **filtered to the scope** | `connection`, `table?`, `include_views?` |
| `db_profile_table` | Bounded column profile: null %, approx distinct, min/max, top-5 values | `connection`, `table`, `columns[]` |
| `db_explain` | Plan for a `db_select`-shaped request; `analyze` requires `explain_analyze` | `connection`, `request`, `analyze?` |

#### Query (`read`)

| Slug | Purpose | Key params |
|---|---|---|
| `db_select` | Structured projection with filters/order/limit/cursor | `connection`, `relation`, `columns[]?`, `filters[]?`, `order[]?`, `limit?`, `cursor?` |
| `db_aggregate` | Deterministic group-by metrics | `connection`, `relation`, `group_by[]?`, `metrics[]`, `filters[]?`, `having[]?`, `order[]?`, `limit?` |
| `db_search` | Term match across the scope's declared `searchable` columns | `connection`, `relation`, `term`, `columns[]?`, `limit?` |
| `db_query` | Raw SQL *(tier `raw_sql`)* | `connection`, `sql`, `params[]?` |

Filter operators (closed): `eq`, `ne`, `in`, `not_in`, `gt`, `gte`, `lt`, `lte`, `between`, `like`, `ilike`, `is_null`, `is_not_null`. Values are always bound. `like`/`ilike` escape `%` and `_` in the caller's value (the `ProductCatalog` LIKE-escape lesson, generalised).

Metrics (closed): `count`, `count_distinct`, `sum`, `avg`, `min`, `max`, `median`, `p90`. Percentiles use `percentile_cont` on Postgres and a window-function equivalent on MySQL; if an engine cannot express a requested metric, the tool refuses with `unsupported_metric` rather than approximating silently.

#### Write path (`propose_write` / `apply_write`)

| Slug | Purpose |
|---|---|
| `db_propose_write` | Build a proposal: the exact statement, a `SELECT count(*)` affected-row estimate under the same row filter, a bound-parameter dump, and a rationale string. Persists as a record (CPT/option on WP, table on Laravel). Does **not** execute. |
| `db_apply_write` | Execute a proposal by `proposal_id` + `confirm` token. Requires `apply_write`, an `ApprovalTicketInterface` approval where configured, and `affected_rows <= max_affected_rows`. Wraps in a transaction; refuses if the affected-row count changed since the proposal. |

The write path is deliberately **two-phase and human-gated**. It is in scope for this proposal because the alternative — operators granting the agent a writable role outside the toolkit — is worse. It is the last phase to ship and may be deferred entirely (D6).

#### Operations

| Slug | Purpose | Tier |
|---|---|---|
| `db_export` | Async CSV/JSON export → file registry; returns an operation id | `export` |
| `db_test_connection` | Probe: version, role, `transaction_read_only`, `can_write`, latency | `admin` |
| `db_audit_tail` | Recent calls for a connection/scope, newest first, bounded | `admin` |

#### Capability flags and annotations

```php
// Read tools (db_select, db_aggregate, db_search, db_describe, db_profile_table, db_explain)
'capability_flags' => array( 'pro', 'read-only', 'requires-credentials', 'network-dependent', 'idempotent' ),
'annotations'      => array( 'readOnlyHint' => true,  'destructiveHint' => false,
                             'idempotentHint' => true, 'openWorldHint' => false ),

// db_query (raw SQL) — still read-only, but not idempotent in the caching sense
'capability_flags' => array( 'pro', 'read-only', 'requires-credentials', 'network-dependent', 'non-deterministic' ),
'annotations'      => array( 'readOnlyHint' => true,  'destructiveHint' => false,
                             'idempotentHint' => false, 'openWorldHint' => false ),

// db_propose_write — writes a proposal record, not the target data
'capability_flags' => array( 'pro', 'local-only', 'state-changing', 'write' ),
'annotations'      => array( 'readOnlyHint' => false, 'destructiveHint' => false,
                             'idempotentHint' => false, 'openWorldHint' => false ),

// db_apply_write — the only destructive tool in the family
'capability_flags' => array( 'pro', 'state-changing', 'write', 'irreversible',
                             'financial-impact' /* when the scope declares financial tables */ ),
'annotations'      => array( 'readOnlyHint' => false, 'destructiveHint' => true,
                             'idempotentHint' => false, 'openWorldHint' => false ),
```

Note `financial-impact` is conditional per scope: a scope whose table allowlist includes a table flagged `financial` must declare it, which raises the necessity-gate verdict to `approval` (F5 / `oos.necessity_gate`). This reuses the existing gate rather than adding a second one.

### 167.6.7 Result shaping and token economy

An unbounded `SELECT` is a denial-of-service against the context window before it is anything else. Shaping is a first-class part of the contract:

```php
array(
    'success'   => true,
    'connection'=> 'warehouse',
    'scope'     => 'analytics-readonly',
    'columns'   => array( array( 'name' => 'order_id', 'type' => 'bigint' ), … ),
    'rows'      => array( array( 'order_id' => 1001, … ), … ),
    'row_count' => 500,
    'truncated' => true,
    'truncated_reason' => 'max_rows',            // max_rows | max_bytes | cell_cap
    'next_cursor' => 'eyJpZCI6MTAwMX0',          // keyset cursor; null when exhausted
    'masked'    => array( 'customers.email', 'customers.phone' ),
    'elapsed_ms' => 41,
    'audit_id'  => 'aud_…',
)
```

Rules:

- **Cell cap** 16 KiB per value (configurable): a `text` column holding a 4 MiB document is truncated with an explicit marker and counted in `truncated_reason = cell_cap`.
- **Byte cap** from the scope (`max_bytes`), enforced during shaping, not after serialisation.
- **Keyset pagination**, not `OFFSET`, for `db_select` — `OFFSET 10000` is a table scan and a latency cliff.
- **Numeric fidelity**: `numeric`/`decimal` come back as strings (never floats) to avoid the precision loss the `ProductCatalog` header already documents for casts.
- **Timestamps** are returned in ISO-8601 with offset, plus the connection's declared session timezone.
- **Budgets**: shaping consults `TokenBudgetServiceInterface` / `DataBudgetTrackerInterface` where bound, so a large result degrades to a summary plus cursor rather than blowing a turn.

### 167.6.8 Audit

One append-only record per call, success or refusal, written through the existing audit logger:

```php
array(
    'audit_id'      => 'aud_01J…',
    'at'            => '2026-11-27T10:14:03Z',
    'principal'     => 'assistant:42',
    'connection'    => 'warehouse',
    'scope'         => 'analytics-readonly',
    'scope_version' => 3,
    'tool'          => 'db_select',
    'decision'      => 'allowed',              // allowed | refused
    'refusal_code'  => null,                   // or e.g. db_scope_table_not_allowed
    'request_hash'  => 'sha256:…',             // normalised request, not the raw prompt
    'sql_hash'      => 'sha256:…',             // fingerprint of the executed statement
    'sql_template'  => 'SELECT … FROM reporting.orders WHERE tenant_id = $1 AND … LIMIT $2',
    'row_count'     => 500,
    'bytes'         => 48210,
    'elapsed_ms'    => 41,
    'truncated'     => true,
)
```

- The **SQL template** stores the statement with literals replaced by placeholders. The values are not stored by default; a scope may opt into `audit_values: true` (with a stated PII consequence).
- **Refusals are audited too**, and specifically alerted on: a spike in `T3`/`T5` refusals from one principal is the exact signal the 2026 bypass research used to find vulnerable servers.
- `db_audit_tail` is the read surface; the Admin console renders it (W4).

### 167.6.9 WordPress implementation

**New folder `includes/database/`** — the kernel, no tools:

```
includes/database/
├── README.md                                       # folder convention (docs/developer/folder-readme-convention.md)
├── class-wp-mcp-ai-db-connection.php               # value object + vault resolution
├── class-wp-mcp-ai-db-connection-registry.php      # catalogue CRUD, site-DB refusal, probe state
├── class-wp-mcp-ai-db-scope.php                    # value object + validation
├── class-wp-mcp-ai-db-scope-registry.php           # scope CRUD, versioning
├── class-wp-mcp-ai-db-catalogue.php                # introspection, cached, scope-filtered
├── class-wp-mcp-ai-db-statement.php                # builder: identifiers → quoted, values → bound
├── class-wp-mcp-ai-db-admission.php                # L2: single-statement, denylist, classifier
├── class-wp-mcp-ai-db-connection-factory.php       # PDO per engine, sslmode, timeouts, read-only txn
├── class-wp-mcp-ai-db-executor.php                 # BEGIN → SET LOCAL → execute → ROLLBACK
├── class-wp-mcp-ai-db-shaper.php                   # caps, cursors, numeric fidelity
├── class-wp-mcp-ai-db-redactor.php                 # column masks + PII filter bridge
├── class-wp-mcp-ai-db-audit-log.php                # append-only record
└── class-wp-mcp-ai-db-proposal-store.php           # two-phase write proposals
```

**Sibling folder `includes/connections/`** — the provider contract and resolver (§167.6.2), deliberately separate so other capabilities can register a provider without loading the database kernel:

```
includes/connections/
├── README.md
├── class-wp-mcp-ai-connection-provider-interface.php   # the port
├── class-wp-mcp-ai-connection-resolver.php             # merge, namespace ids, fail closed
└── class-wp-mcp-ai-native-connection-provider.php      # Base/standalone database records
```

Portable grammar, shaping rules, admission regexes and refusal codes live in **`lib/core/src/Domain/Service/Database/`** and are consumed from WordPress through small adapters, so Laravel and WordPress cannot drift. This is the same discipline `CapabilityFlagVocabulary` (F4) was created to enforce after the flag-spelling divergence.

**Pro toolkit `addons/pro/includes/tools/database/`** — the eleven registration surfaces (F15), in the standard layout:

```
addons/pro/includes/tools/database/
├── init.php                                   # settings defaults; admin page load behind the enable gate
├── README.md
├── TOOLS_LIST.txt / TOOL_INDEX.md
├── class-wp-mcp-ai-database-settings.php      # wp_mcp_ai_database_settings
├── class-wp-mcp-ai-database-tool-base.php     # guard(), scope resolution, envelope helpers
├── class-wp-mcp-ai-tool-db-*.php              # Pro-only tools (6): query, propose_write, apply_write, export, test_connection, audit_tail
├── class-wp-mcp-ai-database-presets.php       # schedule + workflow presets
└── tests/
```

Registration checklist (all eleven, from the toolkit-creation skill):

| # | Surface | File |
|---|---|---|
| 1 | Pro Module Registry (`$conditional_toolkits` + `$dir_map`) | `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php` |
| 2 | Central registry gated block | `addons/pro/mcp-ai-wpoos-pro.php` |
| 3 | Tools-section checkbox **+ `features` subtab group** + memory estimate | `includes/admin/sections/class-wp-mcp-ai-section-tools.php` |
| 4 | Settings page / side menu | `addons/pro/includes/admin/class-wp-mcp-ai-database-settings-page.php` |
| 5 | Pro settings status map | `includes/admin/class-wp-mcp-ai-pro-settings.php` |
| 6 | `wp mcp-ai toolkit` entry | `addons/pro/includes/cli/class-wp-mcp-ai-pro-cli-toolkit-command.php` |
| 7 | `wp mcp-ai status` entry | `addons/pro/includes/cli/class-wp-mcp-ai-pro-cli-status-command.php` |
| 8 | Schedule presets | `addons/pro/includes/class-wp-mcp-ai-pro-schedule-presets.php` |
| 9 | Workflow presets (**with `edges`**) | `addons/pro/includes/class-wp-mcp-ai-pro-workflow-presets.php` |
| 10 | SA Toolkit Manager flags | `addons/schedule-anything-platform/includes/class-sa-toolkit-manager.php` |
| 11 | Coverage manifests (`bin/generate-tool-coverage-manifest.sh`) | `tests/tools/`, `addons/pro/tests/tools/` |

**No Base tools — every tool is Pro (D15).** A base+pro install has no `lib/` directory at all, so a kernel there would be missing for Pro customers; the kernel therefore ships inside the add-on (`addons/pro/lib/database/`, namespace `Nvoos\Database\`) and all thirteen tools register from the Pro toolkit. The base package has none, which is correct: it also has no Pro, so no operator could author a connection for them to read.

The toolkit `init.php` requires `addons/pro/lib/database/autoload.php`, which is a four-line PSR-4 registration for `Nvoos\Database\` — it does not touch the OOS engine or its autoloader.

They register behind the `enable_database_toolkit` flag in `wp_mcp_ai_settings`, **and** only when the kernel is available (D15: the add-on is active, PHP 8.2+) **and** at least one connection probes clean. With no connections the toolkit is inert — no tool registers, no surface claims a capability it cannot deliver (the `datasets` lesson, F6). Tiers gate the surface further: `raw_sql`, `explain_analyze`, `export` and the write path are separate grants, and the console is admin-only.

**Settings shape.**

```php
'wp_mcp_ai_database_settings' => array(
    'enabled'          => false,          // fail closed
    'connections'      => array( /* connection records, credentials as vault handles */ ),
    'scopes'           => array( /* scope records */ ),
    'global_limits'    => array( 'max_rows' => 500, 'max_bytes' => 2097152, 'timeout_ms' => 5000 ),
    'allow_write_path' => false,          // D6
    'audit_values'     => false,
    'replica_routing'  => true,
)
```

**Environment prerequisite, stated plainly.** `pdo_pgsql` must be present. It is already assumed by the Graphify driver (F2, F18), but the settings page must probe `extension_loaded('pdo_pgsql')` / `pdo_mysql` and surface a typed error with the Cloudways PHP-extension path rather than a fatal on first use.

### 167.6.10 Laravel implementation

Hexagonal, matching the existing 32 contracts / 21 adapters pattern.

| Layer | New piece |
|---|---|
| Port | `lib/core/src/Domain/Contract/DatabaseQueryInterface.php` — `describe()`, `select()`, `aggregate()`, `search()`, `explain()`, `execute()` |
| Portable services | `lib/core/src/Domain/Service/Database/` — catalogue model, statement grammar, admission classifier, shaper, refusal codes |
| Tools | `lib/core/src/Tool/Database/` — 13 tools extending `AbstractTool`, same slugs as the WordPress side |
| Adapter (production) | `lib/laravel-adapter/src/Adapter/EloquentDatabaseConnection.php` — resolves `config('database.connections.{name}')`, wraps in `DB::transaction()` with `SET TRANSACTION READ ONLY` and `SET LOCAL`, uses the query builder for identifiers and bindings |
| Adapter (external) | `lib/laravel-adapter/src/Adapter/PdoDatabaseConnection.php` — same contract over raw PDO, for DSNs that are not first-party Laravel connections |
| Config | `docker/laravel/app-skeleton/config/oos.php` → `oos.database` (connections are **references by name** into `config/database.php` where possible; externals declared in `oos.database.connections`) |
| Gates | `oos.database.write_path` (default **false**), `oos.database.raw_sql` (default **false**), `oos.read_only` remains the outer switch and already blocks anything flagged `write` |
| Manifest | rows in `lib/core/resources/tool-readiness.php` with `readiness`, `parity`, `probe`, `reason`, `evidence`, `annotations` |

**Why Eloquent *and* PDO.** A first-party Laravel connection already has credentials, options and SSL configuration that operators maintain. Reaching around it with a hand-built DSN duplicates that config and drifts. But most scoped connections will be *external* databases that the Laravel app does not otherwise use — those need their own DSN, which is exactly what the PDO adapter is for. Both implement the same port, so the tools are indifferent.

**The read-only transaction in Laravel.** `DB::select()` is not read-only. The adapter therefore issues, inside one transaction:

```sql
SET TRANSACTION READ ONLY;                     -- belt
SET LOCAL search_path = reporting, pg_catalog; -- scope-pinned path
SET LOCAL statement_timeout = 5000;
SET LOCAL idle_in_transaction_session_timeout = 10000;
SET LOCAL lock_timeout = 2000;
-- optional, when scope_tenant is set:
SELECT set_config('app.tenant_id', $1, true);
```

…then the statement, then `ROLLBACK`. On MySQL the `SET TRANSACTION READ ONLY` becomes `START TRANSACTION READ ONLY`; on SQLite it is a no-op and the role is the control.

**`ProductCatalog` is not refactored.** It is domain code with a hand-tuned contract; rewriting it onto the new port would risk the commerce contract for no safety gain. It is, however, the **first consumer to migrate** once the port is proven, because it demonstrates that the port can express real queries (D9).

### 167.6.11 Console surface

One new manifest family, `addons/pro/includes/toolkits/manifests/database.json`, rendered by the existing `WP_MCP_AI_Pro_Toolkit_Manifests` engine on both runtimes (the 090-B / 164 mechanism). Screens:

| Screen | Purpose | Read/write |
|---|---|---|
| **Connections** | CRUD connections; credential handle picker (vault); probe result badge; engine + version | write (admin) |
| **Scopes** | CRUD scopes; schema/table tree with checkboxes; column mask editor; tier toggles; `row_filter` builder; principal picker; live "what the assistant will see" preview | write (admin) |
| **Activity** | Audit tail: filter by connection/scope/principal/decision; refusal codes highlighted; latency and byte columns; CSV export | read |
| **Health** | Probe status, replica reachability, connection budget, refusal-rate sparkline, `pdo_*` extension presence | read |

The "live preview" on the Scopes screen is the single highest-value UI affordance in this proposal: it runs the *actual* kernel against the *actual* draft scope and shows the resolved statement, the masked columns, and the row count on a sample — so an operator sees the scope's real effect before an assistant ever touches it.

### 167.6.12 Database-side bootstrap (operator SQL)

The toolkit cannot enforce what the database has not granted. It therefore ships a **generator**, not a promise: the Connections screen emits copy-paste SQL for the chosen engine, pre-filled with the scope's tables and columns.

PostgreSQL template (abridged):

```sql
-- 1. A role that cannot write, cannot bypass RLS, and times itself out.
CREATE ROLE nvoos_agent_ro LOGIN PASSWORD :'password'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
ALTER ROLE nvoos_agent_ro SET default_transaction_read_only = on;
ALTER ROLE nvoos_agent_ro SET statement_timeout = '5s';
ALTER ROLE nvoos_agent_ro SET idle_in_transaction_session_timeout = '10s';
ALTER ROLE nvoos_agent_ro SET lock_timeout = '2s';
ALTER ROLE nvoos_agent_ro SET search_path = reporting, pg_catalog;

-- 2. Explicit grants only. NOT pg_read_all_data.
GRANT CONNECT ON DATABASE analytics TO nvoos_agent_ro;
GRANT USAGE ON SCHEMA reporting TO nvoos_agent_ro;
GRANT SELECT (order_id, tenant_id, total_minor, placed_at)
  ON reporting.orders TO nvoos_agent_ro;
-- repeat per relation/column in the scope

-- 3. RLS as the second layer (not the first).
ALTER TABLE reporting.orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE reporting.orders FORCE ROW LEVEL SECURITY;   -- owners bypass without FORCE
CREATE POLICY tenant_isolation ON reporting.orders
  USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

-- 4. pgAudit baseline (commented; see §167.4.7).
-- ALTER ROLE nvoos_agent_ro SET pgaudit.log = 'read, ddl, role';
-- pgaudit.log_parameter captures VALUES — a privacy decision, not a default.

-- 5. Monitoring is a different role. Never blend it with the agent role.
-- GRANT pg_monitor TO nvoos_monitor;
```

MySQL/MariaDB has no RLS: the generated SQL grants per-column `SELECT` on the scope's tables, and §167.6.4's `row_filter` is the only tenant control — which is why the kernel refuses a tenant-scoped scope on a `mysql`/`mariadb` connection whose `row_filter` targets a column the relation does not have.

**Never** `SECURITY DEFINER` in generated SQL (§167.4.6). If an operator adds a view for a scope, the generator adds the pinned-path form:

```sql
CREATE VIEW reporting.v_orders_scoped
  WITH (security_barrier = true) AS
  SELECT … FROM reporting.orders
  WHERE tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid;
```

### 167.6.13 Integration with Remote Sites and the connection toolkits

The short answer to "should this be integrated with Remote Sites / the connection toolkits?" is **yes — as a provider, not as a fork, and not as a new record shape**. Six boundaries make that concrete.

| Concern | Shared with the connection toolkits | New in this proposal |
|---|---|---|
| Identity | One id namespace via `WP_MCP_AI_Connection_Resolver` (`rs:`, `db:`, `app:`) | — |
| Storage | Remote Sites option + `encrypt_value()`/`decrypt_value()`; export/import provider | Native `wp_mcp_ai_database_settings['connections']` for Base/standalone |
| Admin | The existing **Connections** screen; a `database` field block in it | The **Scopes** screen (grants, masks, row filter, tiers) |
| Binding | `_wp_mcp_ai_pro_remote_connections` per-assistant meta (F21) | Scope-level narrowing on top of it |
| Probe | `test_connection()` dispatches to the DB probe for `database` records | `db_test_connection` (assistant-facing admin tool) |
| Audit | Remote Sites logs connection CRUD/test/discover | Per-call SQL audit (§167.6.8) |

**A scope is not a connection property.** A single connection can serve several scopes — a read-only analyst scope and a `raw_sql` scope on the same warehouse — so scopes are keyed *to* a connection id and stored separately. Remote Sites should not grow a grant model; that is this toolkit's object and its reason to exist.

**Other toolkits consume scopes, not PDO.** A toolkit that already resolves a Remote Sites connection (F20) should ask `WP_MCP_AI_Connection_Resolver` for a DB provider rather than opening its own handle. That is how `graphify`'s generic-SQL driver converges (D10), and how an analytics or CRM toolkit gains relational reads without a second credential store.

**MCP gateway exposure is a follow-up, not a phase.** A scope is the natural unit to publish to external MCP clients through `mcp.nvoos.pro`, mirroring 041's `mcp_server` handling: same scope, same tiers, same audit. To keep that door open, scope ids must be stable and audience-safe now (they can become an RFC 8707 resource identifier later). Deliberately out of scope for v1 (D13).

Three non-goals, stated so they are not smuggled in later:

1. **No record migration between providers.** Export/import covers moving a connection; the native and Remote Sites shapes are near-identical by design.
2. **No database type for the site's own database.** T13 stands regardless of which provider authored the record.
3. **No scope on an HTTP connection.** A `mcp_server` or `wordpress` connection has no schemas, tables or SQL; the scope editor refuses it with `db_connection_not_a_database` rather than offering meaningless controls.

### 167.6.14 Layering — what `lib/core` may and may not see

The portable kernel is `Nvoos\Core\Domain\Service\Database\` plus `Nvoos\Core\Tool\Database\`. Neither may reference a WordPress symbol, a WordPress core class, `$wpdb`, or perform I/O (ADR 001). Concretely: no `get_option`, no `apply_filters`, no `current_user_can`, no `WP_Error`, no `wp_remote_*`, no `file_get_contents`.

| Concern | Portable layer (`lib/core`) | Host adapter (`includes/`, `lib/laravel-adapter`) |
|---|---|---|
| Errors | `RefusalCode` constants + `describe()` | Maps the code to `WP_Error` / a typed exception |
| Credentials | A handle, opaque string | Resolves the handle, opens the connection, drops the secret |
| I/O | None | PDO (`includes/database/…-executor.php`) or `Illuminate\Database` |
| Escaping | None — the host escapes at its boundary | `esc_html()` on WordPress, Blade/`e()` on Laravel |
| Config | Passed in as descriptors | Reads `wp_mcp_ai_settings` / `config('oos.database')` |

Dependency direction is one-way: `includes/database/` and `lib/laravel-adapter/` import `Nvoos\Core\…`; **nothing under `lib/core` imports from `includes/`**. The error-code mapping is the same discipline `addons/nvoos-wifi/includes/Domain/Result.php` already documents — the domain may not return `WP_Error`, so each host maps at the boundary.

**Enforcement.** `bin/check-domain-purity.php` gates `includes/domain`, `includes/application`, `includes/services`, `includes/repositories` and the WiFi addon's three layers. `lib/core` is **not** enrolled today, so "no WordPress in core" is currently a convention rather than a build failure. This proposal adds two strict layers:

```php
// Proposal 167 — database toolkit. Portable kernel, strict from day one.
'lib/core/src/Domain/Service/Database' => 'strict',
'lib/core/src/Tool/Database'           => 'strict',
```

Scoping the gate to the new directories rather than all of `lib/core` is deliberate: the rest of the runtime has not been measured, and a gate that fails on day one for pre-existing reasons is a gate that gets demoted to advisory — which is how guarantees stop being guarantees. Widening it to the rest of `lib/core` is separate, measurable work (D14).

**Packaging, after D15.** The kernel ships **inside the Pro add-on** — `addons/pro/lib/database/`, namespace `Nvoos\Database\` — and the toolkit is available wherever that add-on is active and PHP is 8.2+.

Why not `lib/core`: verified in `bin/build-plugin-zip.sh`, there are three artefacts, and a **base+pro install** (the WordPress.org base package with this add-on unpacked into `addons/pro/`) has **no `lib/` at all** — the base build passes `--exclude '/lib'`. A kernel in `lib/core` would be unavailable to exactly the customers who bought Pro, and the Pro zip carries no `lib/` either, so the kernel has to live inside it.

What this costs and buys:

- The base package ships no toolkit, which is correct: it also has no Pro, so no operator could author a connection for the tools to read.
- Neither `enable_oos_engine` nor the presence of `lib/` affects availability. The add-on registers a four-line PSR-4 autoloader (`addons/pro/lib/database/autoload.php`) that has nothing to do with the OOS engine.
- Laravel loads the same files through a Composer path repository, so there is still exactly one kernel.

If the kernel is absent or PHP < 8.2, the toolkit reports **`unavailable`** with the reason in the readiness row and registers nothing — never a WordPress-side reimplementation. One kernel is the entire point; a second copy is how the flag-spelling and cast-type divergences documented in F4 and §167.6.7 happened.

---

## 167.7 Phases

Each phase is independently shippable and independently useful. No phase depends on the write path.

| Phase | Scope | Exit criteria |
|---|---|---|
| **P0 — Kernel + catalogue** | `addons/pro/lib/database/` host-free kernel (grammar, admission, shaper, refusals); `includes/database/` PDO adapter layer; `includes/connections/` provider contract + resolver; native connection provider; secret resolution; site-DB refusal; probe | Unit tests for grammar/admission/shaper; resolver merges two providers and refuses unknown ids; a failing test proves L1 alone blocks a write |
| **P1 — Read-only Postgres (Pro)** | Pro toolkit scaffold + the seven read tools (`db_list_connections`, `db_describe`, `db_profile_table`, `db_explain`, `db_select`, `db_aggregate`, `db_search`); the eleven registration surfaces; Remote Sites adapter + per-assistant binding read; settings page | A scoped assistant answers a real question against a real Postgres; audit record per call; `phpcs` clean; Docker PHPUnit green |
| **P2 — RLS, row filter, multi-tenant** | `SET LOCAL app.tenant_id`; `row_filter` injection with fail-closed refusal; RLS bootstrap generator; tenant resolver wiring; MySQL/MariaDB engine adapter (no RLS, so the row filter is the only tenant control) | Cross-tenant probe returns zero rows; a scope without a mappable filter refuses rather than widens; a MySQL scope refuses a tenant filter its relation cannot express |
| **P3 — Console + audit** | `database.json` manifest (Connections, Scopes, Activity, Health); `database` connection type + field block in Remote Sites; live scope preview; refusal-rate alerting | Both runtimes render the same four screens; a Remote Sites `database` record and a native record behave identically; a scope edit bumps `version` and appears in the audit tail |
| **P4 — Laravel port** | Port, services reuse, Eloquent + PDO adapters, 13 tools, manifest rows, `oos.database` config, gates | Laravel's tools answer the same questions with byte-identical error codes; `bin/audit-tool-readiness.php` accepts every row with evidence |
| **P5 — Raw SQL + explain-analyze** | `db_query`, `db_explain( ANALYZE )`, tier plumbing, MCP per-tool scopes | A fuzzed corpus of hostile statements is refused or is harmless at the role; a documented corpus of 60+ cases passes |
| **P6 — Write path (in scope — ships last, default-off)** | `db_propose_write`, `db_apply_write`, proposal store, approval ticket, `max_affected_rows`, revert where expressible | Two-phase proof: an unapproved proposal cannot execute; a count-shifted proposal refuses |
| **P7 — Exports** | `db_export` async → file registry; presets (nightly drift report, weekly KPI digest) | Export of a 1 M-row relation completes as a job, lands in the registry with a checksum |

**Sequencing note.** P2 before P3 is deliberate: the console is worthless as a safety surface if scope enforcement is still being tuned. P4 can run in parallel with P3 once P2's refusal codes are frozen, because the port only consumes the kernel.

---

## 167.8 Decision log — fifteen accepted (2026-11-27)

Every decision below is **locked**. The right-hand column is the accepted resolution, not a recommendation. Changing one later requires a dated amendment row rather than an edit, so the plan and the build can be traced to the decision that shaped them.

| # | Decision | Accepted resolution |
|---|---|---|
| **D1** *(amended by D15)* | Base vs Pro split | **Everything is Pro.** The kernel ships in `addons/pro/lib/database/`; all thirteen tools register from the Pro toolkit. The base package has none, which is correct — it also has no Pro, so no connection can be authored. The original "7 Base tools" premise is retired: it assumed a site could hold a connection without Pro, and no supported distribution works that way. |
| **D2** | Postgres only, or multi-engine at launch | **Postgres first, with the engine abstraction in P0.** MySQL/MariaDB in P2 (they are the fleet's PHP-server engine), SQLite test-only. Do not ship a MySQL-specific kernel that Postgres has to work around. |
| **D3** | Raw SQL in v1 | **No.** Ship it in P5 behind its own tier, default off, with a documented hostile-input corpus. The structured surface is the product. |
| **D4** | Tenant model | **Reuse `TenantResolverInterface` (F8) and 007's vocabulary.** Do not invent a second tenant concept. Estate → `app.tenant_id`; assistant scope narrows further. |
| **D5** | Where credentials live | **In the record's own store, encrypted (F19) or a Password Vault handle (F3).** No plaintext DSN anywhere — a settings or connections export must never be a credential leak. |
| **D6** | Write path in scope? | **Yes, but last (P6) and behind a default-off gate.** The alternative is operators granting a writable role outside the toolkit, which is strictly worse. |
| **D7** | `db_describe` depth | **Scope-filtered catalogue, never `information_schema` passthrough.** System schemas are never listed. |
| **D8** | Per-call cost of `set_config` | **Accept it.** Two extra round trips per read (BEGIN + set_config) is the price of transaction-local scope; measure in P2 and revisit only with data. |
| **D9** | Migrate `ProductCatalog` onto the port | **Not in this proposal.** Prove the port first; migrate after P4 with the contract tests as the guard. |
| **D10** | Converge the Graphify generic-SQL driver onto the kernel | **Yes, opportunistically.** It is the same PDO surface and would gain admission + audit for free. Track as a follow-up, not a phase. |
| **D11** | Integrate with Remote Sites / the connection toolkits | **Yes — provider contract plus a `database` connection type**, not a fork. The kernel depends only on `WP_MCP_AI_Connection_Provider_Interface`, so Base, standalone and Laravel work when Remote Sites is absent. |
| **D12** | Per-assistant binding | **Reuse `_wp_mcp_ai_pro_remote_connections` (F21).** The scope narrows that list; no second enablement UI. |
| **D13** | Publish scopes to external MCP clients (`mcp.nvoos.pro`) | **Out of scope for v1**, but keep scope ids stable and audience-safe so they can become an RFC 8707 resource identifier without a rename. |
| **D14** *(superseded by D15)* | Availability | **`unavailable`, nothing registered**, whenever the kernel is absent or PHP < 8.2 — never a WordPress-side reimplementation. Availability is now "the Pro add-on is active", not "`lib/` is present" (D15). `enable_oos_engine` never affects it: that flag routes chat, it does not load classes. Purity enforced by enrolling `addons/pro/lib/database/src` as `strict` in `bin/check-domain-purity.php`. |
| **D15** | Where the kernel ships | **Inside the Pro add-on**: `addons/pro/lib/database/`, namespace `Nvoos\Database\`, with a four-line PSR-4 autoloader and a standalone PHPUnit suite. Verified from `bin/build-plugin-zip.sh` that a base+pro install has no `lib/`, so `lib/core` would have excluded Pro customers. Laravel loads the same files via a Composer path repository. Accepted 2026-11-27. |

---

## 167.9 Effort and success metrics

**Indicative effort** (one engineer, matching the density of 062/164 builds):

| Phase | Effort |
|---|---|
| P0 | 4–5 days |
| P1 | 6–8 days |
| P2 | 3–4 days |
| P3 | 4–5 days |
| P4 | 4–6 days |
| P5 | 3–4 days |
| P6 | 4–5 days |
| P7 | 2–3 days |
| **Total** | **~31–42 engineer-days**, plus review |

**Success metrics** (measurable, not aspirational):

| Metric | Target |
|---|---|
| Write attempts succeeding through the toolkit with a read-only role | **0**, by construction — proven by a test that removes L2 entirely |
| Cross-tenant reads in the probe suite | **0**; a scope without a mappable `row_filter` refuses |
| Calls with an audit record | **100%**, including refusals |
| Refusal codes that are typed (not free text) | **100%** |
| p95 shaping latency overhead vs raw PDO | **< 15 ms** for a 500-row result |
| Hostile-input corpus refused | **100%** of the committed corpus (§167.11 gate 6) |
| Tool-parity drift between WP and Laravel | **0** — same slugs, same refusal codes, enforced by a manifest test |
| Operator time to grant a scoped read | **< 10 minutes** from "I have a Postgres URL" to a working assistant call |

---

## 167.10 Out of scope, and alternatives rejected

**Out of scope:**

- A general BI/dashboard product. Charts exist (`create_chart`); this toolkit returns shaped rows.
- Schema migration, DDL, seeding. The toolkit never writes DDL.
- Replacing `$wpdb` or Laravel's own connection. The site/app database is not a target (§167.6.2).
- Managed connectors (Snowflake, BigQuery, Redshift) — the engine abstraction leaves room; none ship here.
- Vector search over Postgres (`pgvector` remains the vector-store adapter's business, F-table row 3).

**Rejected alternatives:**

| Alternative | Why rejected |
|---|---|
| One `run_sql` tool behind a read-only transaction | The 2026 bypass class (§167.4.1). A `COMMIT` ends the transaction; a denylist misses `SET`; concatenation is injection. |
| A SQL parser as the primary control | F17: no parser is a dependency. Adding one plus keeping it current is real cost, and per §167.4.1 a parser bug still reaches the database. The role is the control; admission is an early-exit courtesy. |
| `pg_read_all_data` as the agent grant | It is precisely the unrestricted scope this proposal exists to avoid (§167.4.8). |
| Tenant id as a tool parameter | Explicitly the anti-pattern in both 093 and the RLS literature. It can be omitted, guessed, or prompt-injected. |
| A hosted MCP SQL server (Toolbox, Draxlr, mcp-postgres) as the integration | It would move the scope model, the audit trail and the credential store outside the product, and the gateway cannot then reason about per-assistant scope. Borrow the *pattern*, not the process. |
| Prompt-level "only read" instructions as a control | Not a control. Stated in §167.6.5 L5 as advisory only. |

---

## 167.11 Validation gates

1. `php -l` on every new/changed file; `vendor/bin/phpcs --standard=WordPress` clean (phpcbf first).
2. `composer run docs:check-folder-readmes` passes — `includes/database/README.md` and `addons/pro/includes/tools/database/README.md` exist and declare purpose, public surface, neighbours, and which `.context/` files to load.
3. Docker PHPUnit green, including `test-tool-registry-coverage.php` (coverage manifests updated), `test-tool-schema-contract.php` (no numeric-key enum bug), and the new settings-page registration test.
4. Base+Pro init-gate pin under `tests/basepro/` — the toolkit loads only when `enable_database_toolkit` is on **and** a connection probes clean.
5. Preset sanity: workflow presets carry `name, description, category, icon, tags, nodes, edges`; every `edges` entry references a real node id.
6. **The L2-removal test.** With admission disabled entirely, a suite of write attempts must still fail at the role. This is the design's acceptance test, not a nice-to-have.
7. **The hostile-input corpus.** Committed cases covering every T-row in §167.5: multi-statement, `COMMIT`-injection, `WITH … DELETE`, `SET ROLE`, `pg_read_file`, `COPY … FROM PROGRAM`, `lo_import`, `dblink`, identifier injection, value injection, `%`-wildcard escape, `OFFSET` cliff, unbounded `SELECT`, cell bomb.
8. **The scope-preview test.** For a draft scope, the resolved statement, masked columns and row count match the live preview shown on the Scopes screen.
9. **Cross-runtime parity test.** The same request on WordPress and Laravel returns the same `refusal_code` and the same shaped envelope.
10. Admin-surface smoke: enable the toggle, `require` the toolkit `init.php`, assert the settings page class loads and `has_action( 'admin_menu', … )` is set; assert the module-registry entry exists; assert the Tools-section checkbox is present in `get_fields()` **and** in the `features` subtab group.
11. **The provider-independence test.** With `class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) === false`, the Base build and the Laravel runtime resolve connections from the native provider and every served tool still works. This is the test that stops a Pro dependency creeping into the kernel.
12. **Provider equivalence test.** The same logical connection authored once in Remote Sites (`connection_type: 'database'`) and once natively resolves to the same descriptor, the same probe result and the same refusal codes.

---

## 167.12 Appendix — schema sketches

### `db_select` parameters

```json
{
  "connection": "warehouse",
  "relation": "reporting.orders",
  "columns": ["order_id", "total_minor", "placed_at"],
  "filters": [
    { "column": "placed_at", "op": "gte", "value": "2026-11-01" },
    { "column": "status", "op": "in", "value": ["paid", "shipped"] }
  ],
  "order": [{ "column": "placed_at", "direction": "desc" }],
  "limit": 100,
  "cursor": null
}
```

### `db_aggregate` parameters

```json
{
  "connection": "warehouse",
  "relation": "reporting.orders",
  "group_by": ["currency"],
  "metrics": [
    { "op": "count", "as": "orders" },
    { "op": "sum", "column": "total_minor", "as": "revenue_minor" },
    { "op": "p90", "column": "total_minor", "as": "p90_minor" }
  ],
  "filters": [{ "column": "placed_at", "op": "gte", "value": "2026-11-01" }],
  "order": [{ "column": "revenue_minor", "direction": "desc" }],
  "limit": 20
}
```

### Typed refusal codes

```text
db_not_configured              no connection catalogue exists
db_unknown_connection          id not in the catalogue for this principal
db_connection_unavailable      probe missing or failed
db_connection_not_a_database   the resolved kind is not a database (e.g. mcp_server, wordpress)
db_scope_not_allowed           no scope grants this principal that connection
db_scope_table_not_allowed     relation outside the allowlist
db_scope_column_not_allowed    column outside the projection
db_scope_filter_unmappable     row_filter cannot be applied to this relation → refuse
db_tier_required               the tool needs a tier the scope does not hold
db_statement_not_admitted      L2 refusal (multi-statement, denylist, non-SELECT)
db_unsupported_metric          engine cannot express the requested metric
db_limit_exceeded              scope max_rows / max_bytes hit before completion
db_timeout                     statement_timeout fired
db_result_too_large            shaping could not fit the payload budget
db_approval_required           apply_write without a ticket
db_write_affected_mismatch     affected-row count changed since the proposal
db_site_database_refused       target resolves to the WordPress/app database
db_extension_missing           pdo_pgsql / pdo_mysql not loaded
db_privilege_denied            the database refused the grant (SQLSTATE 42501 / 1142 / 1143 / 1044)
db_relation_not_found          missing relation (42P01 / 1146)
db_column_not_found            missing column (42703 / 1054)
db_auth_failed                 credentials rejected (28P01 / 1045)
db_unknown_database            database does not exist (3D000 / 1049)
db_connection_limit            too many connections (53300 / 1040)
db_lock_timeout                lock wait exceeded (55P03 / 1205)
db_read_only_violation         a write reached a read-only transaction (25006) — a bug if seen
```

Every code is a stable string. The Laravel runtime returns the same strings, which is what makes gate 9 a real test rather than a spelling exercise.
