# 158 — The parity open decisions

**Status:** decided, 2026-11-20.
**Decides:** the six questions [149](149-laravel-admin-parity-surface-ownership.md) §9
and [157](157-oidc-sso-design.md) §8 left open, plus the `dashboards` merge
referred to by [149-dashboards-redundancy-review](149-dashboards-redundancy-review.md)
§4.
**Does not decide:** anything about implementation order beyond §9, and nothing
about WordPress-side behaviour except where D1 forces it.

Decisions are numbered **D1–D6 in this document**. They extend
[156](156-identity-decision.md)'s D1–D8 and [157](157-oidc-sso-design.md)'s
I1–I5b; where a decision rests on one of those, it says so rather than restating
it.

---

## 1. What was open, and who was waiting

Six questions, from three sources. None of them is an engineering question; each
turns on a product judgement that the code cannot make for itself.

| # | Question | Source | Waiting |
|---|---|---|---|
| D1 | Do the ten dashboards become seven, and if so how? | 149-dashboards-redundancy-review §4 | the `dashboards` row (Wave 5) |
| D2 | Is just-in-time provisioning on or off by default? | 157 §8 q1 | I5b |
| D3 | Where does the per-estate domain allowlist live? | 157 §8 q2 | I5b |
| D4 | What does a provisioned subject get? | 157 §8 q3 | I5b |
| D5 | Cross-estate view, and "view as tenant"? | 149 §9 S-2, S-4 | the operator model |
| D6 | Do the four inert identity keys get readers now? | 149 §9, plan §104 | I5 |

S-1 ("render operator-scope fields disabled with a hint, or omit them?") is not in
this list because it was answered in practice: plan §104 split the surface into
**disabled-with-a-reason** for a key this runtime could honour but does not read
yet, and **hidden** for a key that configures software this runtime does not have.
This document records that as closed (§D5) rather than leaving it dangling.

---

## 2. D1 — `dashboards`: accept 10 → 7, and ship the aliases with it

**Decision: accept the redundancy review's §4 exactly.** Two merges, five
unchanged surfaces:

| New surface | Replaces | Shape |
|---|---|---|
| Connector health | `ezuite`, `flowhub`, `shopify` (3 → 1) | one panel per connection; the two inventory panels share a spec, Shopify adds its budget group |
| Member metrics | `health`, `vitals` (2 → 1) | one dashboard, section visibility driven by the two toolkit flags |
| CRE Debt Portfolio | `cre` | unchanged |
| Law Firm | `law` | unchanged |
| Registration | `registration` | unchanged |
| ECA | `eca` | unchanged |
| AI Observability | `observability` | unchanged |

### 2.1 Why not 10 → 3

The original framing proposed three surfaces. The review's inventory kills it,
and the published practice agrees with the review: consolidation should happen
around **the underlying question**, not around the source system or the toolkit
flag — "dozens of items collapse into three or four underlying questions, asked
with different filters by different audiences"
([ORM][d1a]).

`cre`, `law`, `registration` and `eca` share a *render contract* —
`filters` / `metrics` / `charts` / `lists` — and nothing else: different filters
(`loan_type`/`status` vs none vs none vs `day`/`eca_type`), different metrics,
different audiences. Collapsing them would remove four domains and produce a page
that is a navigation menu with a chart in it. They are a **renderer**
consolidation, which Wave 3's manifest already plans, not a dashboard one.

[d1a]: https://orm-tech.com/blog/how-to-consolidate-sales-dashboards/

### 2.2 Three conditions, all of them about not breaking people

1. **Alias the retired ids.** A dashboard id appears in console URLs and in
   WordPress dashboard-widget registration. `ezuite`, `flowhub` and `health`
   therefore need a redirect or an alias map in the same change that retires
   them — a deletion alone turns a saved link into a 404 with no explanation.
   This is the condition most likely to be dropped and most likely to be noticed
   by a user.
2. **WordPress first, in the same change.** The catalogue is read by both
   runtimes and the labels must match; merging only the Laravel side breaks parity
   on the first request. The review says this in §5 and it is right — this is a
   **Wave 5, cross-runtime** task, not a port task in the Laravel lane.
