# Proposal 090-G — Identity and org storage: assistants, teams, professions

Status: Phase 1 shipped. Phase 2 (profession catalogue) next.
Depends on: `090-f-storage-bridge-plan.md` (the content port), `090-b` §6 (assistant studio).

---

## 1. Why these are missing from the storage plan

`090-f` covers **records** — content families, Paper Store documents, OKF
bundles, schedules. Assistants, teams and professions are a different shape:

| Domain | What it is | Shape |
|---|---|---|
| Assistants | Mutable configuration entity with its own lifecycle and bundle export | Entity (one row + owned config) |
| Teams | A membership graph with a role per member | Relational graph |
| Professions | A curated, versioned catalogue (labels, knowledge base, recommended tools) | Reference data + an assignment |

Trying to force any of the three into `nvoos_posts` would repeat the
"one table for everything" mistake the content port avoids. They get their
own storage, but the same rule applies: **one owner per domain per
deployment, and one contract across runtimes**.

## 2. What exists today

| Piece | WordPress | Laravel |
|---|---|---|
| Assistant storage | CPT `mcp_ai_assistant` + postmeta | `assistants` table (app-level migration) |
| Assistant contract | `AssistantServiceInterface` (core) | `LaravelAssistantService` ✅ |
| Team storage | — | **none** (no model, no migration) |
| Team contract | — | **none** (`090-b` §6 task 2.5 `⏳`) |
| Profession storage | PHP service + knowledge-base loader | **none** |
| Profession contract | `ProfessionRepositoryInterface` (core) | **no adapter** |
| Profession pages | WP admin pages | Content Graph addon only |

**Two defects in the current `assistants` migration** (both only visible once
tenancy is considered):

```php
$table->string('slug')->unique();          // global — two tenants cannot both have "support-bot"
$table->boolean('is_default')->default(false)->index(); // global — every tenant would share one default
```

## 3. What industry practice says

