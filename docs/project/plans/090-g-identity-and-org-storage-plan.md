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
`changeRole()`. It mirrors `AssistantServiceInterface`'s style, so the
console and tools consume either runtime identically.

### 4.3 Professions — versioned reference data

```text
lib/laravel-adapter/resources/professions/
  index.json                  { catalogue_version: "1.0.0", professions: [...] }
  <slug>.json                 { slug, label, category, description,
                                knowledge_base: [...], recommended_tools: [...] }
```

- Code-owned and versioned per release; **never** written at runtime.
- `ProfessionRepositoryInterface` reads the manifest; the six methods map
  directly (`getAll`, `getBySlug`, `getByCategory`, `loadKnowledgeBase`,
  `getRecommendedTools`, `getCategories`).
- Cache key includes `catalogue_version`, so a deploy invalidates cleanly
  without a manual purge.
- Assignment lives in the tenant's data (`team_members.profession_slug`), not
  in the catalogue.
- WordPress keeps its existing service/loader; the core contract is the only
  shared surface.

## 5. Sequencing

| Phase | Work | Unlocks |
|---|---|---|
| 1 | Assistant hardening (tenant, composite unique, default) + regression tests | Correct multi-tenant assistants |
| 2 | Profession catalogue manifests + `ProfessionRepository` adapter + tests | Professional selector, recommended tools |
| 3 | Teams tables, `TeamServiceInterface`, `TeamController` (`090-b` 2.5) | Team CRUD + membership |
| 4 | Surfaces: profession selector, team pages (reuse the Content Graph page patterns) | User-facing |

## 6. Acceptance gates

- [ ] Two tenants can own the same assistant slug; a third cannot duplicate
      it within its own tenant.
- [ ] Exactly one default assistant per tenant, enforced by test.
- [ ] `UNIQUE (team_id, user_id)` and a constrained role set, enforced at the
      database level, not only in PHP.
- [ ] "My teams" and "team members" queries both hit an index.
- [ ] The profession catalogue reports a version, and a version bump
      invalidates the cache without a manual purge.
- [ ] Every tenant-owned table has `tenant_id` as the leading index column and
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
