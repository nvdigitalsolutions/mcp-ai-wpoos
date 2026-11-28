# 170 — PHP floor: hold at 8.1, test it, and migrate the estate first

**Status:** Decided 2026-11-28 — **floor stays 8.1**. The declared-vs-tested gap is closed by adding 8.1 to CI, not by raising the declaration.
**Supersedes:** nothing. **Extends:** [133](./133-php-81-baseline-proposal.md), which set 8.1 as a deliberate bridge and named 8.2 as the recommended next step.
**Surfaces:** `.github/workflows/php-linting.yml` and the workflow PHP matrix (the only change now); the floor fields in `mcp-ai-wpoos.php`, `readme.txt`, `composer.json` are **unchanged**
**Related:** [168](./168-multi-agent-team-scale-and-a2a-conformance-proposal.md) / [168-P0](./168-multi-agent-team-scale-and-a2a-conformance-p0-implementation-plan.md), [167](./167-database-toolkit-scoped-access-proposal.md) (requires 8.2 — stricter than the plugin floor, and unaffected)

## 170.1 The evidence

From php.net's supported-versions and EOL tables, read 2026-11-28:

| Branch | Active support until | Security support until | Status today |
|---|---|---|---|
| **8.1** | 2023-11-25 | **2025-12-31** | **EOL — 10 months, last release 8.1.34** |
| **8.2** | 2024-12-31 | **2026-12-31** | Security-only; **33 days left** |
| **8.3** | 2025-12-31 | 2027-12-31 | Security-only; 13 months left |
| **8.4** | 2026-12-31 | 2028-12-31 | Active for 33 more days; 25 months security |
| 8.5 | 2027-12-31 | 2029-12-31 | Active |
| 8.6 | 2028-12-31 | 2030-12-31 | Released 2026-11-19 |

8.1 is genuinely unsafe to sit on. That is not in dispute. What follows is about *what to do when you cannot leave yet*.

## 170.2 The estate, measured (not assumed)

`server_settings_get` across all nine servers on both accounts, 2026-11-28:

| Server | Label | PHP | MariaDB | `character_set_server` | Apps |
|---|---|---|---|---:|---:|
| 1536372 | NV Digital MCP | 8.2 | 10.11 | latin1 | 13 |
| 1686836 | NV Digital US2 | 8.2 | 10.11 | latin1 | 5 |
| **1099434** | **NV Digital US** | **8.1** | 10.11 | latin1 | 14 |
| **1099457** | **NV Digital SIN** | **8.1** | 10.11 | latin1 | 8 |
| **1122552** | **NV Digital SIN2** | **8.1** | 10.11 | latin1 | 4 |
| 1153029 | Mushroom | 8.2 | 10.11 | **ascii** | 8 |
| 1691080 | Cloned-Mushroom | 8.2 | 10.11 | latin1 | 1 |
| **1363022** | **TEMP** | **8.1** | 10.11 | latin1 | 2 |
| **1204100** | **Pandora** | **8.1** | 10.11 | latin1 | 3 |

**Four of nine servers are on 8.1; five are on 8.2; none is on 8.3.** The 8.1 servers carry the majority of the long-standing client WordPress sites — the US, SIN and SIN2 servers hold 26 of the 57 tracked apps.

Two properties of the platform make this decisive:

- `multi_app_php: yes` on every server — **PHP is set per application** (Application Settings → PHP Version), not once per server. Migration is app-by-app, in waves.
- `Requires PHP` is a hard gate. wp.org refuses the install, and `WP_MCP_AI_MIN_PHP` refuses to load the plugin with an admin notice. It is not "works but unsupported".

So a floor of 8.2 would take every app on those four servers dark, and 8.3 would take all fifty-seven.

## 170.3 The actual defect, restated

| Where | Declared PHP |
|---|---|
| `mcp-ai-wpoos.php`, `readme.txt`, `composer.json` | **8.1** |
| `.github/workflows/*` | 8.1 (2 pins), **8.2 (31 pins)**, 8.3 (10 pins) |
| `lib/core/composer.json` | **^8.2** |
| `docker-compose.yml` | 8.3 |

The plugin declares 8.1 and is *actually required to run on 8.1* by four production servers — but the suite runs almost entirely on 8.2/8.3. That is the risk: **a code path that only 8.1 can reach is untested, on the platform that needs it most.**

There are two ways to make declaration and tests agree:

