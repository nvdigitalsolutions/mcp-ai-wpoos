# 159. Machine principals: a scope, not a second kind of user

**Status:** proposed, 2026-11-20. Answers 158 D6's open half and 149 §117.4's four
questions. No code ships with this document.

## 1. What is open, and who is waiting

Four tenant-scope keys render in `/admin` settings, are read by nothing, and are
refused by `writableFor('admin')`:

| Key | Obstacle recorded in `UnimplementedSettings` |
|---|---|
| `rest_enable_assistant_list` | "Needs a first-party vs token-call split on `/api/v1/assistants`." |
| `rest_enable_assistant_create` | same |
| `rest_enable_assistant_delete` | same |
| `guest_token_lifetime` | "No guest-token path exists in this runtime." |

158 D6 parked them *"until I5"*. I5 shipped (plan §110, §111, §116) and provisioned
**people**; 149 §117 corrected the premise without deciding anything. This document
is the decision.

It has to answer four questions (149 §117.4): **assistant-scoped or estate-scoped**;
what a token-call may do that a browser may not; whether `/api/v1/tools` accepts one;
and who mints and revokes one.

## 2. What the code actually does today (verified, not assumed)

### 2.1 WordPress is the reference, and it already splits first-party from external

`includes/class-wp-mcp-ai-rest.php`, three permission callbacks:

| Route | First-party (nonce / application password) | External (bearer, mesh, guest) |
|---|---|---|
| `GET /assistants` | **always allowed** — the setting is skipped entirely (line 2401, 2418) | gated by `rest_enable_assistant_list`, default **true** |
| `POST /assistants` | **gated** — no bypass (line 2464) | gated, default **false** |
| `DELETE /assistants/{id}` | **gated**, plus `delete_post` (line 2505) | gated, default **false** |

So the split the Laravel obstacle string calls missing **exists on the WordPress
side**, hand-written in the list callback: a same-origin nonce is first-party; any
`Authorization` credential is external, *including a user's own bearer token*. The
create/delete pair do not have the bypass — but they do not need it, because the WP
admin UI authors assistants through the CPT editor rather than REST.

### 2.2 In Laravel, a machine credential is a person's full authority, replayed

- `routes/api.php`: `/api/v1/tools` and the assistant routes sit inside
  `auth:sanctum,auth0` + `ResolveApiTenant` (line 118), in the `throttle:console`
  group. **Any authenticated principal can read the tool catalogue**;
  `ToolsController::__invoke()` performs no authorization of its own.
- Personal access tokens are minted with `abilities: ['*']`, and nothing in the
  runtime calls `tokenCan()`. Authorization is the owner's **live role**, so a token
  is never narrower than its owner and never wider — and it cannot be narrowed.
- `lib/core` already models something better: `AuthContext` carries `tokenType`
  (`bearer`, `nonce`, `mesh`, `guest`) and `scopedAssistantId`, and
  `lib/craft-adapter` mints a guest token as *assistant-scoped* with
  `capabilities: ['public']`.

### 2.3 Two recorded claims this document overturns

1. **149 §102.4 item 3** says `/api/v1/tools` "sits outside the tenant group (its own
   `auth:sanctum` chain)". It does not: it is inside `auth:sanctum,auth0` and
   `ResolveApiTenant`, so it accepts Auth0 tokens and resolves an estate.
2. **The `identity-provider` contract note** and **149 §117.2** repeat that it "takes
   no Auth0 tokens yet". Also false. Corrected in this change.

## 3. The reframing

The problem is not that a machine cannot act. A token can already do everything its
owner can. The problem is that **a machine credential cannot be narrower than the
person who minted it**, so the only safe answer today is to mint nothing.

That makes this decision about **scoping down**, not about granting. The vocabulary
is standard: delegated versus application authority (the Microsoft Graph
distinction), with token exchange (RFC 8693) and resource indicators (RFC 8707) as
the mechanisms a larger deployment would use instead of a bespoke scope string.

## 4. The options

