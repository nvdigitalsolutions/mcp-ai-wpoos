# Proposal 149 — Surface Ownership Matrix

**Parent:** [`149-laravel-admin-parity-proposal.md`](./149-laravel-admin-parity-proposal.md) · [`149-laravel-admin-parity-implementation-plan.md`](./149-laravel-admin-parity-implementation-plan.md) §14
**Status:** ✅ Contract + CI gate written (2026-11-17); first projection (settings, §6.1) shipped 2026-11-17. The matrix is data in `docker/laravel/app-skeleton/contracts/surface-ownership.json`; this document is the rationale.
**Decision:** **D-6** — the admin is the superset of *capability*; the console is a *plan-scoped product surface*. They are two presentations of one domain layer, never two implementations.
**Author:** NV oOS coding agent (Zed)

---

## 1. What this document decides

The question is not "which pages do we build". It is **"for each surface, who may reach it, from which presentation, through which write path, under which entitlement, and what exactly does a reduced presentation receive?"**

Without that recorded, three defects are structural rather than accidental:

1. **API5 / BFLA** — the admin and the customer app drift into different, undocumented function sets. OWASP names the cause precisely: *"Complex access control policies with different hierarchies, groups, and roles, and an unclear separation between administrative and regular functions, tend to lead to authorization flaws."*
2. **API3 / BOPLA** — a "limited" console is limited only in its DOM. The endpoint still returns the operator fields, so the reduction is cosmetic.
3. **Two implementations of one rule** — the same validation or state transition written once in Livewire and once in React. Both render, both pass their own tests, and they disagree in production.

The matrix makes all three testable.

---

## 2. Standards basis

Four sources decide the shape of the artifact. Each contributes a column, not a slogan.

| Source | Requirement | Where it lands in the matrix |
|---|---|---|
| **OWASP ASVS 4.0.3 · V4 Access Control** — 4.1.1 | *"Verify that the application enforces access control rules on a trusted service layer, especially if client-side access control is present and could be bypassed."* | `writer` must name a server-side owner for every mutation. A `console: full` surface whose writer is `none` is a read-only lie. |
| ASVS 4.1.3 | Least privilege — users reach only functions, data and URLs they are specifically authorised for. | `audience` + `plan_gate`. |
| ASVS 4.1.5 | Access controls fail securely. | `state: absent` is preferred over "render the page and 403 on submit" — an unlicensed toolkit is not a page. |
| ASVS 4.3.3 | Segregation of duties for high-value operations. | Licences, credential rotation, DLQ and cross-estate tokens are `operator` only; they never share an audience with tenant administration. |
| **OWASP API Security Top 10 2023** — API1 BOLA | Object-level authorisation on every data-access path. | Cross-tenant **404** is a required test per surface (already the 090-g rule: non-member → 404, member without ability → 403). |
| API3 BOPLA | Property-level authorisation — the union of excessive data exposure and mass assignment. | The `projection` column: a `subset` or `read-only` surface **must** state the server-side reduction it receives. |
| API5 BFLA | Clear separation of administrative and regular functions. | The matrix itself. Every surface declares its audience, and the console cannot reach an `operator` row. |
| **NIST SP 800-162 (ABAC)** | Authorisation is decided by attributes of the **subject**, the **object**, the **requested operation**, and the **environment**. | The four axes: `audience` (subject), `surface` (object), `writer`/`state` (operation), `plan_gate` (environment). A matrix that only records roles is RBAC wearing an ABAC hat. |
| **BFF pattern** (Sam Newman) | *"one experience, one BFF"*; duplication across services is preferable to a bloated shared backend. | One reader in `lib/core`, two callers. The console and the admin must not converge on a single general-purpose "admin API" that both then special-case. |
| **Laravel authorisation** | `Gate::before` returning `null` falls through; `Response::denyAsNotFound()` hides existence. | Existing repo rules: `Gate::before` answers site abilities and returns `null`; cross-tenant misses return 404, not 403. |

**What the standards do *not* say.** None of them requires two code paths. ASVS 4.1.1 is satisfied by one service layer with two presentations; BFF cautions against consolidating *backends*, not against sharing a domain layer. The matrix is therefore an **authorisation and ownership** artifact, not a build-order artifact.

---

## 3. The model: two hierarchies, not one

The earlier framing — "the console covers 45 keys, the admin covers the rest" — is a **coverage** statement. It is not an authority statement. Authority has two independent axes:

