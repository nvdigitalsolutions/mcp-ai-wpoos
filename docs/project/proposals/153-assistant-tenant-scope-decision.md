# Proposal 153 — Should `Assistant` carry a global tenant scope?

**Status:** proposed 2026-11-19; option A accepted and implemented 2026-11-19
(§8).

**Related:** [Proposal 149](149-laravel-admin-parity-implementation-plan.md) §18
(workflow runs), §21 (`ResolveApiTenant`), §27/§39/§40/§47 (earlier assistant-scope
leaks), §86 (token telemetry), §88 (harness traces), §89 (the two routes that
prompted this).

## 1. The question

`Approval`, `Memory`, `Transcript` and `WorkflowRun` each carry a global Eloquent
scope that adds `where tenant_id = TenantContext::current()` to **every** query.
`Assistant` does not.

Instead, **26 hand-written `tenant_id` filters across 18 files** apply it query by
query — each one added after a specific leak. The question is whether to make the
assistant behave like its four siblings, and what that costs.

This is a decision, not a patch: it changes the default behaviour of a model that
every surface in the runtime reads.

## 2. What the evidence says

### 2.1 The hand-written filter is the dominant pattern

| Measure | Count |
|---|---|
| `Assistant::` references in `app/` | **41** across 24 files |
| Queries that apply `tenant_id` by hand | **26** across 18 files |
| Route-model binding parameters | **6** |
| Models that already have a global tenant scope | **4** (`Approval`, `Memory`, `Transcript`, `WorkflowRun`) |

Twenty-six independent copies of the same `where` is not a convention; it is a
missing abstraction that each author has re-derived.

### 2.2 Every documented leak is a lookup by a client-supplied id

Reading the code comments that accompany those 26 filters:

| Section | Site | Shape |
|---|---|---|
| §27/§39/§40/§47 | `AssistantShortcutCatalogue` (extracted from `ToolShortcutsController`) | `Assistant::find($assistantId)` on a body id |
| §42 | `ProbeRunner::assistantOptions()` | bare `find()` on `/ops` |
| §89 | `ChatStreamController` | `find()` on a body `assistant_id` |
| §89 | `McpAppsController` | route-model binding on `{assistant}` |

Every one is **identity resolution from an untrusted id**. None is an aggregate
or list query — those were already scoped, because a page that lists rows is
obviously wrong when it lists the wrong ones, while a `find()` that returns a row
looks fine.

That matters for the choice: the failure mode is narrow, but it recurs, and it is
invisible in review because the code reads correctly.

### 2.3 The behaviour change is small and identifiable

A global scope does **not** change 41 sites. It changes four:

| Site | Today | Under a global scope |
|---|---|---|
| `AgentNodeRunner::run()` (`find($agentId)`, `where('is_default', true)->first()`) | reads across estates; a foreign estate's default can be picked | scoped — and the foreign-default bug disappears |
| `Filament\Operator\Pages\Credentials` (`Assistant::query()->with('tenant')`) | lists every estate's assistants | **would silently list only `default`** — needs an explicit opt-out |
| `EstateCredentials::estateAssistantIds($tenantId)` and `ownsAssistant($tenantId, $id)` | filter by an explicit tenant argument | double-scoped — needs an explicit opt-out |
| `TraceRuns::titles()` | already `withoutGlobalScopes()` | unchanged |

Everything else already passes `tenant_id` explicitly, so the added predicate is
redundant and harmless.

**Two files need a new opt-out** — `Operator\Pages\Credentials` and
`EstateCredentials` — and both are surfaces with a documented cross-estate or
explicit-tenant intent. A third, `TraceRuns::titles()`, already has one. That is
the whole migration.

### 2.4 The operator panel is the counterweight

`OperatorPanelProvider` deliberately declares **no** `->tenant()`: `/ops` is
cross-estate by design. So on `/ops`, `TenantContext::current()` is whatever the
page pins — usually `default`. A global scope therefore converts "operator reads
everything" into "operator reads the default estate" **unless every operator page
opts out and says why**.

That is the real cost, and it is not the code — it is that the failure is silent.
Fail-closed here means *missing data*, not a leak, and missing data on an operator
console is its own incident class.

The codebase already shows both answers coexisting: `ProbeRunner` scopes to
`default` on `/ops` and calls that correct; `Credentials` reads everything and
calls that correct. Neither is written down. A global scope forces the question.

## 3. Options