| | A. Refuse | B. Assistant-scoped | C. Estate-scoped | D. Both, one credential kind |
|---|---|---|---|---|
| `assistant_list` | stays inert | inert (a machine needs no directory) | meaningful | meaningful, `estate:assistants.list` |
| `assistant_create/delete` | stays inert | **never** — a scoped principal must not author its own scope | meaningful, separately granted | meaningful, separately granted |
| `/api/v1/tools` | listing open to members, execution absent | may execute **only** its assistant's tools | may read the catalogue; execution needs a named capability | as B for assistant scope, as C for estate scope |
| Blast radius of a leak | n/a | one assistant | the whole estate | bounded by the scope |

**B** is the smallest useful thing and matches what the runtime already models.
**C** is what an estate admin would ask for and is the largest risk. **A** is the
status quo, which keeps four settings inert forever.

## 5. Recommendation: D, with B as the only scope built first

One credential kind, one table, an **explicit scope** — because a single `abilities`
array cannot express "which assistant" once a runtime has more than one.

Concretely:

1. **Reuse Sanctum's `personal_access_tokens`.** Scope goes in `abilities` as
   structured entries — `assistant:42`, `estate:assistants.list`. Revocation is
   already delete-only and immediate; a second credential store would be a second
   revocation path to get wrong.
2. **Enforce scope at the route** with a `scope:` middleware, never inside a handler.
   A handler that forgets is invisible; a missing route attribute is not.
3. **A machine token is capped by its scope, not by its owner's role** — the exact
   inverse of a personal token. This is the rule that makes D6's obstacle go away.
4. **`/api/v1/tools` execution requires `assistant:{id}`.** The catalogue listing
   stays available to any member (it is a directory, and members can already see the
   assistants). No tool-execution route for machine principals exists yet; when one
   is added it takes an assistant scope, because a tool call is an action.
5. **The three settings mean what WordPress's `list` means**: they gate **external**
   callers only. The session/panel path is not gated, which is a deliberate
   divergence from WP's create/delete callbacks and matches WP's practical behaviour
   (its admin UI does not author assistants over REST). Recorded as a divergence, not
   an oversight.
6. **`guest_token_lifetime` stays out of scope.** It needs a guest surface, not a
   principal; 149 §117 already separates the two.

## 6. Rules that are part of the decision

1. A machine token never exceeds its scope, even when its owner is the estate owner.
2. Scope is checked at the route. A handler never sees an out-of-scope request.
3. Minting requires `manage_options` **in the issuing estate**, and writes an
   `AdminAuditLogger::recordResource()` row naming the scope — Livewire and API
   traffic are outside the request middleware, so an unrecorded mint leaves no trace.
4. Revocation is deletion. There is no "revoked but still valid", because Sanctum
   re-reads the table per request.
5. A machine principal cannot mint another machine principal. Otherwise one leaked
   scope-holder can widen its own reach.
6. A machine principal is never matched by email. Every identity question goes
   through the subject link, or through the scope — never a mutable claim.
7. `assistant:{id}` implies the assistant's estate. It never implies a second one.

## 7. What this changes in the record

| Document | Change |
|---|---|
| [158](158-parity-open-decisions.md) D6 | answered for the three `rest_enable_assistant_*` keys; `guest_token_lifetime` keeps its own obstacle |
| [149](149-laravel-admin-parity-implementation-plan.md) §117.2 | `/api/v1/tools` claim corrected: it *is* tenant-resolved and *does* accept Auth0 tokens |
| `contracts/surface-ownership.json`, `identity-provider` | the "takes no Auth0 tokens yet" tail replaced |
| 149 §102.4 item 3 | left as the dated record; superseded here |
| 149 §119.4 | its open question is answered by §10: default-**deny**; the "belongs with the route annotation, not before it" leaning is reversed |

## 8. Slices, in order

1. **Scope vocabulary and the middleware**, with tests and no UI: encode
   `assistant:{id}` in `abilities`, add `scope:` to the route group, prove that an
   out-of-scope token gets 403 and an in-scope one passes. Nothing is enabled by this.
2. **Wire the three settings** to external callers only, using WP's defaults
   (`list` true, `create`/`delete` false), and make the keys non-inert. This slice
   also installs **default-deny** from §10: one `api`-group attach, priority after
   `Authenticate`, declarations read from the route, `scope:any` for self routes, and
   the ordering test that proves it is not a no-op.
3. **Minting**, first as a console command (the `nvoos:auth0-link` precedent), then a
   panel form if it earns one.
4. **Tool execution for assistant scope**, which is a new route and its own decision
   about which tools are safe for a machine.

