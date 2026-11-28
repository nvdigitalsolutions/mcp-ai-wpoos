# Database Toolkit — tool index

Every tool returns the canonical envelope: a success array (`success: true`) or a
`WP_Error` whose data carries `refusal_code`. Refusals are stable codes, not
prose, and are not a hint to retry with different arguments.

Common optional arguments on the data tools:

| Argument | Meaning |
|---|---|
| `connection` | Id from `db_list_connections`. Optional when exactly one is visible. |
| `scope` | Scope id. Optional when the principal holds one grant for the connection. |

---

## `db_list_connections`

No arguments. Returns redacted summaries: id, label, engine, kind, queryable,
last probe. Never a host, database name, credential handle or replica.

## `db_describe`

| Argument | Type | Notes |
|---|---|---|
| `relation` | string | Optional; a single `schema.table` for a detailed view |

Returns tables with `relation`, `is_view`, `primary_key`, `columns[]`. Excluded
columns and system schemas are absent. Capped at 60 relations with `truncated`.

## `db_profile_table`

| Argument | Type | Notes |
|---|---|---|
| `relation` | string | Required |
| `columns` | string[] | Optional; ≤8, intersected with readable columns |

Returns `row_count`, per-column `nulls`, `null_percent`, `distinct`, `min`, `max`,
`most_frequent[5]`, plus `skipped[]` for sensitive columns.

## `db_explain`

Uses the `db_select` arguments plus `analyze` (boolean, default false).
`analyze` requires the `explain_analyze` tier. Returns `plan`, `analyzed`,
`engine`, `elapsed_ms`.

## `db_select`

| Argument | Type | Notes |
|---|---|---|
| `relation` | string | Required |
| `columns` | string[] | Omit for every allowed column |
| `filters` | object[] | `{column, op, value}`; ops: `eq ne in not_in gt gte lt lte between like ilike is_null is_not_null` |
| `order` | object[] | `{column, direction}`; a single direction enables cursors |
| `limit` | int | The scope cap wins when lower |
| `offset` | int | Refused above the scope ceiling |
| `cursor` | string | `next_cursor` from a previous call |

Returns `columns`, `rows`, `row_count`, `truncated`, `truncated_reason`,
`next_cursor`, `masked`, `elapsed_ms`, `audit_id`.

## `db_aggregate`

| Argument | Type | Notes |
|---|---|---|
| `relation` | string | Required |
| `metrics` | object[] | `{metric, column?, alias?}`; metrics: `count count_distinct sum avg min max median p90` |
| `group_by` | string[] | Optional |
| `filters`, `order`, `limit` | | As `db_select` |

## `db_search`

| Argument | Type | Notes |
|---|---|---|
| `relation` | string | Required |
| `term` | string | Required; literal, wildcards escaped |
| `columns` | string[] | Optional; defaults to declared searchable columns |

## `db_query`

| Argument | Type | Notes |
|---|---|---|
| `sql` | string | One `SELECT` or `WITH … SELECT`, `:name` placeholders |
| `params` | object | Values keyed by placeholder name |

Requires the `raw_sql` tier. Admission refuses DDL/DML, `SET ROLE`, file and
`dblink` functions; the server also refuses stacking on a native prepare; the
transaction is `READ ONLY`.

## `db_export`

Uses the `db_select` arguments plus `format` (`csv` | `json`). Requires the
`export` tier. Returns `file`, `url`, `bytes`, `checksum` (`sha256:…`), `rows`,
`truncated`, `masked`, `audit_id`.

On the Laravel host the payload goes through `FileStoreInterface` instead of the
uploads directory, and is stored **private** when the store supports visibility —
so `url` is null and `file` is the stored path. `stored` reports which happened.

## `db_test_connection`

| Argument | Type | Notes |
|---|---|---|
| `connection` | string | Required; `rs:` ids are probed but not persisted |

Requires `manage_options`. Returns `probe` (`reachable`, `server_version`, `role`,
`can_write`, `bypasses_rls`, …) and operator `guidance[]`.

## `db_audit_tail`

| Argument | Type | Notes |
|---|---|---|
| `limit` | int | 1–200, default 50 |
| `connection`, `scope`, `action`, `refusal_code` | string | Optional filters |

Requires `manage_options`. Returns `records` (newest first), `count`, `summary`
counts for the last hour.

## `db_propose_write` (default off)

| Argument | Type | Notes |
|---|---|---|
| `operation` | string | `update` or `delete` |
| `relation` | string | Required |
| `set` | object | Required for `update` |
| `filters` | object[] | **Required, at least one** |
| `rationale` | string | Required |

Returns the proposal `id`, `statement_template`, `estimated_rows` (counted by the
database) and `expires_at`. Never executes.

## `db_apply_write` (default off)

| Argument | Type | Notes |
|---|---|---|
| `proposal_id` | string | Required |

Refuses unless a human approved the proposal, the scope version is unchanged and
the row count still matches. Commits only when the affected count equals the
re-measured count.