```
                        operator (NV staff)        customer (tenant)
full entitlement        /ops   cross-estate         /admin   own estate, everything
                        licences, DLQ, creds,       assistants, teams, settings,
                        scheduler liveness          tokens, tools, media, …
limited entitlement     —                           console  plan-gated product surface
                                                    chat, runs, toolkits, light admin
```

Three consequences that the matrix enforces:

- **`/ops` is not "admin plus"**. It is outside `manage_options` on purpose — its authority comes from operator status, not from a role. A customer administrator is provably refused by the Q-1 trap test.
- **`/admin` is not a superset of `/ops` in *data scope***. A tenant administrator sees their estate; an operator sees the estate list. Breadth of *features* and breadth of *records* are different columns.
- **The console is not a simplified `/admin`**. It is a different product surface with its own audience (tenant users, not only tenant administrators) and its own strengths (chat, canvas). Reducing it to "admin with buttons hidden" throws away the one thing it does better.

**D-6 therefore reads:** the admin is the superset of capability, the console is plan-scoped, and both are presentations over one writer per operation. "Two versions" means two renderings, not two rulebooks.

---

## 4. Decision rules

These are the invariants the contract encodes. They are written as rules so a future reviewer can reject a PR by number.

| # | Rule | Why |
|---|---|---|
| **R1** | **One authoritative writer per mutation.** A surface declares `writer`; two presentations may call it, but neither may reimplement it. | ASVS 4.1.1; prevents the drift class in §1.3. |
| **R2** | **No presentation is an enforcement point.** `state: full` means the API/Filament action already allows it — not that the button exists. | "Hiding a button is UX, not authorization" (090-g). |
| **R3** | **Every reduced surface declares its projection.** `subset` and `read-only` require a non-empty `projection`; CI fails otherwise. | API3 BOPLA. |
| **R4** | **404 for cross-tenant, 403 for capability, 401 for authentication.** Never 403 for a record that should not be known to exist. | ASVS 4.2.1; existing `TeamAccess` behaviour. |
| **R5** | **No second implementation of chat or canvas.** Chat is `link-out` from the admin; the workflow builder stays React and is embedded in Wave 6. | BFF; avoids a Livewire fork of interactive surfaces. |
| **R6** | **A surface with no console key must still have a home.** `ops` or `admin` presence is mandatory — an unowned operator surface is how parity work disappears. | ASVS 4.1.3. |
| **R7** | **Plan gates are data, not branches.** `plan_gate` uses `feature:*` / `toolkit:*` prefixes resolved by the entitlement service. | Keeps the console and the admin reading the same entitlement store. |
| **R8** | **Secrets are never returned, only masked.** A projection may reduce fields; it may never widen them. Token hashes are never selected; remote-site credentials have one opener (`credentialsFor()`), runtime-only. | Existing slice invariants; ASVS 4.1.5. |
| **R9** | **New page or new console key = new row, in the same commit.** CI fails on an unmapped key and on an undeclared Filament surface class. | Makes D-6 durable rather than aspirational. |
| **R10** | **A row is a decision, not a TODO.** `planned` is allowed; silence is not. | Wave 7's disposition table, applied earlier. |

---

## 5. How to read a row

Authoritative data: `docker/laravel/app-skeleton/contracts/surface-ownership.json` (schema v1). One row per surface:

```json
{
  "id": "settings.estate",
  "title": "Estate settings",
  "audience": "tenant-admin",
  "ops": null,
  "admin": { "state": "full" },
  "console": { "state": "subset", "keys": ["settings"] },
  "writer": "shared",
  "plan_gate": "feature:settings",
  "projection": "Read: the estate catalogue. Write: writableFor('admin') — operator scope excluded.",
  "files": ["app/Filament/Admin/Pages/Settings.php"]
}
```

- `state ∈ {full, subset, read-only, link-out, absent, planned}` — closed vocabulary. `absent` is a decision; `planned` is a queued decision.
- `writer ∈ {ops, admin, console, shared, none}` — `none` is only legal for genuinely read-only surfaces (`tools`, `audit-log`, `connected-apps`).
- `files` — the Filament class(es) that implement the row. Filament's derived `Manage*` resource pages are not rows; only the `*Resource` class and top-level `Pages/*` are.
- Row count is 42; the 45 console keys are partitioned across 29 of them, with the remaining 13 rows being `/ops`-only or `/admin`-only surfaces that have no console equivalent.

---

## 6. The four surfaces this decision was asked about

### 6.1 General settings

Today: `SettingsController::index()/schema()/values()` return **every** field in `SettingsSchema::fields()` to any authenticated caller, with sensitive values masked. So the console can read and write operator-shaped settings, and "limited console" is currently a UI claim.