## 9. Risks, and what would change this decision

- **MCP's own OAuth 2.1 profile** may make *audience* (RFC 8707) the right axis
  rather than a scope string. If MCP clients are the main consumer, adopt the
  resource indicator as the scope carrier instead of inventing `assistant:{id}`.
- **An estate that wants one integration to see everything** would make B
  insufficient on day one — the answer is C's `estate:` scopes, granted explicitly,
  not a widening of B.
- **Replaying WP's create/delete semantics exactly** (gating first-party too) would
  be simpler to justify against the WordPress surface and worse to use. If a
  customer's expectation is WP parity rather than WP behaviour, revisit rule 5 in
  §5.
- **If tool execution for machines turns out to be a product requirement now**,
  slice 4 moves ahead of 2 and 3, because it is the only part with an action in it.

## 10. Decision: a scoped credential is default-denied on an unannotated route

**Taken 2026-11-21.** A credential that carries a scope is admitted **only** by a
route that declares which scope it needs. A route that declares nothing still admits
sessions, Auth0 users and `['*']` tokens exactly as before, and refuses a scoped
token with the 403 shape slice 1 already returns for a blank requirement
(`principal_scope_required`, naming `required` and `granted`).

This answers the question [149](149-laravel-admin-parity-implementation-plan.md)
§119.4 deliberately left open, and reverses the leaning recorded there.

### 10.1 Why the safer default is also the honest one

| Rule | A forgotten annotation becomes | Who notices |
|---|---|---|
| Default-**allow** | a silent escalation: the credential reaches more than it was minted for | nobody, until an incident |
| Default-**deny** | an outage on one route for scoped tokens | the integration, in seconds |

Both rules fail somewhere; they differ in *direction*. The purpose of a scoped
credential is to be narrow, so closure is the feature rather than the tax. The
comparison is asymmetric in a second way too: default-allow's failure is invisible,
while default-deny's failure is a 403 with the route named in it.

The counter-argument is real and is kept: default-deny means **every route a machine
should reach must carry a declaration**, and the rule protects nothing on a route the
middleware was never attached to. §10.3 is therefore part of the decision rather than
an implementation note — a default-deny attached in the wrong place is
indistinguishable from default-allow.

### 10.2 What it costs, measured

Measured in this runtime on 2026-11-21 (`php artisan route:list --json`):

| | Count |
|---|---|
| Registered routes | 240 |
| Under `api/` | 166 |
| API routes behind `auth:sanctum,auth0` — the machine-reachable set | **159** |
| `scope:` declarations in `routes/` today | **0** |

159 reads like a large annotation bill, but it is not one. Those routes come from
**one** machine-reachable group (`routes/api.php` line 118) plus a handful of nested
groups that already exist for other reasons (`throttle:chat`, `throttle:console`,
`org`, `pro/*`). Annotation cost tracks *the number of distinct scopes*, not the
number of routes: one declaration on a group covers every route inside it, and a
nested group needs its own only where it wants something narrower than its parent.

The realistic slice-2 shape is therefore one attach plus declarations on the subtrees
a machine is actually for (the assistant routes), not 159 edits.

### 10.3 The implementation shape — and the trap that would make it a no-op

Two properties of Laravel's pipeline decide whether "default-deny" is real:

1. **Middleware is additive.** Attaching `scope` with no arguments at an outer group
   and `scope:assistant` at an inner one does not mean "override" — *both* run, and
   the outer one denies everything. Default-deny cannot be expressed as a bare outer
   attach with inner overrides.
2. **`forRequest(null)` is unrestricted.** `PrincipalScope::forRequest()` returns
   `unrestricted()` when there is no user, so a `scope` middleware running *before*
   `auth:sanctum` reads no principal, allows everything, and silently enforces
   nothing.

Point 2 is the trap. Route-level middleware runs after group middleware, and
`SortedMiddleware` only reorders middleware named in the priority list; anything
unlisted keeps its gathered position. So the obvious
`$middleware->appendToGroup('api', EnsurePrincipalScope::class)` would place the guard
**before** the route's own `auth:sanctum` and do nothing at all — while looking
installed. The ordering must be stated, not assumed.

So the rule is carried by data rather than by a second guard:

1. Attach `EnsurePrincipalScope` **once** on the `api` group
   (`$middleware->appendToGroup('api', …)`) — the group a Sanctum token can
   authenticate on at all, so coverage is total rather than "where we remembered".
