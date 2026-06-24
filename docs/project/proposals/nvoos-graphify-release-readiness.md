# NV oOS Graphify — Release Readiness Plan

**Date:** 2026-06-22
**Status:** Draft
**Plugin:** `plugins/nvoos-graphify`
**Target:** Stable `1.0.0` release
**Context:** Plugin Check run 2026-06-18 against WP 7.0, code review, and documentation audit

---

## Purpose

Provide a single source of truth for every known issue that blocks or weakens a stable `1.0.0` release. This document merges:

- The Plugin Check output (verbatim run on WP 7.0, 2026-06-18)
- A code-level audit of runtime correctness, schema safety, and documentation accuracy
- A test-suite review

**Release Position:** Do not tag `1.0.0` until every **Must Fix** item is complete. WordPress.org submission is blocked while Plugin Check reports errors.

---

## Must Fix Before Stable 1.0

### 1. Resolve the REST read access model

**Problem:** The code allows graph read endpoints to any logged-in user with the `read` capability and to anonymous callers holding a valid base-plugin guest token. Public documentation (README, REST README, CHANGELOG) states read endpoints require `edit_posts`.

**Required outcome:**
- Code, README, REST docs, CHANGELOG, and tests describe the **same** access model.
- The graph never exposes private or non-public content to Subscribers or guest-token callers unless that exposure is explicitly designed and documented.
- (Can hold later) Tests cover anonymous access, Subscriber access, Editor access, and Admin access.

---

### 2. Remove runtime fatals and false-success states

These are separate bugs with different triggers.

#### 2a. Uncaught fatals — method name mismatches

| Affected tool / route | Calls | Actual method |
|------------------------|-------|---------------|
| `GetNode` | `Db::get_node()` | `Db::getNode()` |
| `GetNeighbors` | `Db::get_neighbors()` | No such method — uses `getEdgesForNode` + `getNode` |
| `QueryGraph` | `Db::query_nodes()` | No such method — uses `listNodes` / `searchNodes` |
| `GraphStats` | `Db::get_stats()` | `Db::getStats()` |

**Fix:** Change the tool `execute()` calls to the existing camelCase methods. If standardization to snake_case is preferred, rename methods project-wide — but camelCase matches the rest of the codebase.

#### 2b. Uncaught fatal — `sync_remote_source` tool and REST `POST /sources/{slug}/sync`

Both call `Enricher::sync_source()`, but the class defines `syncSource()`. Change the call sites to `syncSource()`.

#### 2c. Uncaught fatal — webhook handler `receiveWebhook()`

Two problems:
1. Instantiates `NvoosGraphify\Remote\Drivers\Webhook` directly, but the driver class does not exist in this repo. Once a webhook source row exists, every `POST /webhooks/{slug}` returns HTTP 500.
2. References `NvoosGraphify\Crypto`; the class lives at `NvoosGraphify\Remote\Crypto`.

**Fix:** Either ship the Webhook driver class or guard the webhook route so it returns a clear `WP_Error` ("Webhook driver not installed") when the class is missing. Fix the Crypto namespace reference.

#### 2d. False success — admin Sync button

`Remote\Enricher::syncSource()` is a stub that always returns `success: true` with zero nodes and zero edges. The admin AJAX handler (`ajaxSyncSource`) calls this stub directly, skips the driver registry, and sends `wp_send_json_success()`. The Sync button shows a success alert even though nothing ran.

**Fix:** Admin Sync returns an explicit unavailable/skipped/error state when no driver or addon ran the sync — not `success: true` with zero nodes.

**Required outcome:**
- Registered tools and REST routes either perform the advertised work or return a clear `WP_Error`.
- Missing optional addons do not cause uncaught PHP errors.

---

### 3. Fix the embeddings table schema

**Problem:** `Graph\Db` creates an `embeddings` table with an unquoted `vector` column. `VECTOR` is reserved in modern MariaDB and a keyword or future-compatibility risk in MySQL.

**Required outcome:**
- Fresh installs create every plugin table on supported database versions.
- Prefer renaming the column. If the name stays, quote **every** SQL reference, not only the DDL.
- Add an integration test that installs the schema against a real WordPress test database.

---

### 4. Clear WordPress.org and Plugin Check blockers

This section consolidates all issues found by `wp plugin check nvoos-graphify` on 2026-06-18 against WP 7.0, plus `.org` readme-parser issues.

#### 4a. readme.txt — header and metadata (2 ERRORs, 4 WARNINGs)

