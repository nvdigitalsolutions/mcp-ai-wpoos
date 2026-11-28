# Database test design — index assertions, race harnesses, schema gates

Lessons from the run-board schema work (2026-11-28). Every one of these
produced a test that **passed while proving nothing**, which is worse than a
failing test.

## 1. `EXPLAIN` on a near-empty table reports `key=NULL`

A fresh test database has ~0 rows per table, so MariaDB picks a full scan and
`EXPLAIN` reports `key => NULL`. An assertion of the form
`assertSame( 'idx_run_status', $row->key )` therefore fails on a *correct*
schema, and — worse — the temptation is to "fix" it by deleting the assertion,
which then never catches a dropped index.

Rules:

1. Seed **≥ 2,000 rows** before `EXPLAIN`, then `ANALYZE TABLE` so the
   optimiser has statistics. Below that the optimiser has no reason to prefer
   the index.
2. Assert on **`key`** *and* **`possible_keys`**. `key` proves the index was
   actually chosen; `possible_keys` proves it was even *available*. A schema
   with the index dropped fails the second check; a schema with the index
   present but unusable (wrong column order) can fail the first.
3. **Never assert on `type`.** MariaDB appends a `|filter` suffix to the access
   type (e.g. `ref|filter`), so an exact `assertSame( 'ref', $row->type )`
   breaks on a MariaDB version bump rather than a regression.

`EXPLAIN` is also the only cheap way to catch an index that exists but is
ordered wrong for the query — the index is listed in `possible_keys` yet `key`
stays `NULL`, which rules 1–2 above surface and a `SHOW INDEX` check cannot.

## 2. The WP test harness rewrites `CREATE TABLE` → `CREATE TEMPORARY TABLE`

wp-phpunit intercepts `CREATE TABLE` and makes it **temporary**, and temporary
tables are **per-connection**. A two-connection test (the only way to test row
locking, lease races, or `INSERT … ON DUPLICATE KEY`) therefore runs against a
table the second connection cannot see: the race never happens and the test
passes vacuously.

Fix: call **`suspend_temporary_table_rewrite()`** and create the real table
outside the test transaction, then drop it in `tearDown()`. Note that the
harness also wraps each test in a transaction, which hides uncommitted writes
from the second connection for the same reason — the race harness has to
commit explicitly.

## 3. A verdict read from affected rows needs a monotonic column

Concurrency rules that read their verdict from `$wpdb->rows_affected` are
broken by MySQL's return of **0** when an `UPDATE` writes values identical to
what is already stored. A lease renew that sets the same `updated_at` inside
the same second reports "lost the lease" on a successful renewal.

Every such `UPDATE` must change a **strictly monotonic** column — `attempts`,
`renewals`, a counter — so that the write is guaranteed to differ. `updated_at`
is not sufficient: it collides within a one-second granularity. The claim/lease
pattern itself lives in the `wp-plugin-cron` skill, § "Claiming a shared row
(leases, not locks)".

## 4. `SKIP LOCKED` is not portable to this fleet

`SELECT … FOR UPDATE SKIP LOCKED` is MySQL 8.0+ / recent-MariaDB only and is
restricted to queue-style tables. The fleet runs MariaDB on the Cloudways
estate (see `docs/operations/fleet/server-settings-baseline.md`), and the
repo's house style is the conditional `UPDATE` + affected-rows read — used in
`class-nvoos-checkout-api-license-store.php` and
`WP_MCP_AI_Concurrency_Guard`. Follow that rather than introducing
`SKIP LOCKED` in one subsystem.

## 5. Schema charset is gated in CI — run the gate, do not eyeball it

`bin/check-db-charset.php` fails any first-party `CREATE TABLE` that omits an
explicit charset. It is wired as `composer run quality:db-charset` (part of
`ci:all`) **and** as the `db-charset` job in `.github/workflows/php-linting.yml`.
Tokeniser-based, so it does not false-positive on regex strings containing the
word `charset`.

Why it exists: `character_set_server` is `latin1` on 8 of the 9 Cloudways
servers (MariaDB's own default through 10.11; `utf8mb4` only from 11.8), so a
`CREATE TABLE` without `$wpdb->get_charset_collate()` lands as latin1 on the
fleet and utf8mb4 on a developer's machine — a bug that only appears in
production, and only for non-ASCII content. The dbDelta formatting rules that
must accompany this are in the `wp-plugin-lifecycle` skill.

Useful invocations:

```bash
php bin/check-db-charset.php --help        # scope, --only, --report, --selftest
php bin/check-db-charset.php --selftest    # 8 fixtures proving the gate both ways
```

## 6. `character_set_server` is not settable through the API

`server_settings_update` does not expose it. Changing it means editing
`/etc/mysql/mariadb.conf.d/50-server.cnf` over SSH (server-wide, restart) or a
support ticket — do **not** do that for a plugin-level non-issue on a shared
client host. Append `get_charset_collate()` in the plugin instead.
