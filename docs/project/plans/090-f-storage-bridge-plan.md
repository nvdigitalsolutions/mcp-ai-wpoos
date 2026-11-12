# Proposal 090-F — Storage bridge: make the content port trustworthy

Status: Phase 1 shipped (#7635, #7636); Phase 2 pre-req (Laravel SchemaStore) shipped.
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
4. **`perPage`** — 1–100, default 20 when 0. A **negative** value throws:
   `WP_Query` uses `-1` for "return everything", and clamping that to one row
   would be worse than failing.
5. **`statuses: ['any']`** — disables the status filter, matching
   `post_status => 'any'`. Translated literally to `whereIn()`, it would
   match zero rows because no record stores the literal status `any`.
6. **`search`** — title **and excerpt** and content, matching `WP_Query`'s
   `s`. Matching two of the three silently narrows the result set.

The last two came from reading `lib/wordpress-adapter/src/Adapter/ContentStore.php`
as the reference: it is deliberately a thin pass-through to `WP_Query`, so its
argument map is the best statement of what the contract means.

Acceptance:

- [ ] Parity test per compare operator, asserting rows and **counts**.
- [ ] A clause with no matches returns zero rows (not all rows).
- [ ] `EXISTS` / `NOT EXISTS` match rows with and without the key.
- [ ] Tax `IN`, `NOT IN` and `AND` return the same sets as `WP_Query`.
- [ ] `orderBy('date')` orders by `created_at`; unknown fields throw.
- [ ] `statuses: ['any']` returns every status.
- [ ] `search` matches an excerpt-only hit.
- [ ] Existing round-trip and adapter suites stay green.

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

### Phase 3 — Paper Store port

`PaperStoreInterface` with `collections()`, `find()`, `query()`,
`save()`, `delete()`; a JSON adapter using atomic temp-file + rename under a
configured root, with path containment and `schemaVersion` stamping.

### Phase 4 — OKF bundles

Move the parser/reader to a pure core engine with an injected bundle root;
the WP adapter keeps `wp_upload_dir()` as the root source.

### Phase 5 — Schedules

A narrow schedule store over `nvoos_schedules` (already migrated), so
schedule reads return the same shape on both runtimes.

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