| # | Type | Issue | Fix |
|---|------|-------|-----|
| 1 | **ERROR** | `Tested up to: 6.9` < 7.0 | Change L6 to `Tested up to: 7.0` |
| 2 | **ERROR** | `Stable tag: 1.0.0` ≠ `Version: 1.0.0-dev` in main plugin file | Align both to `1.0.0` (see Decision A below) |
| 3 | WARNING | Plugin name mismatch: readme L1 says "NV oOS Graphify — Visual Knowledge Graph for WordPress" but header says "NV oOS Graphify" | Change readme L1 to `=== NV oOS Graphify ===` |
| 4 | WARNING | Too many tags (>5). Current: `knowledge graph, content visualization, cytoscape, content strategy, internal links, SEO, schema, structured data, related posts` (9 tags) | Reduce to 5: `knowledge graph, content visualization, cytoscape, content strategy, semantic web` |
| 5 | WARNING | Short description > 150 chars (L12) | Trim to ≤150 characters. Suggestion: `Map your WordPress content into an interactive knowledge graph. See relationships between posts, terms, and authors. Discover content gaps.` (~144 chars) |
| 6 | WARNING | Trademarked term "WordPress" in plugin name | Fixed by #3 — after removing the subtitle the readme title does not contain "WordPress." The plugin header `Plugin Name: NV oOS Graphify` is already clean. The short description may still contain "WordPress" — that is allowed in descriptions, not in the name. |

#### 4b. `src/Admin/Section.php` — Output escaping errors (8 ERRORs)

| Line | Issue | Root cause |
|------|-------|------------|
| 173 | `$name` not escaped at echo point | `$name` is pre-escaped via `esc_attr()` on L163, but the linter requires an escaping function visible at each `echo` statement |
| 181 | Same | Same pattern in `<select>` output |
| 194 (name) | `$name` not escaped | Same |
| 194 (min) | `$min` not escaped | `$min` is built from `absint()` output (L192), but the variable appears in an echo context without an escaping function |
| 194 (max) | `$max` not escaped | Same as min (L193) |
| 201 | `$name` not escaped | Password field output |
| 209 | `$name` not escaped | Text field output |

**Fix strategy:** Replace inline string concatenation with `printf()` calls where each dynamic value is explicitly escaped at the output point. For the number field (lines 191–198):

```php
// Replace:
$min = isset( $field['min'] ) ? ' min="' . \absint( $field['min'] ) . '"' : '';
$max = isset( $field['max'] ) ? ' max="' . \absint( $field['max'] ) . '"' : '';
echo '<input type="number" name="' . $name . '" value="' . \absint( $value ) . '"' . $min . $max . ' class="small-text">';

// With:
printf(
    '<input type="number" name="%s" value="%d"%s%s class="small-text">',
    esc_attr( $name ),
    \absint( $value ),
    isset( $field['min'] ) ? sprintf( ' min="%d"', \absint( $field['min'] ) ) : '',
    isset( $field['max'] ) ? sprintf( ' max="%d"', \absint( $field['max'] ) ) : ''
);
```

Apply the same pattern to lines 173, 181, 201, 209.

#### 4c. `src/Admin/SettingsPage.php` — Nonce verification (2 WARNINGs)

| Line | Issue | Fix |
|------|-------|-----|
| 180-181 | `$_REQUEST['_wp_http_referer']` accessed without nonce verification | Replace with `$referer = wp_get_referer();` — this avoids direct superglobal access entirely and returns the same value. The code sits inside a `register_setting()` sanitization callback; WP core handles nonce verification before invoking it. |

#### 4d. `src/Admin/Sections/SourcesCptsSection.php` — Nonce missing (3 WARNINGs)

| Line | Issue | Fix |
|------|-------|-----|
| 91 (×2) | `$_POST['nvoos_cpt_include']` without nonce | Add `WordPress.Security.NonceVerification.Missing` to the existing `phpcs:ignore` comment on L97, or add a separate `phpcs:ignore` above L91. This runs inside a `register_setting()` sanitization chain where WP core verifies nonces. |
| 98 | `$_POST['nvoos_cpt_include']` without nonce | Same — covered by the same `phpcs:ignore` if placed correctly |

#### 4e. `src/Admin/Sections/SourcesExtSection.php` — Nonce missing (3 WARNINGs)

| Line | Issue | Fix |
|------|-------|-----|
| 91 (×2) | `$_POST['nvoos_ext_table']` without nonce | Same approach as SourcesCptsSection.php |
| 98 | `$_POST['nvoos_ext_table']` without nonce | Same |