### A. Global tenant scope on `Assistant`

Copy the `booted()`/`addGlobalScope('tenant', …)` block the other four models use.

- **For:** removes the class, not the instance; matches four siblings; the 26
  hand-written filters become redundant; route-model binding starts answering 404
  for a foreign id automatically, which is exactly the §89 fix; the foreign
  `is_default` bug disappears.
- **Against:** changes a model's default semantics; needs two new opt-outs (a third
already exists); makes a forgotten opt-out *silent* rather than loud; requires
every queue/command context that reads assistants to pin first.

### B. Scope route-model binding only

Override `resolveRouteBinding()` to add the tenant predicate.

- **For:** smallest change; closes all 6 binding sites and the whole §89 class in
  one place; no effect on queries.
- **Against:** does nothing for `find()` inside services (`AgentNodeRunner`,
  `AssistantShortcutCatalogue`, `ChatStreamController` before §89); the remaining
  `is_default` bug survives; the 26 hand filters stay.

### C. Keep per-call-site checks, add an automated guard

Ship a `WPMCPAI.Laravel.AssistantScope` PHPCS sniff (the repo already carries two
`WPMCPAI.Tools.*` sniffs at severity 5) that fails CI on an `Assistant` query with
no `tenant_id` and no documented opt-out.

- **For:** no behavioural change; the omission becomes a build error instead of a
  leak; consistent with how this repo already enforces tool envelopes.
- **Against:** enforces a rule rather than removing the need for one; the sniff has
  to understand `->where`, `->find`, `->whereKey`, binding and relations, so it
  will have false negatives (`find($id)` with no `where` is the exact case that
  leaked, and it is the hardest to detect statically).

### D. Repository seam

All assistant access behind a service whose methods require a tenant.

- **For:** the strongest boundary; testable.
- **Against:** the runtime has no repository layer, and inventing one for a single
  model is a much larger change than the problem. Recorded for completeness.

### Comparison

| | Fixes §89 class | Fixes `is_default` bug | Removes 26 duplicates | Silent-failure risk | Size |
|---|---|---|---|---|---|
| **A** global scope | yes | yes | yes | **medium** (missing data) | medium |
| **B** binding only | yes | no | no | none | small |
| **C** sniff | yes, if detected | no | no | none | small |
| **D** repository | yes | yes | yes | low | large |

## 4. Recommendation

**A, with the opt-outs named in the code and a test that pins them.**

Reasoning:

1. The runtime already made this choice four times. A fifth model behaving
   differently is the anomaly to justify, not the default to keep.
2. The 26 hand-written filters are the strongest signal available: authors keep
   rediscovering the same requirement, which means the model is not carrying its
   own invariants.
3. §89 was two leaks in one slice, and the second was in a route nobody was
   looking at. Option B would have caught both, but not `AgentNodeRunner`'s
   foreign default, which is the same mistake in a query.
4. Option C is the right *complement* to A — a guard on the opt-outs — and the
   wrong substitute for it. A sniff that must recognise `find()` as unscoped is
   the case static analysis handles worst.

**A is not recommended as a security fix on its own.** It changes an invariant, so
it must land with the operator tests that prove each opt-out still sees every
estate. Without those, the change trades a leak class for a reporting class.

## 5. What implementation would involve

Not done here; recorded so the size is visible.

1. `Assistant::booted()` gains the scope (one block, copied verbatim).
2. `Filament\Operator\Pages\Credentials` adds `withoutGlobalScopes()` with a
   comment explaining the cross-estate intent.
3. `EstateCredentials::estateAssistantIds()` and `ownsAssistant()` add
   `withoutGlobalScopes()` — they take the tenant as an argument, so the ambient
   one is not theirs to apply. Without this, `ownsAssistant('estate-b', …)`
   returns false on `/ops` and the operator panel misreports the estate.
4. `McpAppsController` and `AssistantController` keep their explicit
   `ensureInCurrentEstate()` calls: with the scope they become unreachable, but
   they are the readable statement of the rule and cost nothing.
5. `ChatStreamController::resolveAssistant()` keeps its check for the same reason.
6. A new `AssistantTenantScopeTest`: the scope hides a foreign row;
   `withoutGlobalScopes()` sees it; creation stamps the tenant; route binding
   404s; the operator credentials page still reports two estates.
7. `AgentNodeRunner` gets a test for `is_default` resolving inside one estate only.

