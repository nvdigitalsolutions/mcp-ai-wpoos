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

## 8. Slices, in order

1. **Scope vocabulary and the middleware**, with tests and no UI: encode
   `assistant:{id}` in `abilities`, add `scope:` to the route group, prove that an
   out-of-scope token gets 403 and an in-scope one passes. Nothing is enabled by this.
2. **Wire the three settings** to external callers only, using WP's defaults
   (`list` true, `create`/`delete` false), and make the keys non-inert.
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