| Option | Cost | Risk |
|---|---|---|
| Raise the floor to 8.2 | 31 workflow pins + ~156-file mechanical sweep (133's §3) | **Takes 26 apps dark on four servers** |
| Add 8.1 to the CI matrix | a few workflow lines | none |

Option two is the one that fixes the defect without an outage. 133 already anticipated this shape — it set 8.1 as a bridge and listed 8.2 as the "recommended next" step, explicitly noting that 8.1's own security support had ended.

## 170.4 Decision

1. **Floor stays 8.1.** No change to the plugin header, `readme.txt`, `composer.json` `require.php`, or the compat `testVersion` range.
2. **CI gains an 8.1 leg.** The suite must run on the floor it advertises. This is the change that closes the gap.
3. **8.2 becomes the floor only after the four 8.1 servers are migrated** (Stage 2 below). 8.3 only after all nine (Stage 4).
4. **`lib/core`'s `^8.2` is correct as a library constraint** and should stay; it is not the plugin's floor and does not load on a Base-only install.

`testVersion 8.1-8.4` stays as-is: it is already the right range for a floor of 8.1.

## 170.5 The migration (the real work, and it is infrastructure)

This is the plan that makes a floor bump possible at all. It is ordered so the code change is always last.

| Stage | Work | Gate |
|---|---|---|
| **1. Pilot** | Move one low-risk app to 8.2, then 8.3. Prefer a `phpstack` app (YOURLS — no plugin stack) or a staging app (`Staging-Oceanswell`, `Staging-MYYCO`) | Plugin loads; smoke pass; no deprecations in `debug.log` |
| **2. 8.1 servers → 8.2** | 1099434, 1099457, 1122552, 1363022, 1204100 — app by app, staging before production | Every app ≥ 8.2 → `Requires PHP: 8.2` flip, with a notice release first |
| **3. All servers → 8.3** | All nine, in waves by server, ascending criticality | Every app ≥ 8.3 |
| **4. Floor → 8.3** | Header, `readme.txt`, `composer.json`, both compat ranges `8.3-8.5` | 133's §3 sweep replayed as 8.1→8.3 |

The plugin's own `WP_MCP_AI_MIN_PHP` notice is the safety net at every stage: an app that moves later than its peers fails visibly and gracefully, never fatally.

## 170.6 What changes in code now

| File | Change |
|---|---|
| `.github/workflows/php-linting.yml` | Add an **8.1** leg to the PHP compatibility job's matrix (it currently runs 8.2/8.3) |
| Other workflows | Where a PHP-8.2 pin guards a job that exercises plugin code, add 8.1; leave infra-only jobs alone |
| Everything else | **No change.** The floor fields already say 8.1 and are now truthful because 8.1 is tested |

A future floor bump replays [133 §3](./133-php-81-baseline-proposal.md) mechanically: 51 runtime guards, 20 addon headers, Site Health fallbacks, admin diagnostics, `lib/wordpress-adapter` and `addons/pro` composer constraints, ~46 docs. Budget it as a mechanical sweep, not a design task.

## 170.7 The charset finding, recorded

Measured at the same time and **accepted, with no change**:

- **`character_set_server = latin1` on eight of nine servers.** That is MariaDB's own default through 10.11; the change to `utf8mb4` first ships in MariaDB **11.8**. Not a misconfiguration.
- **`character_set_server = ascii` on `1153029` (Mushroom) only.** Not the default, so it was set deliberately at some point. Accepted, but it is the one server where a charset-less table would *error* ("Incorrect string value") rather than silently mangle — which is why the gate below exists.
- **Impact on this plugin: none measured.** WordPress sets the connection charset (`SET NAMES utf8mb4`) on connect and passes `DEFAULT CHARACTER SET utf8mb4` on every core table. An audit of the plugin found **61 files containing `CREATE TABLE`; none is a live WordPress DDL that omits a charset** (the 15 without a charset marker are tests, vendored code, or the Cloudflare D1/SQLite path).
- **Not changing the server variable.** It is not exposed by `server_settings_update`, so the only routes are an SSH edit of `mariadb.conf.d/50-server.cnf` (restarting MariaDB for every tenant) or a support ticket. Changing a server-wide default to fix a plugin-level non-issue, on servers shared with client sites, is the wrong trade.

**The durable mitigation** is application-side and now enforced: [`bin/check-db-charset.php`](../../bin/check-db-charset.php) fails when a first-party `CREATE TABLE` omits a charset, with `--selftest` fixtures so a refactor cannot quietly neuter it. It runs in `composer ci:all` (`quality:db-charset`) and as its own job in `php-linting.yml`.

## 170.8 Risk

| Risk | Assessment | Mitigation |
|---|---|---|
| 8.1 stays EOL while the estate sits on it | **Certain, already true for 10 months** | Stage 2 is the priority; the pilot is a single app and can start immediately |
| 8.1-only paths are untested | **Certain today** | The 8.1 CI leg is the whole point of this decision |
| A floor bump ships before the servers move | **Catastrophic** — 26 apps dark at 8.2, 57 at 8.3 | Floor change is the *last* step of each stage, never the first |
| Migration stalls and 8.2 also goes EOL (2026-12-31) | Likely if Stage 2 slips past the new year | Treat it as a scheduled project with an owner, not a chore; the pilot has no dependencies |
| An app is left behind on an old runtime | Low | `MIN_PHP` fails loudly with a notice naming the required version |

## 170.9 Effect on Proposals 167 and 168

**167 (database toolkit).** Requires PHP 8.2, which is **stricter than the plugin floor of 8.1**. That is fine: the toolkit is Pro-runtime only and Base installs never load it. Nothing changes.

**168 (Run Board).** The board must run wherever the plugin runs, i.e. on 8.1 — so it stays on `$wpdb` and avoids 8.2+ syntax. Note that its case for not using 167's toolkit **never rested on PHP**: it rests on 167 being base-files/Pro-runtime, on a separate PDO connection, and on a propose/approve/apply write model. The PHP row is at most a secondary difference and is deliberately no longer cited as a reason.

## 170.10 Sign-off checklist

- [ ] 8.1 added to the CI matrix and green
- [ ] `Requires PHP`, `WP_MCP_AI_MIN_PHP`, `composer.json`, both `testVersion` ranges **unchanged** at 8.1 / `8.1-8.4`
- [ ] Pilot app running 8.3 (Stage 1) with a smoke pass recorded
- [ ] Migration owner and dates recorded for Stage 2 (four 8.1 servers)
- [ ] Charset baseline accepted and recorded; `quality:db-charset` gate green in `ci:all` and in `php-linting.yml`
- [ ] No floor change merged before its stage gate is met