2. Name it in `$middleware->priority([...])` **after**
   `Illuminate\Auth\Middleware\Authenticate`, so it observes the resolved user.
3. The middleware reads the route's own declarations from
   `$request->route()->gatherMiddleware()` (verified present; it merges route and
   controller middleware and dedupes by the full string, so `scope` and
   `scope:assistant` coexist) and enforces those.
4. **No declaration ⇒ denied** for a scoped principal. That is the same code path
   §119.3 already tests for a blank requirement; slice 2 wires it, it does not
   invent it.
5. One test must prove the ordering, because the failure mode is silence: a scoped
   token reaches an annotated route (200), is refused an unannotated one (403), and
   an unauthenticated request is still 401 — auth first, so a missing credential is
   never disguised as a 403.

One consequence of that shape is easy to get backwards. The group instance is itself
one of the entries `gatherMiddleware()` returns, and it carries no arguments — so it
must collect only the **non-blank** requirements and ignore its own entry. Reading
that blank entry as "this route declared a scope, and it is empty" would deny the very
routes that had just declared one: a guard failing closed, so it looks safe, while
refusing exactly the traffic it was installed for.

With that in place, a route-level `scope:` is purely declarative — the group instance
reads it, and the duplicate route-level run reaches the same verdict. That is why the
declarations can stay in the route file where reviewers see them, without a second
enforcement point to keep in sync.

### 10.4 The escape hatch is a declaration, not a bypass

Default-deny needs somewhere to say "any scoped principal may pass" — `/session/me`
is the obvious case, since a machine reading its own identity has no narrower scope
that makes sense. That is `scope:any`: explicit, greppable, and reviewed in the route
file beside every other declaration. It needs a one-line special case in
`PrincipalScope::allows()`, because `any` is not otherwise a valid capability; that is
the only place the vocabulary grows.

The hatch is deliberately *not* a config allowlist of URIs. An allowlist lives outside
the route it protects, so the two drift apart and the route reads as annotated when it
is not.

### 10.5 Blast radius today: zero credentials

No minting path writes a scope. Both minters (`NvoosSpikeToken` and the admin personal
token page via `PersonalTokens`) produce Sanctum's default `['*']`, and
`PersonalTokens` documents that as the stored truth. So this decision, applied today,
refuses **no request that currently succeeds**. It becomes load-bearing the moment
slice 3 mints the first `assistant:{id}` token — which is exactly why it is decided
before slice 2 rather than after.

### 10.6 What stays out of band, honestly

- **`web.php`, Filament and Livewire.** A Sanctum token does not authenticate there,
  so there is nothing for the guard to refuse. Machine tokens are an API surface.
- **`Route::fallback` and unmatched URIs** never reach a route object, so the
  middleware does not run. A scoped token hitting an unknown path gets the usual 404.
  This is not a hole — there is no handler — but it does mean "default-deny" is a
  statement about *routes*, not about *paths*.
- **Non-HTTP surfaces** (console commands, queues) have no route and are governed by
  their own gates, not by this one.

### 10.7 How to audit for an unannotated route

Because a missing declaration is now an outage rather than a silent permission, the
audit has to be cheap enough to run in review:

```sh
# routes a scoped principal would be denied on
php artisan route:list --json \
  | jq -r '.[] | select(.uri|startswith("api/")) | .uri' | sort
grep -rn "scope:" routes/
```

Any URI in the first list whose group carries no declaration is denied by design. The
invariant worth pinning in CI is weaker and safer than "every route annotated": **the
set of unannotated routes must not grow** — a deliberate snapshot, refreshed in the
same PR that adds a route.

### 10.8 What would change this decision

- **If a machine needs a broad surface on day one** (a sync integration touching most
  of `api/v1`), the annotation bill is paid once at the group and stays cheap. If it
  instead needs *many distinct* scopes, revisit §5's option C rather than widening
  `assistant`.
- **If the unannotated-set snapshot keeps needing refreshes** for routes no machine
  will ever call, the rule is being paid for by the wrong population; reconsider a
  narrow attach plus an enumerated denylist.
- **If MCP's OAuth 2.1 profile lands** (§9), audience becomes the axis; this section's
  mechanics survive but its vocabulary does not.
