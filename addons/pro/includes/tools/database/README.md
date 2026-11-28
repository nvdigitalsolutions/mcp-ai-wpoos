# Database Toolkit (Pro)

> Scoped, audited database access for assistants. Proposal 167.

A **connection** names an endpoint. A **scope** is the grant: schemas, tables, column rules, mandatory row filters, capability tiers and named principals. The database role — not the prompt, not the parser — is what cannot write.

## Contents

| File | Responsibility |
|---|---|
| `init.php` | Load gate: toggle → `WP_MCP_AI_Db_Runtime::load()` → tool classes → Remote Sites credential filter → admin page |
| `class-wp-mcp-ai-database-tool-base.php` | The six shared steps: availability, principal from context, connection resolution, sanitisation, executor, canonical envelope |
| `class-wp-mcp-ai-tool-db-*.php` | Eleven read/ops tools + two write tools |
| `class-wp-mcp-ai-database-presets.php` | Five schedule presets (weekly refusal review, daily connection health, weekly scope census, monthly data-quality profile, monthly export digest) and one workflow preset (profile + export, with `edges`) |

## The thirteen tools

| Slug | Tier required | Notes |
|---|---|---|
| `db_list_connections` | — | Scope-derived, redacted; no host, database, credential or replica |
| `db_describe` | `metadata` | Scope-filtered catalogue; excluded columns absent |
| `db_profile_table` | `read` | ≤8 columns; sensitive columns skipped, not profiled |
| `db_explain` | `metadata` + `read` | `analyze` additionally requires `explain_analyze` |
| `db_select` | `read` | Columns, filters, order, limit, keyset cursor |
| `db_aggregate` | `read` | Closed metric enum; no SQL |
| `db_search` | `read` | Declared searchable columns; wildcards escaped |
| `db_query` | `raw_sql` | One admitted `SELECT`/`WITH … SELECT`, prepared only |
| `db_export` | `export` | CSV/JSON to the host file store; checksum returned |
| `db_test_connection` | — (`manage_options`) | Privilege introspection; reports role health |
| `db_audit_tail` | — (`manage_options`) | Templates and refusals, never bound values |
| `db_propose_write` | `propose_write` | Default off; computes the database's affected-row count, changes nothing |
| `db_apply_write` | `apply_write` | Default off; human approval + scope-version and row-count drift checks |

The two write tools are registered only when `enable_database_writes` is on **and** the scope holds the tiers **and** `max_affected_rows` is non-zero.

## Availability

Three conditions, all required: the Pro kernel loads, `enable_database_toolkit` is on, and at least one connection exists (native or a Remote Sites `database` record).

Availability is **never** gated on `enable_oos_engine`. That flag routes chat; it does not load classes.

## Configuration

**NV oOS Pro → Database** (`manage_options`): connections, scopes, write proposals, audit, health.

A connection stores its credential as an encrypted blob (`WP_MCP_AI_Encryption`) or a `vault:`/`remote:` handle. A plaintext password is refused at save time.

## Tests

```bash
# Portable adapter + executor suite (SQLite fixtures)
php lib/core/vendor/bin/phpunit -c tests/database/portable/phpunit.xml.dist

# Kernel suite
php lib/core/vendor/bin/phpunit -c addons/pro/lib/database/phpunit.xml.dist
```

Postgres gates (L2 removal; hostile corpus on a live engine) need `NVOOS_TEST_PGSQL_DSN` and skip without it.

## See Also

- `includes/database/README.md` — adapters, executor, registries
- `includes/connections/README.md` — id namespacing
- `docs/project/proposals/167-database-toolkit-scoped-access-implementation-plan.md`