Target: the admin is the full form. The console receives a projection.

- `SettingsSchema::fields()` declares `scope: operator|tenant|user` and `console: visible|readonly|hidden` on every field.
- `SettingsSchema::forSurface('console')` drops hidden fields; `tabsForSurface('console')` drops a tab left empty. The controller uses both for `schema`, `index`, `values` and `updateValues`.
- A hidden field is **omitted**, not returned as `null` — null still discloses that the setting exists.
- A hidden key in a write payload is ignored, and the visible keys in the same request still write. Rejecting the whole request with 422 would disclose the field's existence.
- `keys()` and `sensitiveKeys()` stay complete: the security snapshot path depends on the full catalogue, so filtering happens in the projection only.

**Shipped 2026-11-17** (implementation plan §15). `enable_logging` is the first operator-scope field, so the console loses the `advanced` tab; nothing in the SPA hardcodes it — the form builds tabs and fields from the payload, so an emptied tab disappears with no client change.

**Ownership shipped 2026-11-17** (implementation plan §16). The store now has two layers: operator scope in `nvoos_settings` (platform), tenant/user scope in `nvoos_tenant_settings` keyed by `(tenant_id, key)`. One service routes a field to its layer and maps the surface key to the storage key the runtime reads (`google_api_key` → `gemini_api_key`, `ollama_endpoint` → `ollama_endpoint_url`). Read and write projections are separate: `forSurface()` decides what is rendered, `writableFor()` decides what is accepted, and `admin` accepts only tenant/user scope — a rendered disabled control is not permission.

**Pages shipped 2026-11-17** (implementation plan §17). One generated form builder feeds both panels: `/admin/settings` renders the full estate catalogue with operator fields disabled under the S-1 hint, and `/ops/settings` renders operator scope only. The contract row split in two — `settings.estate` and `settings.platform` — because they have different audiences, writers and layers.

**Why `/ops` does not render estate fields.** The operator panel is deliberately not tenant-scoped, so `TenantContext` resolves `default`. A `default_model` field there would edit one estate while looking like a platform setting: a failure that appears to work.

**One follow-up.** The WordPress twin still returns `enable_logging` and `ollama_endpoint` to the console; on that runtime the console user is the wp-admin user, so no privilege boundary is crossed, but the same page now differs per runtime — align when the WP settings schema is next touched.

### 6.2 Token manager

Two different surfaces, previously conflated:

| | Assistant credentials (`/ops/credentials`) | Personal access tokens (`tokenManager`) |
|---|---|---|
| Audience | operator | tenant-admin (own tokens), planned operator cross-estate view |
| Writer | `AuthProvider` | token service |
| Verbs | rotate (issue-then-delete) | overview / revoke / delete / lift |
| Projection | plaintext shown once, never stored in audit | metadata only; **hash never selected** |
| Console | absent | `subset` — own tokens, metadata only |

The expiry defect fixed during this track (`issueCredential()` never persisted `expires_at`) belongs to the first column and is the reason the two must not share a page.

### 6.3 Tools

`tools` is read-only on every surface (`writer: none`). The admin gets the full catalogue with readiness annotations. The console's honest projection is the assistant's **enabled** tools plus presets — not the 223-entry registry. Today it receives the full list, which is API3 by definition: the catalogue is not a secret, but it is object property a plan holder did not ask for.

### 6.4 Orchestration

The ruling from implementation plan §13.1 stands, now recorded in the matrix:

- **Definitions** (schedules, triggers, presets) — `console: full`, `writer: console`. Rebuilding CRUD in Filament would maintain one page twice. The admin candidate is a read-only view.
- **Runs** — `console: read-only`. This is the one orchestration surface that is genuinely admin-shaped: inspecting a failed run, its node outcomes and its `skipped` downstream nodes is post-incident work. It is the recommended next slice.
- **Canvas** — `console: full`, `admin: link-out`. Wave 6 embeds the React bundle; it is never rebuilt in Livewire.

So the answer to "does this consider orchestration and the token layer?" is: **yes, as decisions** — and the decisions mostly say *the console keeps orchestration definitions*, which is a change from "port everything".

---

## 7. Enforcement

### 7.1 What is wired

`tests/Feature/SurfaceOwnershipTest.php` (four tests):

1. the contract parses and declares its vocabularies;
2. every `route_backed` key in `console-endpoints.json` has **exactly one** row, and no row names an undeclared key;
3. every row uses the closed vocabularies; `subset`/`read-only` rows must declare a `projection`; `plan_gate` must use a declared prefix;
4. every declared Filament file exists, and every `app/Filament/*/Resources/*/*Resource.php` plus `app/Filament/*/Pages/*.php` file has a row.