#### 4f. `uninstall.php` — Direct database and schema change (7 WARNINGs)

| Line | Issue | Fix |
|------|-------|-----|
| 27 | Direct query, no caching, schema change (DROP TABLE) | Add `phpcs:disable` block at the top of the file. This is a standalone uninstall script — direct DB access is the only option. |
| 35 | Direct query, no caching (DELETE transients) | Covered by file-level disable |
| 41 | Direct query, no caching (DELETE timeout transients) | Covered by file-level disable |

```php
// Add after the WP_UNINSTALL_PLUGIN guard:
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
```

#### 4g. `src/Graph/Db.php` — Critical bug + bulk warnings

**Critical bug — Line 348 (`Placeholders.WrongNumber`):**

```php
// CURRENT (broken):
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$table} {$whereSql} ORDER BY {$orderBy} {$order} LIMIT %d OFFSET %d",
        $params   // ← array passed as single argument; $wpdb->prepare expects individual args
    )
);

// FIXED:
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$table} {$whereSql} ORDER BY {$orderBy} {$order} LIMIT %d OFFSET %d",
        ...$params   // ← spread operator unpacks array into individual args
    )
);
```

`$params` can contain 2–5 elements (where clauses + limit + offset). Without the spread operator, `$wpdb->prepare()` sees only 1 replacement parameter (the array itself) but the SQL contains 2+ placeholders. This **does** produce wrong results at runtime when `$whereSql` is non-empty.

**Direct database query warnings (~80 warnings):**

The `Db.php` class is a data-access layer for custom tables. Every line flagged uses `$wpdb` directly — by design. The file already has many `phpcs:disable` comments for `InterpolatedNotPrepared`, but lacks coverage for `DirectQuery` and `NoCaching`.

**Fix strategy:** Add a class-level phpcs disable inside the class body, keeping per-method `InterpolatedNotPrepared` disables as-is:

```php
class Db {
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    // (per-method InterpolatedNotPrepared / NotPrepared disables remain surgical)
```

Also add `SchemaChange` to the `truncateNodes` (L387) and `truncateEdges` (L516) disables (TRUNCATE is a schema change).

Address the false-positive `SlowDBQuery` warnings for `meta_key` / `meta_value` (L587, 592–593): these queries target the plugin's **custom** `nvoos_graphify_meta` table, not `wp_postmeta`. Add a `phpcs:ignore` with a justification comment.

#### 4h. Other files with direct DB warnings

Each of these has 1–10 direct DB queries needing `DirectQuery` + `NoCaching` added to their existing `phpcs:disable` lists:

| File | Lines | Notes |
|------|-------|-------|
| `src/Tools/ResolveExternal.php` | 273 | Already has `InterpolatedNotPrepared`; add `DirectQuery`, `NoCaching` |
| `src/Graph/Exporter.php` | 455 | Already has `InterpolatedNotPrepared`; add `DirectQuery`, `NoCaching` |
| `src/Graph/Report.php` | 74 | Already has `InterpolatedNotPrepared`; add `DirectQuery`, `NoCaching` |
| `src/Graph/Analyzer.php` | 86–87, 345, 427, 433, 441–442, 480 | Multiple blocks need `DirectQuery`, `NoCaching` added |
| `src/Graph/Builder.php` | 145 | Already has `InterpolatedNotPrepared`; add `DirectQuery`, `NoCaching` |
| `src/Admin/RemoteAdmin.php` | 192 | Already has `InterpolatedNotPrepared`; add `DirectQuery`, `NoCaching` |

#### 4i. Required outcome for category 4

- `wp plugin check nvoos-graphify` reports **zero errors**.
- Any remaining warnings are either fixed or documented with a narrow, justified `phpcs:ignore`.
- readme.txt passes the WordPress.org readme parser without errors.

---

### 5. Correct public documentation that overstates shipped behavior

