# Proposal 149 — toolkit manifest renderer (track P4)

**Parent:** [`149-laravel-admin-parity-implementation-plan.md`](./149-laravel-admin-parity-implementation-plan.md) §46
**State:** design, **step 1 (engine extraction) shipped 2026-11-17** — see §1a — and **the renderer shipped 2026-11-18** as plan §56 (`/admin/toolkits`, contract row `toolkits: subset`), followed by the **first authoring pass (12 manifests)** and **Wave 4 (research + consolidate) as plan §57** — all five manifest screens are now routed. **Remaining:** the 10 toolkits that persist nothing (WordPress-side fix first), the committed settings-page ↔ manifest map, and create/edit on the panel. **Unblocks:** order item **10** (`toolkits`, `chat-channels`, and the 45 Pro settings/CPT pages behind Wave 3).
**Why it runs in parallel with items 1–9:** it is the only remaining item that changes the *shape* of everything after it. Designing it late would serialise the whole plan.

## 1. Verified state

Already exists:

- **Schema** — `lib/core/src/Domain/ValueObject/ToolkitManifest.php` validates a closed
  vocabulary: `screens` ∈ `settings|entities|research|consolidate|dashboard`;
  setting types `text|number|boolean|select|secret`; column types
  `string|number|currency|boolean|date|enum`; aggregates `count|sum|avg|min|max`;
  filter ops `eq|neq|lt|lte|gt|gte|like`.
- **Loader** — `App\Support\Pro\ToolkitManifestLoader` reads `resources/pro-toolkits`
  (`NVOOS_TOOLKIT_MANIFESTS` override), validates, and **fails closed**: an invalid
  document is skipped and reported, never served.
- **HTTP engine** — `/api/v1/pro/toolkits/...` serves list, show, settings (GET/POST),
  research, dashboard, and entity CRUD/import/duplicates. Entitlement is per manifest
  `feature()`.
- **Entity storage** — `nvoos_toolkit_records`.

Missing:

| Gap | Evidence |
|---|---|
| ~~**No Filament renderer**~~ | **Shipped 2026-11-18 (plan §56):** `ToolkitsPage` + `ToolkitPage`, contract row `toolkits: subset` |
| ~~**The 7-tab settings page is not expressible**~~ | **Addressed 2026-11-18:** optional `tab_labels` map + per-setting `tab`, all-or-nothing; `ToolkitManifest::settingTabs()` groups for any renderer. Sections *within* a tab are still not expressible — no manifest needs one yet |
| **9 of 22 manifests missing** | **12 exist** (`commerce`, `documents`, `crm`, and nine from the 2026-11-18 authoring pass). The pass stopped at 8 of 25 toolkit settings pages because 10 of them never persist what they render and 2 render nothing — see `149-toolkit-manifest-schema-check.md` §9c |
| ~~**No manifest lint**~~ | **Shipped 2026-11-18:** `bin/audit-admin-manifests.php` (validation, slug/filename, servable screens) wired into `laravel-app.yml`. The settings-page ↔ manifest mapping check waits on the authoring pass |
| ~~No in-process engine~~ | **Shipped 2026-11-17** (§1a): `ToolkitEngine` + `ToolkitException`; `ToolkitsController` is a thin adapter |

## 1a. Step 1 shipped — and the blocker it exposed

**Shipped 2026-11-17:** `App\Support\Pro\ToolkitEngine` owns manifest resolution,
settings read/write, entity CRUD, dashboard aggregation, duplicates and import;
`ToolkitException` carries the typed refusal (`errorCode`/`status`/`fields`);
`ToolkitsController` is now a thin HTTP adapter over both. `ToolkitContractTest`
and `ToolkitResearchTest` pass **unchanged** (18 tests), and
`ToolkitEngineTest` adds 5 that drive the engine with no request at all.

**Blocker found while extracting:** `nvoos_toolkit_records` has **no `tenant_id`**.
Its schema is `toolkit`, `entity`, `external_id`, `data`, timestamps, with a
**globally unique** `(toolkit, entity, external_id)` and an index on
`(toolkit, entity)`. The engine filters on the last two only, and the routes
already sit inside `ResolveApiTenant` — so the estate is resolved and then
ignored. That is the same shape as §19, §27, §39, §40 and §41, and it would make
the renderer a sixth instance:

