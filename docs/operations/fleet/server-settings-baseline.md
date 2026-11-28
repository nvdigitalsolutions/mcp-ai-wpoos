# Server settings baseline — measured 2026-11-28

Recorded so that a future audit does not re-flag an intentional deviation as a finding, and so the plugin's PHP floor can be argued from measurement rather than assumption.

**Method:** Cloudways API `server_settings_get` for every server on both accounts; app counts from `app_list`. Read-only.

## Baseline

| Server | Label | Account | PHP | MariaDB | `character_set_server` | `multi_app_php` | Apps |
|---|---|---|---|---:|---|---|---:|
| 1536372 | NV Digital MCP | NV Digital | 8.2 | 10.11 | latin1 | yes | 13 |
| 1686836 | NV Digital US2 | NV Digital | 8.2 | 10.11 | latin1 | yes | 5 |
| 1099434 | NV Digital US | NV Digital | **8.1** | 10.11 | latin1 | yes | 14 |
| 1099457 | NV Digital SIN | NV Digital | **8.1** | 10.11 | latin1 | yes | 8 |
| 1122552 | NV Digital SIN2 | NV Digital | **8.1** | 10.11 | latin1 | yes | 4 |
| 1153029 | Mushroom | Mushroom | 8.2 | 10.11 | **ascii** | yes | 8 |
| 1691080 | Cloned-Mushroom | Mushroom | 8.2 | 10.11 | latin1 | yes | 1 |
| 1363022 | TEMP | Mushroom | **8.1** | 10.11 | latin1 | yes | 2 |
| 1204100 | Pandora | Mushroom | **8.1** | 10.11 | latin1 | yes | 3 |

Totals: **9 servers, 57 apps. PHP — 4 servers on 8.1, 5 on 8.2, 0 on 8.3.**

`multi_app_php: yes` everywhere means PHP is chosen **per application** (Application Settings → PHP Version), so any migration is app-by-app, not one setting per server.

## Accepted deviations

### `character_set_server = latin1` (8 of 9 servers)

**Accepted — no action.** This is MariaDB's own default up to and including 10.11; the switch to `utf8mb4` first ships in MariaDB **11.8** (MDEV-19123). It is not a Cloudways misconfiguration and not evidence of drift.

It is also inert for this plugin:

- WordPress issues `SET NAMES utf8mb4` on connect, so the session charset does not follow the server default.
- Every core table and every first-party table passes `$wpdb->get_charset_collate()`, which emits `DEFAULT CHARACTER SET utf8mb4`.
- An audit found 61 files containing `CREATE TABLE`; **none is a live WordPress DDL that omits a charset.** The 15 files without a charset marker are tests, vendored code (`wp-phpunit`, Laravel/Symfony), or the Cloudflare D1/SQLite path in `saas-controller`.

### `character_set_server = ascii` (server 1153029, Mushroom)

**Accepted, unexplained — worth remembering.** `ascii` is *not* the MariaDB default, so it was set deliberately at some point. It is the one server where a charset-less table fails **loudly** — inserting `é` into an ascii column under strict mode raises "Incorrect string value" — rather than silently mangling bytes as latin1 would.

If a third-party plugin on that server ever creates a table without a charset, this is the box where it will show up first.

### Do not change either variable server-side

`server_settings_update` does not expose `character_set_server`. The only routes are an SSH edit of `/etc/mysql/mariadb.conf.d/50-server.cnf` followed by a MariaDB restart, or a support ticket — both **server-wide**, on hosts shared with client sites, to address a plugin-level non-issue. The durable mitigation is the application-side gate below.

## Enforcement

`bin/check-db-charset.php` fails when a first-party `CREATE TABLE` omits a charset. Wired in two places:

- `composer run ci:all` → `quality:db-charset` (`--scope=all --selftest`)
- `.github/workflows/php-linting.yml` → the `db-charset` job

`--selftest` runs eight fixtures first, so a refactor that breaks the detector fails the build instead of turning the gate permanently green.

Statements targeting a non-MySQL engine opt out inline:

```php
// charset-exempt: Cloudflare D1 (SQLite) schema
```

## Verify rather than assume

Per database, these two queries are decisive. Empty results mean the server default is doing nothing:

```sql
SELECT table_name, table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_collation NOT LIKE 'utf8mb4%';

SELECT table_name, column_name, character_set_name
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND character_set_name IS NOT NULL
  AND character_set_name NOT IN ('utf8mb4','utf8mb3');
```

If a conversion is ever needed, never run `ALTER TABLE … CONVERT TO CHARACTER SET utf8mb4` blind: it rewrites the table and *amplifies* existing mojibake if the data is already double-encoded. Copy, inspect with `SELECT HEX(col)`, then convert.

## Re-measure

```bash
# Per server: php, mariadb, character_set_server, multi_app_php
cloudways_nvdigital_server_settings_get  <server_id>
cloudways_mushroom_server_settings_get   <server_id>

# Per app: php_version (fields=...,php_version)
cloudways_nvdigital_app_list  --limit=100 --fields=id,label,application,php_version,server_label
cloudways_mushroom_app_list   --limit=100 --fields=id,label,application,php_version,server_label
```

Note: `app_list` with `fields=…,php_version` did **not** return a `php_version` key on 2026-11-28; `server_settings_get` did. Trust the latter until the former is confirmed.

## Related

- PHP floor decision and migration staging: [`docs/project/proposals/170-php-floor-decision.md`](../../project/proposals/170-php-floor-decision.md)
- PHP 8.1 baseline (the original floor change): [`docs/project/proposals/133-php-81-baseline-proposal.md`](../../project/proposals/133-php-81-baseline-proposal.md)