### Test impact

74 `Assistant::` references across the suite. Most create and read in `default`,
where `TenantContext::current()` already resolves, so they are unaffected. The
ones that need review are the multi-estate fixtures: `PortabilityTenancyTest`,
`AdminEstateCredentialsTest`, `AdminConversationImportTest`, `TenantContextIsolationTest`.

### Rollout

No migration: the column exists and is backfilled (149 §21). The change is
behavioural, so it ships in one release with the opt-outs — a split release would
hide operator data in between.

## 6. Risks

| Risk | Mitigation |
|---|---|
| An operator page forgets the opt-out and silently under-reports | Every operator surface that reads assistants gets a two-estate fixture; `AdminEstateCredentialsTest` is the template |
| A queue job or command reads assistants with nothing pinned | Only `FireTriggers` reads assistants in `app/Console`, and it pins per trigger. Documented as a rule: a job that reads assistants pins first |
| A future cross-estate feature forgets the opt-out | Fail-closed is the correct default for a tenancy boundary; the opt-out is one call and one comment |
| `withoutGlobalScopes()` becomes a habit that re-opens §89 | The opt-out list is three files, asserted by a test; adding a fourth is a reviewable diff |

## 7. Open questions

1. **Does §89.3's `options.provider` question belong to this decision?** No — that
   is spend, not tenancy. Separate.
2. **Should `TenantContext::current()` throw when unpinned?** That would make the
   ambient-`default` risk explicit, but it breaks every single-estate deployment
   that legitimately runs without a pin. Not recommended.
3. **Is `is_default` per estate?** Yes — `AssistantWriter::clearOtherDefaults()`
   already clears per tenant. So the scope aligns the reader with the writer.
4. **Do blueprints or portability need cross-estate reads?** No: both are scoped
   to the current estate today by explicit filter, and the bundle format never
   carries a tenant (§34).

## 8. Implementation record

Option A shipped. The scope lives on `Assistant::booted()` with the same shape
the other four models use; the explicit `ensureInCurrentEstate()` and
`resolveAssistant()` checks stay, unreachable but readable as the statement of
the rule.

**Opt-outs** — five sites, each with a comment:

| Site | Why |
|---|---|
| `Operator\Pages\Credentials` | `/ops` lists every estate; the Estate column is the point |
| `EstateCredentials::estateAssistantIds()` | takes the estate as an argument |
| `EstateCredentials::ownsAssistant()` | same |
| `EstateCredentials::overview()` title lookup | titles for ids already resolved per estate |
| `TraceRuns::titles()` | labels a run whose estate it already resolved (pre-existing) |

The `EstateCredentials` ones were not optional: without them
`ownsAssistant('estate-b', …)` returns false on `/ops` and the panel
**misreports** the estate — the silent-data failure §2.4 predicted.

**Fixtures that had to change** — four, each asserting across estates for a
schema-level property rather than a reader's one: `AssistantTenancyTest` and
`AdminAssistantsTest` (the `(tenant_id, slug)` index), `AdminBlueprintsTest`
(the blueprint boundary), and `PortabilityTenancyTest` (now pins the estate it
asks about instead of reading whatever context the ended request left behind).

### Gates

| Run | Result |
|---|---|
| `AssistantTenantScopeTest` (new) | **6 tests** — the scope hides a foreign row; the opt-out reaches it; each estate sees only its own; route binding 404s; a default is not taken from another estate; an estate with no default gets none |
| `OperatorCredentialsTest` | **+1** — the page still lists two estates, with the Estate column |
| `AdminAssistants`, `AssistantTenancy`, `PortabilityTenancy`, `AdminBlueprints`, `AssistantTenantScope`, `OperatorCredentials` | **63 tests / 234 assertions, 0 failures** |
| Broad sweep (assistants, tenancy, blueprints, portability, probes, operators, chat, tokens, traces, workflows) | **423 tests / 1,807 assertions, 0 failures** |
| phpcs (`phpcs-laravel.xml.dist`, 9 files) | **0 errors** |
| Full app suite | **1443 tests / 18,386 assertions, 0 failures** (CI `App suite (SQLite)`) — +7 tests / +12 assertions over §89's 1436 / 18,374 |
| CI `Console E2E (Laravel / SQLite)` | **pass** |

The broad sweep is the number that matters here: it covers every surface known
to read assistants, and it is what proves the scope did not silently reduce an
operator page to the default estate.