- two estates cannot both hold `SKU-1` in `commerce` (the unique key collides);
- a panel table would list every estate's records, not the current one's;
- `duplicates` and `import` would group and reject across estates.

**The renderer must not ship before this is scoped.** The prerequisite is its own
slice, in the §27/§39 pattern:

1. add `tenant_id` (backfill existing rows to `default` — no signal attributes
them elsewhere);
2. replace the unique with `(tenant_id, toolkit, entity, external_id)` — adding
the column alone converts a silent collision into a constraint failure;
3. put the scope in **one** `query()` helper in `ToolkitEngine` that every method
starts from, so a new method cannot forget it;
4. `import`'s duplicate check and `findRow` go through that helper;
5. `ToolkitContractTest` gains a cross-estate case (a foreign record is absent
   from a list, and a foreign id answers "not found").

**Prerequisite shipped 2026-11-17** as plan §47: all five steps landed, and the
cross-estate cases live in `ToolkitTenancyTest` (6 tests / 42 assertions) rather
than `ToolkitContractTest`, because the engine — not the HTTP adapter — is where
both the API and the panel read and write. See plan §47 for the migration, the
one-helper rule and the validation record. The tab schema and the renderer are
now unblocked.

## 2. Decision 1 — extract the engine (one writer)

The panel must not become a second writer. Recommended shape, in this order:

1. Move the controller's body into `App\Support\Pro\ToolkitEngine` (settings read/write,
   entity CRUD, dashboard aggregation, research draft), taking slug + manifest.
2. Leave `ToolkitsController` as a thin HTTP adapter over it — the console's contract is
   unchanged, so its tests and the console smoke stay honest.
3. The Filament renderer calls the same engine.

Rejected: the panel calling its own HTTP API. It adds a network hop, a loopback auth
problem (Sanctum cookie vs internal request), and two failure surfaces for one write —
exactly the "two writers" the contract forbids.

## 3. Decision 2 — settings tabs, non-breaking

Add an **optional** `tab` string to each settings field and an optional `tab_labels`
map on `settings`. A manifest without either renders one "General" tab, so `commerce`
and `documents` keep validating unchanged. A breaking `settings: {tabs: [...]}` reshape
is rejected: it would invalidate both committed manifests and the console's settings
screen for no gain.

## 4. Decision 3 — the renderer's shape

- **Route:** `/admin/{tenant}/toolkits/{slug}` with a screen segment
  (`/settings`, `/entities/{entity}`, `/dashboard`, and — since Wave 4 — `research`
  and `consolidate`). **Amendment 2026-11-18:** all five are routed. The two Wave 4
  screens are entity-scoped, so a settings-only manifest 404s them rather than
  rendering an empty wizard; that is what makes routing all five safe.
- **Entitlement:** `pro.entitlement:{feature}` on the route group, matching the HTTP
  side. An unlicensed toolkit is **absent from the nav**, not a rendered 403.
- **Access:** `manage_options` plus panel membership (the same rule as remote-sites and
  MCP apps); the REST group already requires it.
- **Writes:** every mutation goes through `ToolkitEngine`; each writes one
  `AdminAuditLogger::recordResource('toolkits', {slug}:{entity}:{id}, …)` row, because
  Livewire traffic is excluded from the request audit.
- **Secrets:** `secret` setting types render write-only (empty on load, blank keeps the
  stored value) — the `EstateResource`/`McpAppsPage` rule.
- **Tables:** `entities` render from the manifest's columns; `enum` columns use the
  manifest's option map. No hand-written table per family.

## 5. Decision 4 — the lint

`bin/audit-admin-manifests.php`, run in CI:

1. every `manifests/*.json` validates through `ToolkitManifestLoader`;
2. every Pro toolkit that ships a settings page has a manifest (map
   `class-wp-mcp-ai-*-settings-page.php` / `*-toolkit-settings-page.php` to slugs; the
   mapping table is committed, not inferred);
3. no manifest declares a screen no surface can serve. **Since Wave 4 the
   console-only allowance is empty** — every screen is panel-servable, so an unknown
   screen fails outright instead of being excused as "later".

## 6. Order item 10 — acceptance