3. **Fix the number before anyone implements it.** The review's §4 says 10 → 7,
   the contract row says 10 → 7, and the review's §6 says "the 10 → 8
   recommendation". Two merges (3 → 1 and 2 → 1) give seven. §6 is corrected with
   this document so the stale figure cannot be the one someone reads.

### 2.3 What does not change

The contract row stays `dashboards` / `admin: planned`. This decision makes the
*shape* of the future row known; it does not build it. `DashboardsTest` continues
to pin the disabled entries and their toolkit flags — changing that set is a
deliberate test change that belongs to the merge, not a side effect of this
document.

---

## 3. D2 — JIT stays off by default (extends 156 D4)

**Decision: just-in-time provisioning is off by default, and enabled per estate.**

Published guidance on this is unusually unanimous. The default role for a
provisioned user should be **the lowest possible privilege level**, on the
reasoning that "it's safer to have a dev ask for more access than to accidentally
give a contractor admin rights" ([Security Boulevard][d2a]). And an application
that cannot establish an entitlement should **fail closed** rather than "silently
grant a broad default role" ([ActiKnow][d2b]).

[d2a]: https://securityboulevard.com/2026/01/just-in-time-jit-provisioning-how-automated-user-provisioning-works-in-sso/
[d2b]: https://developerlabs.actiknow.com/custom-software-development/b2b-saas-single-sign-on-saml-oidc/

156 D4 already says deny-by-default; this confirms the *default* rather than the
mechanism. When an estate opts in, 157's gate stands unchanged:
`email_verified` **and** an entry in the estate's domain allowlist (D3), never a
match by mutable email.

### 3.1 The condition that keeps this decision from being reversed

Off-by-default has a failure mode that gets it undone: the user authenticates
successfully and then sees a generic 403, which reads as *broken*, not as
*not yet granted*. Support pressure then produces a request to "just turn it on
for everyone", which is the outcome D4 exists to prevent.

So **the unprovisioned-but-authenticated state must be named and visible**: the
subject gets a distinct outcome ("authenticated — awaiting membership"), and an
estate administrator can see a list of those subjects and promote one. The dead
end is the design; an unexplained dead end is the bug.

---

## 4. D3 — The allowlist is a companion table, not a setting and not an env var

**Decision: a per-estate table, `nvoos_estate_domains`.**

Guidance says the allowed-domain list should be a **visible, per-tenant** thing
that an estate can administer, and that verified domains should be registered and
enforced ([SSOJet][d3a]; the same pattern appears in vendor hardening guides —
"register your verified domains and restrict allowed email domains to corporate"
([Claude Enterprise][d3b])).

[d3a]: https://ssojet.com/blog/email-domain-discovery-routing-multi-tenant-sso
[d3b]: https://platformsecurity.com/blog/how-to-secure-your-claude-enterprise-tenant

### 4.1 Why not a setting

`nvoos_tenant_settings` is `(tenant_id, key) PRIMARY KEY, value TEXT` — a scalar
store. The settings catalogue has no list type, so a domain list stored there
means one of two worse things:

- **JSON in a text column.** Every reader decodes, uniqueness is unenforceable,
  and a malformed row fails on the *authentication* path rather than at write
  time.
- **A new list type** threaded through the catalogue, the writer, the admin
  renderer and `writableFor()`. That is a larger change than a table, and it buys
  nothing the table does not already give.

### 4.2 Why not an env var

An env var is process-global. This runtime resolves an estate per request
(`TenantContext`), so a per-estate allowlist in the environment would have to be
re-read and re-parsed on every login, could not be edited by the estate it
belongs to, and could not record who added a domain. It is the right shape for a
single-estate deployment and the wrong shape for this one.

### 4.3 The shape

```
nvoos_estate_domains
  id            bigint
  tenant_id     string(64)   indexed
  domain        string(255)  -- stored lowercase, A-label (punycode)
  verified_at   timestamp    nullable
  created_by    bigint       nullable
  created_at    timestamp
  UNIQUE (tenant_id, domain)
```

Four rules that are part of the decision, not implementation detail:

1. **Normalise before storing and before comparing.** Lowercase, then IDN to
   A-label, so `München.de` and `xn--mnchen-3ya.de` are one domain and cannot be
   used to bypass the list.
2. **Exact match on the domain after the last `@`.** Never a substring or suffix
   match — `notacme.com` must not satisfy `acme.com`.