Test 4 is the "new page without a decision" gate. It is deliberately file-path-based rather than reflection-based: the vendor tree is a Docker volume and is not resolvable on a host checkout, so class-name guesses would make the gate environment-dependent.

### 7.2 What is deliberately not wired yet

- **Per-field projection assertions.** The contract says Settings must be scope-filtered; no test asserts a field is absent for a console caller, because the projection itself does not exist yet. Wiring the test before the projection would assert a fiction.
- **Route-level reachability per row.** The endpoint map already covers console keys; `/ops` and `/admin` reachability is covered by the existing panel/policy tests.
- **Entitlement resolution.** `plan_gate` is declared, not enforced by this contract. `TenantEntitlement` enforces plans; the matrix only names which key a surface depends on.

### 7.3 Validation status

The contract JSON parses and the four tests were written on 2026-11-17. **They have not been executed**: `oos-laravel-app` (8001) and `oos-laravel-worker` are unhealthy, and the app suite needs the container's vendor symlinks. The first real run is CI's `App suite (SQLite)` job. Treat the gate as unproven until that job is green — the repo rule is that a count you saw is stronger evidence than a pipeline that did not fail.

---

## 8. Gaps this exposes

Ordered by leverage, each small enough to be one slice:

1. **`SettingsSchema` scope + console projection** (§6.1). The clearest R3 violation in the codebase.
2. **`/admin` settings page** — the full form, reading the same store.
3. **Workflow runs list** in the admin (§6.4) — read-only, the one orchestration gap.
4. **Tool catalogue projection** for the console (§6.3) — enabled tools + presets.
5. **PAT manager** split from assistant credentials (§6.2), with an operator cross-estate view.
6. **`/ops` tail** — onboarding, site health, identity provider, assets, datasets, conversation import, reports, audit log (all `planned` rows).
7. **`/admin` tail** — professions, chat profiles, memory, media, transcripts, tool catalogue, blueprints, portability, MCP apps, remote sites, skills/OKF, paper store, dashboards, command centre (all `planned` rows).

Passing a row from `planned` to `full` is now the unit of progress, and every transition is a one-line diff plus its test.

---

## 9. Open questions

| # | Question | Blocks |
|---|---|---|
| S-1 | Should `/admin` settings render operator-scope fields disabled with a "managed by NV Digital" hint, or omit them entirely? | The settings slice. Omitting is safer (R3); hinting is friendlier. |
| S-2 | Does the console need a cross-estate view of anything? Today: no. If yes, it becomes an operator surface with a different audience. | Client feedback. |
| S-3 | For `toolkit:{slug}` gates, does an unlicensed toolkit disappear from the console nav or render an upsell? | Wave 3. Upsell is a product surface; absence is an authorisation surface. |
| S-4 | Does the admin need a "view as tenant" mode for operators? | Not in v1; it would break the Q-1 trap if implemented as impersonation rather than a separate read projection. |
| S-5 | ~~Should `ollama_endpoint` be operator-scope?~~ **RESOLVED 2026-11-17 — yes.** A URL the server fetches is platform configuration, not an estate preference; leaving it tenant-writable is an SSRF primitive. Customers keep the ability to *use* Ollama (`default_provider` and `default_model` stay tenant-scope); only the address is closed. Recorded in the contract row and implementation plan §16. | Closed |

---

## 10. References

- OWASP ASVS 4.0.3, V4 Access Control — <https://raw.githubusercontent.com/OWASP/ASVS/v4.0.3/4.0/en/0x12-V4-Access-Control.md>
- OWASP API Security Top 10 2023 — <https://owasp.org/API-Security/editions/2023/en/0x11-t10/>
- OWASP API1:2023 BOLA — <https://owasp.org/API-Security/editions/2023/en/0xa1-broken-object-level-authorization/>
- NIST SP 800-162, Guide to Attribute Based Access Control — <https://csrc.nist.gov/pubs/sp/800/162/upd2/final>
- Sam Newman, *Backends For Frontends* (2015) — <https://samnewman.io/patterns/architectural/bff/>
- Laravel 12 authorisation (gates, policies, `denyAsNotFound`) — <https://laravel.com/docs/12.x/authorization>
- Internal: `090-g` identity/org authorization, `090-n` console manifest parity, `149` §8.1 (do not maintain one page twice), §13.1 (scheduler liveness ruling)