| Document | Claim | Reality | Fix |
|----------|-------|---------|-----|
| `readme.txt` L44 | "13 endpoints" | Controller registers **14** routes | Update to 14 |
| `CHANGELOG.md` L37 | "13 endpoints" | Same | Update to 14 |
| `src/Rest/README.md` L49 | `POST /webhook/{slug}` | Code registers `POST /webhooks/{slug}` (plural) | Fix the README path |
| `src/Remote/README.md` L39 | "Seven built-in drivers: Wikidata, GenericRest, RssSitemap, Sparql, WooCommerce, Csv, Webhook" | The Webhook driver class does not exist in this repo. Other drivers may be addon-only. | Audit which drivers actually ship; label addon-only drivers as such |
| `src/Remote/README.md` L40 | "HttpClient wraps wp_remote_get/wp_remote_post with SSRF-safe defaults" | The current code does not provide SSRF protections | Either implement SSRF safeguards or remove the claim |
| `src/Remote/README.md` L41 | "Remote-source credentials are encrypted at rest via Crypto" | Verify this is implemented | If the Crypto class exists and is called, the claim is valid; if not, remove it |
| `readme.txt` L20 | YouTube placeholder `DEMO_VIDEO_ID` | Placeholder in release text | Replace with real video ID or remove the YouTube embed |
| `README.md` (root) | Links to `../.context/` and `../CLAUDE.md` | These paths resolve relative to the main plugin repo root, not the graphify plugin root | Fix relative links or remove if the root README won't ship with the plugin |
| `readme.txt` L46 | "14 built-in tools" | Several tools have fatal method-call bugs (see item 2a) | Fix the tool bugs before claiming the count; qualify with "14 built-in tools (core only, AI addon available)" |

**Required outcome:**
- Public docs describe only code that ships in **this** repo.
- Optional or addon-only features are labeled as optional or addon-only.
- Broken links and placeholders are removed before release.

---

### 6. Make the test suite release-ready

The unit suite passes, but the integration suite fails in a configured WordPress test environment.

| Issue | Fix |
|-------|-----|
| `RestApiTest` extends the wrong base class | Change to extend `WP_UnitTestCase` and call `parent::setUp()` |
| Write-permission tests don't match controller's actual 401/403 behavior | Align assertions with the controller's actual response codes |
| Webhook tests run without ensuring plugin tables exist | Add table-setup fixture or skip guard |
| Unit suite leaves global `$wpdb` in a broken state for later tests | Reset or isolate `$wpdb` between test classes |
| No CI test-installation script | Add a `test:install` Composer script equivalent to the base plugin's |

**Required outcome:**
- `composer test` runs cleanly in the documented test environment.
- The repo explains how to install or point to the WordPress test library.
- Test failures block release work (full CI workflow optional for now, but the suite must be green).

---

## Should Fix For Professional Growth

These items are not hard blockers for `1.0.0` submission but directly affect release confidence, long-term maintenance, and team scalability.

### 7. Add CI gates

The repo should not depend on local manual checks.

| Gate | Tool |
|------|------|
| PHP lint + code style | `composer lint` (PHPCS) |
| Unit + integration tests | `composer test` (PHPUnit) |
| Plugin Check | `wp plugin check nvoos-graphify` (or the Plugin Check GitHub Action) |
| Static analysis | PHPStan or Psalm (would have caught the method-name fatals from item 2a) |
| Vendor asset verification | `npm run install:vendor` or equivalent checks that committed `assets/vendor/` matches `package.json` |

### 8. Improve test coverage where failures would be expensive

After the suite is green, prioritize:

- One `execute()` smoke test for every registered tool
- Integration tests for the graph build pipeline (Detector → Extractor → Db)
- Settings sanitization tests (especially the post-type and external-table sanitizers)
- Webhook HMAC validation tests
- Real database tests for `Graph\Db` query and upsert behavior (not mocks)

### 9. Make releases reproducible

| Item | Action |
|------|--------|
| `composer.lock` + `package-lock.json` | Ensure both are committed for source releases |
| Vendor JS rebuild | Document which direct and transitive npm dependencies produce `assets/vendor/` |
| CI verification | Add a CI step that verifies committed vendor assets match the lockfile inputs |
| Release workflow | Add a tag-based GitHub Action that runs all gates, builds the ZIP via `.distignore`, and optionally deploys to WordPress.org SVN |

### 10. Reduce scale and maintenance risk

| Item | Description |
|------|-------------|
| Unbounded graph scans | Replace `posts_per_page => -1` graph build scans with batched queries |
| Script handle collisions | Namespace frontend script handles (e.g., `cytoscape` → `nvoos-graphify-cytoscape`) |
| Cron cleanup on uninstall | Clear the `nvoos_graphify/initial_build` cron event during uninstall (currently only `cron_build` and `cron_enrich` are cleared) |
| Inline admin JS | Move large inline admin JavaScript blocks into enqueued files |

---

## Decision Points

These choices affect multiple items above and should be settled before implementation begins.