3. **No subdomain inheritance by default.** Whether a claim on `acme.com` covers
   `eng.acme.com` is a real decision with a real risk: inheriting hands routing
   authority to anyone in the organisation who can create a subdomain. Make it an
   explicit per-estate flag, **default off**.
4. **`verified_at` is required for provisioning.** A null `verified_at` is a
   domain that has been proposed but not confirmed; it gates nothing. Fail closed.

The reader is **one indexed lookup on the auth path**, cached per request and not
process-wide — the `TenantContext` rule (§106.2's neighbourhood): an Octane worker
must not carry one estate's allowlist into the next request.

---

## 5. D4 — A provisioned subject gets `viewer`, in the issuing estate, and nothing else

**Decision: `viewer`, membership of the estate whose login flow issued the
assertion, no cross-estate membership, no role derived from IdP claims.**

Three parts, each deliberate:

- **`viewer`, not a higher default.** This is D2's reasoning applied to the role:
  the lowest level first, promotion on request.
- **The issuing estate only.** A subject provisioned through estate A's
  connection must not arrive in estate B, even if the IdP asserts an attribute
  that looks like a tenant. Cross-estate membership is a grant someone makes, not
  something a login confers.
- **No role from claims.** Mapping IdP groups to roles is the classic
  privilege-escalation path: it hands role assignment to whoever administers the
  group mappings, which is usually a different team from the one accountable for
  the estate. If a customer requires it later, it needs its own decision with its
  own constraints.

The first-login dead end — a `viewer` who cannot do their job until someone acts —
**is the design**, not a gap. It is resolved by D2's pending-promotion surface,
where the promotion is an explicit, attributable action. On the panel that action
must call `AdminAuditLogger::recordResource()` itself: Livewire traffic is excluded
from the request middleware (plan §25), so a promotion with no explicit record
leaves no trace.

---

## 6. D5 — S-2 and S-4, and the closure of S-1

### S-2 — No cross-estate view on the console

**Decision: no.** The console is a tenant surface; an operator already has the
cross-estate view on `/ops`. Adding one to the console would create a surface with
a different audience inside a panel scoped to one estate, which is the two-
hierarchy model (149 §3) failing at its seam. Revisit only on a named client
requirement, and build it on `/ops` when that arrives.

### S-4 — No "view as tenant", and never as impersonation

**Decision: not in v1.** If it is ever built, it is a **separate read
projection with its own audit row**, never a session swap. Impersonation makes the
audit trail unable to answer "who did this", which defeats the reason the audit
trail exists; it also breaks the Q-1 trap. This is a stronger position than
"deferred" and should be read as a standing constraint.

### S-1 — Closed by plan §104

"Render operator-scope fields disabled with a hint, or omit them?" was answered by
splitting the question. A key this runtime **could** honour but does not yet read
renders disabled, refuses writes, and names its obstacle
(`UnimplementedSettings`). A key that configures software this runtime **does not
have** is hidden (`InapplicableSettings`) — a greyed control still asserts the
capability could be built here, and for a WordPress.com identity bridge that is
simply false. Recorded as closed; no further decision needed.

---

## 7. D6 — The four inert identity keys stay inert until I5

**Decision: leave them.** `rest_enable_assistant_list/create/delete` and
`guest_token_lifetime` keep their current state: rendered disabled, refused by
`writableFor('admin')`, each naming its obstacle.

The reason is scope, not inertia. The three assistant-REST gates need a
first-party-versus-token-call split, which is a **machine-principal model**, and
157 records that `/api/v1/tools` takes no Auth0 tokens yet for exactly that
reason — the scope question belongs with I5. Answering it before I5 would bake in
a principal model the SSO work is about to change. `guest_token_lifetime` bounds
an anonymous chat this runtime does not have.

The existing rule still applies and is the exit condition: **a key leaves the
inert list the moment it gains a reader.**

### 7.1 Status, 2026-11-20 — I5 shipped, and this decision survives it

The premise above was "wait for I5". I5 has now shipped: OIDC just-in-time
provisioning, deny-by-default, gated on `email_verified` **and** a verified
per-estate domain allowlist, granting `viewer` in the issuing estate and nothing
else, with a pending-request surface and a daily digest (plan §110–§111, extended
by §116).

**It provisioned people. It did not produce a machine-principal model.** So the
four keys stay inert for the same obstacle, and no longer because a slice is
pending. Nothing in the runtime distinguishes a browser principal from a token
principal — `first_party`, `machine_principal` and `service_account` appear
nowhere under `app/` — the `/api/v1/tools` group still takes no Auth0 tokens, and
there is no guest-token path for `guest_token_lifetime` to bound.

What would change that is a decision, not a dependency: whether a machine
principal is assistant-scoped or estate-scoped. It is now its own document rather
than something the SSO work was going to settle.

---

## 8. What this changes in the record

| Document | Section | Change |
|---|---|---|
| [149-dashboards-redundancy-review](149-dashboards-redundancy-review.md) | §6 | "10 → 8" corrected to 10 → 7; the decision is recorded |
| [149-laravel-admin-parity-surface-ownership](149-laravel-admin-parity-surface-ownership.md) | §9 | S-1 closed; S-2 and S-4 answered by D5 |
| [157-oidc-sso-design](157-oidc-sso-design.md) | §8 | the three questions are answered by D2–D4; I5b is no longer blocked on a decision |
| [149 implementation plan](149-laravel-admin-parity-implementation-plan.md) | §107, §108 | the handover's "blocked on a decision" entries point here |
| `contracts/surface-ownership.json` | `dashboards` row | the note records the decision and that it is not yet implemented |

---

## 9. Slices, in order

1. **`media`** — unchanged and still first. It needs no decision, and it starts
   with `nvoos_files.tenant_id` (plan §107.1).
2. **I5b, as one slice** — the `nvoos_estate_domains` migration, the auth-path
   read, the `viewer` default, and the pending-promotion state. One slice because
   D3's table is useless without D2's gate and D4's role, and shipping the table
   alone would read as "JIT is coming" while nothing enforces it.
3. **`dashboards`, Wave 5** — cross-runtime, WordPress first, aliases in the same
   change. Separate lane; do not interleave with (2).
4. **`phpcompatibility/phpcompatibility-wp`**, opportunistically — it blocks no
   feature, but every enum in the repository fails `PHP Compatibility Check` the
   moment it is edited (plan §106.10).

---

## 10. Risks

| Risk | Mitigation |
|---|---|
| JIT off-by-default produces support load and gets reversed | the pending state is named and listable (§3.1); the dead end is visible, not silent |
| An estate administrator adds a broad domain (or a public one) and provisions strangers | `verified_at` required, `created_by` recorded, no subdomain inheritance by default; a public-suffix check at write time is worth adding |
| `viewer` makes first login useless and people work around it | pending-promotion surface, audited; if it still hurts, that is a request for a different *default role*, which is a new decision, not an argument for turning JIT off |
| Retired dashboard ids 404 saved links | §2.2's alias map, shipped in the same change |
| Dashboard owners resist retiring their dashboard | name an owner per surviving surface and communicate the consolidation, which is the published practice ([TechTarget][d10]) |

[d10]: https://www.techtarget.com/data-technologies/tip/Why-a-dashboard-audit-matters-before-BI-cleanup

---

## 11. What would change these decisions

- **A customer needing multi-domain organisations** (holding companies, acquired
  brands) would reopen D3's "no subdomain inheritance" flag — but as configuration,
  not as a changed default.
- **An IdP-driven role requirement as a contractual constraint** would reopen D4,
  and would need its own document: which claim, which roles, who owns the mapping,
  and what the estate can do when the IdP is wrong.
- **Usage evidence that a merged dashboard is a daily surface for two different
  audiences** would reopen the split in D1. The review's inventory is a
  static analysis; real per-surface usage would beat it.
- **A PHPCompatibility release that parses enums** retires §9 item 4 and the
  suppression in `AdminNavigationGroup` immediately.

---

## References

1. [149 — Laravel admin parity](149-laravel-admin-parity-implementation-plan.md)
2. [149 — surface ownership](149-laravel-admin-parity-surface-ownership.md) §9
3. [149 — dashboards redundancy review](149-dashboards-redundancy-review.md)
4. [156 — identity decision](156-identity-decision.md) D4
5. [157 — OIDC SSO design](157-oidc-sso-design.md) §8
