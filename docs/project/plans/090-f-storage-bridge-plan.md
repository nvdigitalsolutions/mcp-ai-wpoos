# Proposal 090-F — Storage bridge: make the content port trustworthy

Status: Phase 1 shipped (#7635, #7636) and its acceptance gates verified (2026-11-13, 104 adapter tests); Phase 2 pre-req (Laravel SchemaStore, #7637) and the family read path shipped; Phase 3 shipped (2026-11-13, 18 core + 10 app tests); Phase 5 shipped (missing runtime schema + test isolation); Phase 4 moved to Proposal 145.
Depends on: `090-d-selective-tool-port.md` (what to port), `090-e-cpt-cct-schema-port.md` (which families).

---

## 1. The gap, measured

`lib/core` already ships the right ports. `ContentStoreInterface` covers
CPT-shaped records (`find`, `query`, `create`, `update`, `delete`,
`getMeta`, `getTaxonomyTerms`, `userCanAccess`), `ContentQuery` already
carries `metaQuery` and `taxQuery` in the `WP_Query` clause shape, and three
adapters implement it:

| Adapter | Implements the port | Honours `metaQuery` | Honours `taxQuery` |
|---|---|---|---|
| `lib/wordpress-adapter` | yes | ✅ passes `meta_query` to `WP_Query` | ✅ passes `tax_query` |
| `lib/laravel-adapter` | yes | ❌ ignored | ❌ ignored |
| `lib/craft-adapter` | yes | ❌ ignored | ❌ ignored |

**This is the real blocker.** 654 of the 1,432 remaining tools are CPT/CCT
shaped (`090-e` §1). A Laravel adapter that accepts a `ContentQuery` with a
meta clause and silently returns *every* row is worse than no port at all:
the tool runs, the envelope is well-formed, and the answer is wrong.

Two smaller fidelity gaps in the same method:

- `orderBy` is passed straight to SQL. `ContentQuery` documents
  `date` / `modified`, but the Laravel table has `created_at` / `updated_at`,
  so `ORDER BY date` is an SQL error.
- `perPage` is not clamped to the documented 1–100 range.

## 2. What industry practice says

**Ports stay narrow and domain-owned.** In hexagonal architecture the
repository port belongs to the core, adapters hold every persistence detail,
and mapping between the domain object and the storage row is the adapter's
job — not the domain's
([Manning, *Clean Applications with Hexagonal Architecture*, ch. 5](https://www.manning.com/preview/clean-applications-with-hexagonal-architecture/chapter-5)).
We already follow this; the defect is an adapter that accepts half the port.

**Two adapter philosophies, and we are the second.** Directus wraps an
existing database and introspects it (no migration lock-in); Payload keeps
the model in code and lets the adapter talk to the chosen database
([comparison](https://www.glukhov.org/post/2025/11/headless-cms-comparison-strapi-directus-payload/)).
NV oOS keeps the model in the repo (JSON-Schema manifests) and adapts per
runtime — so the Laravel side must implement the same query semantics, not
a reduced subset.

**WordPress storage guidance.** Postmeta is fine for large datasets with
simple queries; dedicated tables win for high-frequency, transactional or
range-filtered data, and `wp_postmeta` grows much faster than `wp_posts`
([Remkus de Vries](https://remkusdevries.com/custom-database-tables-in-wordpress/),
[Synct Collective](https://synctcollective.com/wordpress-database-schema-cpt-optimization-how-to-prevent-scale-bloat/)).
Our `nvoos_posts.meta` JSON column sits between the two: one document per
record, queryable through JSON paths. That is the right default for CRUD
families, with a documented escape hatch to typed columns when a family
crosses the thresholds.

**File-backed stores need crash safety.** Write to a temp file in the target
directory, fsync, then rename; readers only ever see a complete file, and
stale `.tmp` files are cleaned up
([crash-safe JSON](https://dev.to/constanta/crash-safe-json-at-scale-atomic-writes-recovery-without-a-db-3aic),
[POSIX advisory lock + atomic rename](https://agents.stackoverflow.com/questions/7255a13f-4da9-4d62-bcc5-92b430c07549)).
This applies to Paper Store and OKF, which are file-backed.

**Schema evolution is expand → migrate → contract.** Embed a `schemaVersion`
in every document, version the manifest with SemVer, add fields before
reading them, and only drop old fields in a later release
([JSON Schema versioning](https://www.zerodatatools.com/blog/json-schema-versioning-guide/),
[expand-contract](https://tech-champion.com/database/backward-compatible-database-migrations-the-expand-contract-pattern-for-zero-downtime-releases/)).

## 3. Decision

Do **not** add a new god-store. Keep the four narrow domain ports and make
each one honest on both runtimes:

| Data shape | Port | Owner |
|---|---|---|
| CPT/CCT records (task, lead, activity, ECA…) | `ContentStoreInterface` + `ContentQuery` | this proposal, Phase 1–2 |
| Paper Store collections | `PaperStoreInterface` (new, narrow) | Phase 3 |
| OKF bundles | OKF engine + a bundle root setting | Phase 4 |
| Schedules | `ScheduleStoreInterface` (new, narrow) | Phase 5 |
| Assistants, teams, professions | `AssistantServiceInterface`, `TeamServiceInterface` (new), `ProfessionRepositoryInterface` | `090-g-identity-and-org-storage-plan.md` |

Identity and org data are out of scope here: an assistant is an entity, a team
is a membership graph and a profession is versioned reference data. They are
specified in `090-g`, with the same rule (one owner per domain, one contract
across runtimes).

A generic `RecordStoreInterface` is explicitly rejected: it would duplicate
`ContentStoreInterface` for CPT data and Paper Store's collection semantics
for documents, and every implementer would carry methods it cannot honour.

## 4. Phases

### Phase 1 — ContentStore fidelity (this PR)

Implement in `lib/laravel-adapter/src/Adapter/ContentStore.php`:

1. **`metaQuery`** — recursive WP clause translation over the JSON column:
   - `=` `!=` `>` `>=` `<` `<=` `LIKE` `NOT LIKE`
   - `IN` `NOT IN` `BETWEEN` `NOT BETWEEN` `EXISTS` `NOT EXISTS`
   - nested groups with `relation` `AND` / `OR`
   - `!=` includes rows where the key is absent, matching `WP_Query`'s
     documented behaviour. `NOT LIKE` keeps plain SQL semantics because
     WordPress's behaviour for absent keys is undocumented — not guessed.
2. **`taxQuery`** — `whereHas` / `whereDoesntHave` on the `terms` relation,
   honouring `taxonomy`, `field` (`term_id` / `slug` / `name`) and
   `operator` (`IN` / `NOT IN` / `AND`).
3. **`orderBy` mapping** — `date → created_at`, `modified → updated_at`,
   `title`, `id`; any other field **throws**. `ContentQuery` documents exactly
   four fields, and silently sorting by `created_at` would hide a caller bug
   (e.g. `rand`, or WP's `meta_value`).
4. **`perPage`** — 1–200, default 20 when 0. A **negative** value throws:
   `WP_Query` uses `-1` for "return everything", and clamping that to one row
   would be worse than failing. (200, not 100, because `list_tasks` documents
   a 200-row ceiling.)
5. **`statuses: ['any']`** — disables the status filter, matching
   `post_status => 'any'`. Translated literally to `whereIn()`, it would
   match zero rows because no record stores the literal status `any`.
6. **`search`** — title **and excerpt** and content, matching `WP_Query`'s
   `s`. Matching two of the three silently narrows the result set.

The last two came from reading `lib/wordpress-adapter/src/Adapter/ContentStore.php`
as the reference: it is deliberately a thin pass-through to `WP_Query`, so its
argument map is the best statement of what the contract means.

Acceptance:

- [x] Parity test per compare operator, asserting rows and **counts**.
- [x] A clause with no matches returns zero rows (not all rows).
- [x] `EXISTS` / `NOT EXISTS` match rows with and without the key.
- [x] Tax `IN`, `NOT IN` and `AND` return the same sets as `WP_Query`.
- [x] `orderBy('date')` orders by `created_at`; unknown fields throw.
- [x] `statuses: ['any']` returns every status.
- [x] `search` matches an excerpt-only hit.
- [x] Existing round-trip and adapter suites stay green.

*(All eight verified 2026-11-13 — 104 adapter tests. Writing the numeric-operator
parity test exposed a real bug: `>` bound the operand as text, and SQL orders
numbers before strings, so every decimal clause silently returned zero rows.
The adapter now casts both sides for `>`, `>=`, `<`, `<=`, `BETWEEN` and
`NOT BETWEEN`, **but only when the clause declares a numeric `type`** —
`WP_Meta_Query` casts only then, and untyped ordering must stay lexical for date
ranges. Both behaviours are pinned by tests.)*

### Phase 2 — First CPT family (project management)

**Pre-req shipped.** `SchemaStoreInterface` now has a Laravel adapter
(`lib/laravel-adapter/src/Adapter/SchemaStore.php`) reading
`oos.content_types`, `oos.content_statuses` and `oos.taxonomies`. It returns
the same `PostTypeSchema` / `TaxonomySchema` the WordPress adapter builds from
`register_post_type()`, so `getPostType('mcp_ai_task')` and
`postTypeSupports()` answer identically on both runtimes. Declaring the family
is a config change, not an adapter change.

Model `mcp_ai_task` + `mcp_ai_project` as one manifest (`090-e` §7 #1),
seed the Laravel `nvoos_posts` rows with `type` + `meta`, and rewire the
read tools to `ContentStoreInterface`. Writes follow in a second PR
(`090-e` §8: reads → writes → import → admin).

**Read path shipped.** The family is declared in `config/nvoos.php`
(`content_types` with the same labels, supports and meta keys as the CPTs,
including `_task_depends_on` / `_task_blocks`). Three tools now run on both
runtimes through `ContentStoreInterface`: `list_projects`, `list_tasks`,
`get_task_dependencies` — registered in the provider because they need the
store, not just the error factory. `ProjectManagementSeeder` gives a fresh
app one project and two tasks (the second depending on the first).

Portability note: `_project_assigned_to` is stored as an array of **string**
user IDs and filtered with `LIKE '%"<id>"%'`, which matches a JSON array
element and a PHP-serialized string element alike — the WP tool's serialized
`:'<id>';` pattern does not translate to a JSON column. The contract ("projects
assigned to user N") is identical; the encoding is documented in the schema.

Still to do for the family: create/update/delete, bulk import, and the admin
page.

**Write path shipped (tasks).** `create_task` and `delete_task` are now core
tools registered by the provider, so both runtimes write through
`ContentStoreInterface`:

- `create_task` mirrors the WordPress contract exactly — one slug that creates,
  and updates instead when `task_id` is supplied. Same parameter names, same
  `required`, same result fields (`task_id`, `task`, `updated`).
- `delete_task` takes `task_id` and reports the deleted id; an unknown id is an
  error, not a silent success.
- Validation is explicit: blank title, unknown status/priority/category and an
  impossible `due_date` (e.g. `2026-02-31`) each return a typed error rather
  than writing a half-valid row.
- **One projection.** `Tool\ProjectManagement\TaskRow` renders a task for both
  `list_tasks` and the write tools, so a read and a write can no longer present
different shapes. `list_tasks` now delegates to it.
- `AbstractTool::floatParam()` was added: non-numeric input falls back to the
default instead of casting to `0`, so "high" cannot silently become zero hours.

Remaining for the family: project create/update/delete, bulk import, the admin
page, and an `update_task` partial-update tool (today `create_task` requires a
title, matching WordPress).

**Project write path shipped.** `create_project` and `delete_project` follow the
same shape as the task pair:

- `create_project` creates, or updates when `project_id` is supplied. Status
defaults to `planning`; `end_date` may not precede `start_date`.
- `assigned_to` is persisted as an array of **string** ids — the encoding the
  read path's JSON `LIKE` filter matches (`090-e` §7). Ids are de-duplicated and
  non-numerics dropped on the way in, so `[1, "1", "x"]` stores `["1"]`.
  A supplied list **replaces** the previous one; it does not append.
- `Tool\ProjectManagement\ProjectRow` is shared with `list_projects`, and the
  parity test asserts a written project reads back identically.
- Deleting a project does **not** cascade to its tasks — `_task_project_id` is
  plain meta on both runtimes, and the WordPress tool behaves the same way. The
  behaviour is pinned by a test so a future cascade is a deliberate change.

Remaining for the family: bulk import and the admin page (writes now cover
tasks and projects), plus the `update_task` partial-update tool.

**Partial-update tools shipped.** `update_task` and `update_project` close the
last behavioural gap with WordPress. `create_task` / `create_project` are
upserts that require a title or name; these change only the fields supplied, so
a caller can mark a task `completed` without re-sending everything.

- **Nothing-to-update is an error**, not a silent no-op: an empty call returns
  `nothing_to_update` instead of reporting success for a write that did nothing.
- **Dates are validated against the merged result.** Changing only `end_date`
  still cannot invert a stored `start_date` — the failure a per-field check
  misses, and it has its own test.
- **Type-checked ids.** `update_project` refuses a task id (and vice versa) with
the same `not found` answer, so a mixed-up id cannot quietly rewrite the wrong
kind of record.
- Results carry the updated row, matching `create_*`. WordPress returns only
the id; the richer shape is a deliberate superset that saves a follow-up read.

`AbstractTool` gained `isIsoDate()` and `providedParams()` so the four write
tools share one date validator and one "was this field really supplied" rule
instead of four slightly different copies.

**Bulk import shipped.** `bulk_import` takes `entity` (`tasks` | `projects`), a
`rows` list, `preview` and `skip_existing`, and follows the toolkit import
contract (`090-e` §5):

- **The report reconciles.** `valid + duplicates + failed === total`, always —
  a row that passes validation but fails to persist is moved into `failed`
rather than silently vanishing from the count.
- **`preview` writes nothing** and returns the normalised rows, so a caller can
  show the operator what would happen before anything lands.
- **The natural key is the title** (or project name), compared case- and
  whitespace-insensitively. There is no external id on this family, so two rows
  with the same title in one batch are a duplicate — the honest consequence of
  having nothing else to tell them apart. Matching is exact after a database
  search narrows the field, because `ContentQuery::search` is a substring match
  and would treat "Alpha" as a duplicate of "Alpha Two".
- **500 rows per call**, matching the toolkit import.

`ProjectManagement\Vocabulary` now holds the status/priority/category lists.
They were duplicated across four tools, which is exactly how a schema and its
validator drift apart.

**Admin surface shipped.** The family's `/pm` console page was blocked on two
small pieces, both fixed here:

- `Wp\ContentController` now maps the `tasks` and `projects` REST bases to
  `mcp_ai_task` / `mcp_ai_project`. The console's Records page addresses records
  through `wp/v2/{restBase}`, so without these two entries it got a
  `rest_no_route` 404 for a post type the runtime already stores.
- `Pro\RecordsController::ENTITIES` advertises both entities under group `pm`,
  which is what the Records page reads to decide what to render.
- `oos.features.project_management` flips to `true`, so the console nav shows
  `/pm` at all.

Writes from the page carry meta, so `_task_status` and friends round-trip
through the same `nvoos_posts` rows the tools read.

**Phase 2 is complete**: reads, writes, partial updates, bulk import and the
admin page all run on both runtimes.

### Phase 3 — Paper Store port

`PaperStoreInterface` (`lib/core/src/Domain/Contract/`) with
`collections()`, `has()`, `find()`, `query()`, `save()`, `delete()`; a
`JsonPaperStore` (`lib/core/src/Infrastructure/PaperStore/`) writing
`{root}/{collection}/{id}.json` — the layout WordPress already uses, so one
directory serves both runtimes.

Design decisions worth keeping:

- **Atomic replacement.** The payload is written to a temporary file in the
destination directory and renamed over the target. `rename()` replaces the
target on POSIX *and* Windows, so a reader sees the old record or the new one,
never a partial file.
- **Reject, do not normalise.** Collection names and ids must match
  `^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$`. Anything with a separator, a drive letter
  or `..` throws; silently rewriting a caller's id would turn a traversal
  attempt into a successful write somewhere else. The API routes carry the same
  pattern as a route constraint, so a bad segment is a 404 before it reaches the
  store.
- **The store owns its stamps.** `id` (slugged from the title when absent),
  `collection`, `type` (defaults to the collection), `schemaVersion`,
  `created_at` and `updated_at` are assigned on write. `created_at` is preserved
  from the existing record on replace; a caller-supplied value is ignored.
- **`query()` returns `{records, total}`.** A flat-file store reads the
  candidate set to filter it, so returning the count from the same pass avoids
  doing the work twice for every page.
- **Unknown `order_by` throws**, and it throws *before* the empty-collection
  shortcut — otherwise the same caller bug would be silent on a fresh install
  and loud once data existed.

Acceptance:

- [x] Save stamps `id`, `collection`, `type`, `schemaVersion`, `created_at`,
      `updated_at`; a replacement keeps `created_at`.
- [x] A missing title throws; a traversal id or collection throws.
- [x] Records are written as pretty-printed JSON with `schemaVersion: 1`.
- [x] `collections()` lists only directories that hold records.
- [x] `query()` filters by tag, status and search, pages with `limit`/`offset`,
      reports the full total, and orders by `updated_at` desc by default.
- [x] An unknown `order_by` throws even when the collection is empty.
- [x] `delete()` reports the second delete as absent.
- [x] The API keeps its existing response shapes; a record created through
      `POST /api/v1/paper-store/{collection}` lands on disk in the shared layout.

*(Verified 2026-11-13 — 18 `JsonPaperStoreTest` cases in `lib/core`, 10
`PaperStoreTest` cases in the app. The routes previously pointed at
`nvoos_paper_records`, a table **no migration ever created**, so every Paper
Store call failed; the store replaces that dead path rather than adding a second
backend.)*

### Phase 4 — OKF bundles

Move the parser/reader to a pure core engine with an injected bundle root;
the WP adapter keeps `wp_upload_dir()` as the root source.

**Deferred — and why.** OKF has no Laravel consumer: the console builds `endpoints.okf` as
`isWordPress ? ... : ''` and nulls it when the feature flag is off, and the runtime exposes no OKF
route or tool. Two things also make "move" the wrong verb:

1. **Licence boundary.** `includes/okf/` is GPL-3.0 and `lib/core` is MIT. The parser (817 lines)
   and reader (808 lines) cannot be copied across that line — they would have to be re-authored
   from the format description, which is a project in its own right, not a refactor.
2. **Nothing to prove it against.** Without a Laravel surface there is no parity test to write, so
   an extraction would be verified only by the WordPress suite that already covers it.

Revisit when a Laravel surface needs OKF (bundle browsing in the console is the likely trigger).
At that point the honest shape is a clean-room core parser with the existing WP tests as the
specification, not a file move.

**Superseded.** [`145-okf-core-extraction-and-licence-proposal.md`](../proposals/145-okf-core-extraction-and-licence-proposal.md)
revises that conclusion: the OKF code is first-party, so relicensing is available and a clean-room
rewrite is unnecessary. The blocker is the WordPress coupling and the parser/reader having no
automated tests, so proposal 145 replaces Phase 4 with a characterised port (golden corpus →
differential test → licence change). Track the work there.

### Phase 5 — Schedules

**Premise corrected.** The plan said `nvoos_schedules` was "already migrated". It was not —
and neither were seven other tables the app queries. Every one existed only in the dev database:

| Table | Used by |
|---|---|
| `nvoos_schedules`, `nvoos_schedule_runs` | Pro Schedule Manager |
| `nvoos_triggers` | Triggers |
| `nvoos_approvals` | Approval queue |
| `nvoos_files` | Media / FileStore |
| `nvoos_posts` | Content store |
| `nvoos_security_snapshots` | Security center |
| `nvoos_toolkit_records` | Toolkit records |

A fresh `php artisan migrate` on an empty database therefore produced an app whose Pro surfaces
failed at runtime with "table not found", while the dev box worked because the tables had been
created by hand at some point and never captured.

Shipped:

- **`2026_11_13_010000_create_nvoos_runtime_tables`** — all eight tables, read back from the live
  schema (`SHOW CREATE TABLE`) so nothing changes behaviourally, each guarded by
  `Schema::hasTable()` so installs that already have them record the migration without touching
  data. `nvoos_posts` carries both a unique index and a plain index on `slug`; only the unique one
  is recreated, because the second adds write cost for no lookup the unique index cannot serve.
- **`RuntimeSchemaTest`** — asserts every table plus the key columns and the toolkit-record unique
  identity. `RefreshDatabase` builds the schema from migrations alone, so this fails the moment a
  `DB::table(...)` call is added without a migration.

**Test isolation fix.** Making the Paper Store file-backed exposed a leak: `Phase3bTest`'s Paper
Store cases shared the default `storage/paper-store` directory, so records accumulated between
tests *and between runs* and `total` counts drifted (3 → 12 on the second run). The base
`Tests\TestCase` now gives every test its own root and removes it in `tearDown()`. The suite is
idempotent: two consecutive runs are both 179 passing.

Schedule shape parity is already covered by `Phase3cTest`, so no separate store interface was
extracted — the missing piece was the schema, not the abstraction.

Acceptance:

- [x] Every table the app queries is created by a migration.
- [x] The migration is a no-op on an install that already has the tables.
- [x] Key columns and the toolkit-record unique index are asserted.
- [x] The full suite is green on a schema built from migrations only, twice in a row.

*(Verified 2026-11-13 — 179 app tests, 1341 assertions.)*

## 5. Guardrails

1. **One owner per record.** Never mirror a CPT family into Paper Store or
   vice versa.
2. **No silent filter drops.** An unsupported clause must either be
   translated or make the query fail loudly; returning unfiltered rows is a
   bug class, not a limitation.
3. **Counts are part of the contract.** `total` comes from the filtered
   query, never from an unfiltered count.
4. **JSON paths are user input.** Meta keys are validated against
   `[A-Za-z0-9_.-]+` before they reach a JSON path.
5. **Escape hatch is explicit.** A family that outgrows the JSON column gets
   typed columns and a migration — decided per family in `090-e`, not
   globally.