**Roles are per resource, and assignments are tenant-aware.** The standard
shape is a `team_members` table carrying the role with
`UNIQUE (team_id, user_id)` and a constrained role set; a user can be admin
in one team and viewer in another. Even when roles themselves are global,
*assignments* must carry the tenant, or privilege bleeds across tenants
([WorkOS — RBAC for multi-tenant SaaS](https://workos.com/blog/how-to-design-multi-tenant-rbac-saas),
[SaaS RBAC 2026](https://viprasol.com/blog/saas-role-based-access/),
[Enterprise RBAC design](https://www.nirajkumar.in/blog/designing-enterprise-rbac-system)).

**Tenant ID belongs on every tenant-owned row, inside uniqueness.** The rule
is `tenant_id` indexed as the leading column, composite indexes for the
common `(tenant_id, …)` query, and `UNIQUE (tenant_id, slug)` rather than a
global unique — "slugs and emails collide across tenants by design"
([PostgreSQL SME Cookbook](https://postgresql.codeguides.io/multi-tenant-patterns/best-practices/),
[WorkOS multi-tenant guide](https://workos.com/blog/developers-guide-saas-multi-tenant-architecture),
[ClickHouse](https://clickhouse.com/resources/engineering/multi-tenant-saas-postgres-architecture)).

**Reference data is versioned and read-mostly.** A catalogue should expose an
explicit version, keep historical values (valid-from/valid-to or immutable
snapshots), and be served as a read-only holder rather than edited at
runtime ([Aerospike](https://aerospike.com/blog/reference-data-management/),
[Reference Data Holder pattern](https://microservice-api-patterns.org/patterns/responsibility/informationHolderEndpointTypes/ReferenceDataHolder)).

**Relational beats a JSON blob when you query the relationship.** Membership
is queried in both directions ("my teams", "who is on this team"), so it is a
pivot table. Assistant `tools` is read and written whole and never filtered
on, so JSON is the right call there — until a query needs it.

## 4. Design

### 4.1 Assistants — harden the existing table

Keep the dedicated table. It is an entity, not a content record; the CPT on
WordPress is an implementation detail behind `AssistantServiceInterface`.

| Change | Why |
|---|---|
| Add `tenant_id` (default `'default'`) | Required for scoping |
| `UNIQUE (tenant_id, slug)` replaces `slug UNIQUE` | Slugs may repeat across tenants |
| `is_default` becomes "one default per tenant" | Service-level, tenant-scoped invariant — see below |
| `index (tenant_id, status)` | The common listing query |
| `tools`, `meta` stay JSON | Read/written whole; no membership query today |

Expand → migrate → contract: add `tenant_id` nullable, backfill, add the
composite unique, drop the global one. No downtime, no rewrite.

**Why `is_default` is not a database constraint.** MySQL has no partial
unique index, and the portable nullable-unique variant would change
`is_default` from `false` to `NULL` for every non-default row — a semantic
change that leaks into the API shape. The invariant is therefore enforced in
the controller, scoped to the tenant, with a regression test; the schema
keeps the column for querying.

**Phase 1 shipped.** Migration `2026_11_12_090000_add_tenancy_to_assistants_table`
adds the column, backfills existing rows, swaps the unique key and adds the
index. The controller scopes the listing, the slug-collision check and the
default-clearing write; `tenant_id` is not mass-assignable, so a request body
can never choose its tenant. `AssistantTenancyTest` covers the same slug in
two tenants, the same slug rejected within one tenant, a per-tenant collision
suffix, and a default in tenant B surviving tenant A's default.

### 4.2 Teams — a small relational graph

```text
teams            id, tenant_id, name, slug, description, timestamps
                 UNIQUE (tenant_id, slug), index (tenant_id), soft deletes

team_members     id, team_id, user_id, role, profession_slug (nullable),
                 invited_by (nullable), joined_at
                 UNIQUE (team_id, user_id)
                 index (tenant_id, user_id)          -- "my teams"
                 CHECK role IN (owner, admin, manager, member, viewer)
                 CHECK profession_slug IS NULL OR profession_slug <> ''
```

`profession_slug` on the membership is deliberate: it answers "who on this
team is the designer?" without a second join, and it keeps the profession
catalogue out of the tenant's data. A team-level *required professions* list
is a later template feature, not v1.

Contract: new `TeamServiceInterface` in `lib/core` — `listForUser()`,
`get()`, `create()`, `update()`, `addMember()`, `removeMember()`,
`changeRole()`, plus `delete()` for CRUD parity. It mirrors
`AssistantServiceInterface`'s style, so the console and tools consume either
runtime identically. Tenancy is a runtime concern, so the contract carries no
tenant argument.

**Phase 3 shipped.** Migration `2026_11_12_100000_create_teams_tables` creates
both tables; `role` is a database enum (MySQL `ENUM`, SQLite `varchar … check`,
both emitted by Laravel's own grammar) so the role set holds for writes that
bypass the application. `LaravelTeamService` scopes every query through
`TenantContext` and enforces the two invariants the schema cannot express:
“a team keeps at least one owner” and “re-adding a member is a role change”.
`TeamController` serves `/api/v1/org/teams` + `/members`; `TeamTest` covers 15
cases including both database-level constraints, the tenancy indexes, and the
401-without-Accept-header regression.

**Why `/org`, not `/teams`.** `mcp-ai/v1/teams` already exists on WordPress
and means a *collection of professions* (the multi-agent collaboration
surface the SPA `TeamsPage` consumes, shaped `{ id, title, member_count }`).
The org primitive here is users-with-roles and returns a different shape, so
mounting it on `/teams` would silently break the shared console. The two
concepts stay separate: `/teams` = agent teams (profession collections),
`/org/teams` = the tenant's people.

### 4.2a Multi-agent team catalogue (parity) — shipped

The profession-team surface is now served on both runtimes, so the shared
console `TeamsPage` no longer 404s on Laravel:

```text
GET /api/v1/teams                 { success, teams: [{ id, title, member_count }] }
GET /api/v1/teams/{id}/members    { team_id, team_title, driver_assistant_id,
                                    has_driver_assistant, members[], count,
                                    orchestration_mode, result_aggregation,
                                    multi_agent_enabled, supports_unified_mode }
```

- Storage is a `mcp_ai_team` **content type** (the `SchemaStore` added in
  #7637), with the same meta keys WordPress uses:
  `_wp_mcp_ai_team_members`, `_wp_mcp_ai_team_orchestration_mode`,
  `_wp_mcp_ai_team_result_aggregation`, `_wp_mcp_ai_team_driver_assistant`.
- Members are **profession catalogue slugs**, not post IDs. `members[].id` is
  therefore omitted (the SPA already falls back to the array index for its
  React key) and `members[].slug` is added.
- `member_count` counts the raw membership, exactly as WordPress does, so a
  slug that no longer resolves still counts; it is skipped from `members`
  rather than failing the request.
- `multi_agent_enabled` reads `oos.features.multi_agent_teams`, which is
  **false** on Laravel: the catalogue is readable, but the orchestration
  engine is not ported, so claiming otherwise would be a lie.

Phase 1 gained the matching runtime fix: `LaravelAssistantService` had been
querying `is_default` and the listing without a tenant predicate (only the
controller was scoped), so the orchestrator could resolve another tenant's
default. Both now go through `TenantContext`, model-bound assistant routes
return 404 across tenants, and `AssistantTenancyTest` pins it.

### 4.3 Professions — versioned reference data

**Status: shipped** (`ProfessionRepository` + starter catalogue + the three
read tools, PR #7641).

```text
lib/laravel-adapter/resources/professions/
  manifest.json               { catalogue_version: "1.0.0", description }
  <domain>.json               { category, description,
                                professions: [ { title, slug, description,
                                  category, role_description, expertise[],
                                  warnings[], knowledge_base,
                                  default_tools[] } ] }
```

The loader globs `*.json` and merges every document that has a `professions`
array, so `manifest.json` (no `professions` key) is metadata only and a
deployment can point `oos.professions.path` at a fuller, licensed catalogue
without changing code. The shipped catalogue is a nine-role MIT-authored
starter; it deliberately does **not** copy the plugin's 544 K GPL corpus.

- Code-owned and versioned per release; **never** written at runtime.
- `ProfessionRepositoryInterface` is implemented on Laravel; the six methods
  map directly (`getAll`, `getBySlug`, `getByCategory`, `loadKnowledgeBase`,
  `getRecommendedTools`, `getCategories`). `ProfessionRepository::catalogueVersion()`
  exposes the manifest version for cache keys and logging.
- Shapes match `WP_MCP_AI_Profession_Service` so a tool cannot tell which
  runtime answered. Two upstream stubs are mirrored deliberately rather than
  invented on one side:
  - `loadKnowledgeBase()` returns `['knowledge' => [], 'playbook' => []]` — the
    legacy loader has no `load_for_profession()` entry point. Inline knowledge
    lives in `getBySlug()['profession']['knowledge_base']`.
  - The WordPress adapter's `getCategories()` returns `[]` because the legacy
    service has no `get_categories()` method. **Laravel implements the
    contract shape** (grouped `category => professions`), because
    `ProfessionStatsTool` consumes it.
- `list_professions`, `get_profession` and `profession_stats` are registered
  on Laravel in `NvoosServiceProvider::registerTools()`. `save_profession` is
  **not** registered: `ProfessionRepositoryInterface` has no write method, so
  the tool is a no-op stub that reports success without persisting.
- Assignment lives in the tenant's data (`team_members.profession_slug`), not
  in the catalogue.
- **Assistant selection.** WordPress keeps up to three profession post IDs in
  `_wp_mcp_ai_primary_roles`. On Laravel the slug is the identity, so the
  selection lives in `meta.primary_role_slugs`; `Assistant::toRecord()`
  promotes it to a flat `primary_role_slugs` field, the controller validates
  each slug against the catalogue and caps the list at three, and an update
  without the key leaves an existing selection untouched.
- WordPress keeps its existing service/loader; the core contract is the only
  shared surface.

## 5. Sequencing

| Phase | Work | Unlocks |
|---|---|---|
| 1 | Assistant hardening (tenant, composite unique, default) + regression tests | Correct multi-tenant assistants |
| 2 | Profession catalogue manifests + `ProfessionRepository` adapter + read tools + tests — **shipped** | Professional selector, recommended tools |
| 3 | Teams tables, `TeamServiceInterface`, `TeamController` (`090-b` 2.5) — **shipped** | Team CRUD + membership |
| 4 | Surfaces: profession selector, org-team pages, team-catalogue parity — **catalogue parity shipped**; org-team pages pending | User-facing |

## 6. Acceptance gates

- [x] Two tenants can own the same assistant slug; a third cannot duplicate
      it within its own tenant.
- [x] Exactly one default assistant per tenant, enforced by test.
- [x] `UNIQUE (team_id, user_id)` and a constrained role set, enforced at the
      database level, not only in PHP.
- [x] "My teams" and "team members" queries both hit an index.
- [ ] The profession catalogue reports a version, and a version bump
      invalidates the cache without a manual purge. *(Version is exposed via
      `catalogueVersion()`, but there is no shared cache layer yet — the
      repository only memoizes per instance, so a deploy is visible on the
      next resolve. A persisted cache keyed by `catalogue_version` is
      deliberately deferred until something needs it.)*
- [x] Every tenant-owned table has `tenant_id` as the leading index column and
      inside its unique constraints.

## 7. Guardrails

1. **Do not store these in `nvoos_posts`.** Content records and identity
   entities have different lifecycles and query shapes.
2. **No global unique on a tenant-owned value** — slugs, names and emails
   collide across tenants by design.
3. **Reference data is never edited at runtime.** A profession change is a
   release, with a version bump.
4. **Roles are data, not code paths.** Adding "auditor" should be a value in
   the constraint, not a new branch in every permission check.
5. **Expand → migrate → contract** for the live `assistants` table.