| Decision | Options | Recommended |
|----------|---------|-------------|
| **A:** Version number alignment | (1) Change plugin header + constant from `1.0.0-dev` to `1.0.0`. (2) Change readme stable tag from `1.0.0` to `1.0.0-dev`. | **Option 1.** A stable release should not carry a `-dev` version. This also fixes the Stable Tag mismatch. |
| **B:** Snake_case vs camelCase in Db methods | (1) Fix tool callers to use existing camelCase methods. (2) Rename Db methods to snake_case project-wide. | **Option 1.** CamelCase matches the rest of the codebase and the PSR-4 convention. Less surface area changed. |
| **C:** Embeddings column name | (1) Rename `vector` → `embedding_vector` or `vector_data`. (2) Quote `vector` everywhere but keep the name. | **Option 1.** Renaming eliminates the keyword conflict at the schema level and is safer for future DB versions. |
| **D:** Db.php DirectQuery warnings | (1) Class-level `phpcs:disable`. (2) Per-method surgical ignores. | **Option 1.** The entire file is a dedicated data-access layer. Class-level is simpler and equally honest. Keep per-method `InterpolatedNotPrepared` disables for the table-name interpolation pattern. |

---

## Summary of Files to Modify

| File | Changes | Severity |
|------|---------|----------|
| `readme.txt` | Header, tags, tested-up-to, stable tag, short description, endpoint count, placeholder removal | **ERROR** |
| `nvoos-graphify.php` | Version `1.0.0-dev` → `1.0.0` (Decision A) | **ERROR** |
| `src/Admin/Section.php` | Rewrite 5 echo blocks to use `printf()` with explicit `esc_attr()` | **ERROR** |
| `src/Graph/Db.php` | Fix spread operator bug L348 + add class-level phpcs disable + SchemaChange for truncate methods | **BUG** |
| `src/Admin/SettingsPage.php` | L180-181: use `wp_get_referer()` | WARNING |
| `src/Admin/Sections/SourcesCptsSection.php` | L91, 98: add NonceVerification to phpcs:ignore | WARNING |
| `src/Admin/Sections/SourcesExtSection.php` | L91, 98: add NonceVerification to phpcs:ignore | WARNING |
| `uninstall.php` | Add file-level phpcs:disable for DirectQuery/NoCaching/SchemaChange | WARNING |
| `src/Tools/ResolveExternal.php` | L273: add DirectQuery/NoCaching to disable list | WARNING |
| `src/Graph/Exporter.php` | L455: add DirectQuery/NoCaching to disable list | WARNING |
| `src/Graph/Report.php` | L74: add DirectQuery/NoCaching to disable list | WARNING |
| `src/Graph/Analyzer.php` | Multiple blocks: add DirectQuery/NoCaching | WARNING |
| `src/Graph/Builder.php` | L145: add DirectQuery/NoCaching to disable list | WARNING |
| `src/Admin/RemoteAdmin.php` | L192: add DirectQuery/NoCaching to disable list | WARNING |
| Tool files (GetNode, GetNeighbors, QueryGraph, GraphStats) | Fix method calls to use camelCase | **FATAL** |
| SyncRemoteSource tool + REST sync route | Fix `sync_source()` → `syncSource()` | **FATAL** |
| REST Controller `receiveWebhook()` | Guard missing Webhook driver class + fix Crypto namespace | **FATAL** |
| `Remote\Enricher::syncSource()` + admin AJAX | Return real results or clear error, not false success | **BUG** |
| `src/Graph/Db.php` (embeddings) | Rename `vector` column or quote all references (Decision C) | **SCHEMA** |
| `src/Rest/README.md` | Fix webhook endpoint path, endpoint count | DOCS |
| `src/Remote/README.md` | Audit claims about drivers, SSRF, encryption | DOCS |
| `CHANGELOG.md` | Fix endpoint count (13 → 14) | DOCS |
| Test files | Fix base class, assertions, table fixtures, global state | TESTS |
| `composer.json` | Add `test:install` script | CI |
| `.github/workflows/` | Add CI workflow for lint, test, Plugin Check (optional for `1.0.0`) | CI |

---

## Definition of Done

The `1.0.0` release is ready when:

1. Every **Must Fix** item (1–6) is complete.
2. `wp plugin check nvoos-graphify` reports **zero errors**; remaining warnings are documented and justified.
3. `composer lint` passes with zero errors.
4. `composer test` passes with zero failures.
5. Public documentation matches the shipped code exactly.
6. The release ZIP can be reproduced from the repository.