- [x] `ToolkitEngine` extracted; `ToolkitsController` delegates; its existing tests pass
      unchanged. **Done 2026-11-17.**
- [x] `nvoos_toolkit_records` is estate-scoped (`tenant_id`, composite unique, one
      `query()` helper) and a cross-estate test pins it — **blocker §1a**, shipped
      as plan §47.
- [x] `commerce` renders **settings, entities and dashboard** from data, with no
      family-specific PHP. **Shipped 2026-11-18 (plan §56):** one parameterised page
      renders every manifest; no toolkit name appears in the PHP or the views.
- [x] Adding a family is a manifest commit and nothing else — proven by adding one
      (recommend `documents`, which already has a manifest, then one new toolkit).
      **Proven in `AdminToolkitsTest`:** a fixture manifest (`workshop`) renders a
      tabbed settings screen, an entity workbench and a dashboard card with zero
      code changes beyond the manifest file.
- [x] Settings tabs render from `tab`/`tab_labels` with a single-tab fallback.
      **Shipped in `ToolkitManifest::settingTabs()`** so the grouping is shared, not
      re-implemented per renderer.
- [~] `bin/audit-admin-manifests.php` fails on an invalid manifest and on a toolkit
      settings page with no manifest; runs in `php-linting.yml` beside the admin-surface
      coverage job. **Shipped:** validation, slug/filename match, and unserved-screen
      detection, wired into `laravel-app.yml`. **Deferred:** the settings-page ↔
      manifest mapping check — the authoring pass showed the mapping is not
      "one manifest per settings page" (10 pages persist nothing, 2 render no
      controls), so the committed map has to come from §9b/§9c of the check doc
      rather than from filenames.
- [x] `toolkits` moves off `planned`, and so does `chat-channels` once its manifest
      exists. **Both are `subset`** — the panel lists/searches/deletes records
      while create/edit remain console-side (§56.4), and `chat-channels` renders
      its four persisted settings with the credential panels projected out.
- [x] Audit rows for settings and entity writes; a diff never carries a `secret` value.
      Keys only: the engine masks secrets on read, so a secret change is reported as a
      changed **name**, never a value.

## 7. Non-goals

- **Do not port the 45 Pro settings/CPT pages individually.** They are the data this
  engine consumes; porting them one by one is the failure mode Wave 3 exists to avoid.
- **Do not extend the schema beyond `tab`/`tab_labels`.** Column types, aggregates and
  filter ops already cover the catalogue; a bigger schema is a bigger validation surface
  for no known toolkit.
- ~~**Do not build `research`/`consolidate` screens here.**~~ **Shipped 2026-11-18
  (plan §57).** They are entity-scoped and share the manifest's own column schema,
  so they belong on this page rather than on a second pair of hand-written pages.
  The rule that survives is: a screen whose data the engine does not own stays
  console-only, because a manifest cannot render rows it cannot reach.

## 8. Open question — answered 2026-11-18

The 25-manifest authoring pass assumes the WordPress toolkit settings pages' fields map
cleanly to the five setting types. One domain should be checked before the bulk
(recommend `crm`, which has enum-heavy settings) — if a toolkit needs a field type the
schema lacks, that is a schema decision to make **once**, before 23 manifests are
written against the wrong shape.

**Answered:** [`149-toolkit-manifest-schema-check.md`](./149-toolkit-manifest-schema-check.md).
The CRM inventory found one missing type (`textarea`, ten fields: newline lists and
free text) which **shipped** — optional `rows` 2–20, rendered by both the panel and the
console. Two exclusions are recorded rather than implemented: dynamic select options
(three fields read remote-site connections or assistants from another store) and
sections within a tab. Nested groups map to flattened keys plus `tab_labels`. CRM is
expressible with the six types, so the authoring pass is unblocked.

**`crm.json` shipped 2026-11-18 as the worked reference** (66 settings / 10 tabs,
settings-only). Writing it exposed one more assumption: `entities` was required, so a
settings-only toolkit could not exist. Omitted `entities` is now valid (an explicitly
empty list is still rejected), and `ToolkitContractTest` skips the entity lifecycle
for such a family. The recipe for the remaining manifests is §7b of the check, and the
first pass' results — including the ten pages that persist nothing — are §9 there.
