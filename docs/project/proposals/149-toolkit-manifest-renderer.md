# Proposal 149 — toolkit manifest renderer (track P4)

**Parent:** [`149-laravel-admin-parity-implementation-plan.md`](./149-laravel-admin-parity-implementation-plan.md) §46
**State:** design, and **step 1 (engine extraction) shipped 2026-11-17** — see §1a. **Unblocks:** order item **10** (`toolkits`, `chat-channels`, and the 45 Pro settings/CPT pages behind Wave 3).
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
| **No Filament renderer** | `app/Filament/Admin/` has no `Toolkits` surface; contract row `toolkits` is `planned` |
| **The 7-tab settings page is not expressible** | a manifest's `settings` is a **flat field list** (`key/label/type/default/description`); the archetype it replaces is 7 tabs |
| **23 manifests missing** | 2 exist (`commerce`, `documents`) against ~25 toolkits |
| **No manifest lint** | §11's Wave 3 exit gate promises `bin/audit-admin-manifests.php`; it does not exist |
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
  (`/settings`, `/entities/{entity}`, `/dashboard`); `research` and `consolidate` are
  Wave 4 and should not be routed yet.
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
3. no manifest declares a screen the renderer cannot serve yet (`research`,
   `consolidate` until Wave 4 — a declared-but-unserved screen is the "blank shell"
   problem).

## 6. Order item 10 — acceptance

- [ ] `ToolkitEngine` extracted; `ToolkitsController` delegates; its existing tests pass
      unchanged. **Done 2026-11-17.**
- [x] `nvoos_toolkit_records` is estate-scoped (`tenant_id`, composite unique, one
      `query()` helper) and a cross-estate test pins it — **blocker §1a**, shipped
      as plan §47.
- [ ] `commerce` renders **settings, entities and dashboard** from data, with no
      family-specific PHP.
- [ ] Adding a family is a manifest commit and nothing else — proven by adding one
      (recommend `documents`, which already has a manifest, then one new toolkit).
- [ ] Settings tabs render from `tab`/`tab_labels` with a single-tab fallback.
- [ ] `bin/audit-admin-manifests.php` fails on an invalid manifest and on a toolkit
      settings page with no manifest; runs in `php-linting.yml` beside the admin-surface
      coverage job.
- [ ] `toolkits` and `chat-channels` contract rows move off `planned` (`chat-channels`
      is toolkit-shaped and becomes reachable once a manifest declares it).
- [ ] Audit rows for settings and entity writes; a diff never carries a `secret` value.

## 7. Non-goals

- **Do not port the 45 Pro settings/CPT pages individually.** They are the data this
  engine consumes; porting them one by one is the failure mode Wave 3 exists to avoid.
- **Do not extend the schema beyond `tab`/`tab_labels`.** Column types, aggregates and
  filter ops already cover the catalogue; a bigger schema is a bigger validation surface
  for no known toolkit.
- **Do not build `research`/`consolidate` screens here.** Those are Wave 4 and share the
  `probe`/wizard renderers; routing them early would ship two blank screens.

## 8. Open question

The 25-manifest authoring pass assumes the WordPress toolkit settings pages' fields map
cleanly to the five setting types. One domain should be checked before the bulk
(recommend `crm`, which has enum-heavy settings) — if a toolkit needs a field type the
schema lacks, that is a schema decision to make **once**, before 23 manifests are
written against the wrong shape.
