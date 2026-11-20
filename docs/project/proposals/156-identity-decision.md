# Proposal 156 — Identity: the surface was never missing, the behaviour is

**Status:** decided 2026-11-20. Recommendation is **Option B** (reclassify and
gate, then build one identity behaviour at a time). Slice I1 is a contract
correction; no behaviour ships in this proposal.

**Related:** [Proposal 149](149-laravel-admin-parity-implementation-plan.md) §36
(the original "nothing to port" finding, corrected here), §85.3, §14.2 (a
console surface is not admin parity), §17 (`/ops` has no estate selected), and
[Proposal 155](155-metrics-decision.md) for the "show the gap, never a zero"
discipline this borrows.

## 1. What §36 said, and why it was wrong

§36 recorded that a grep for `identity_provider` / `IdentityProvider` / `saml` /
`openid` / `oidc` across the WordPress plugin "returns only an A2A agent card",
and concluded that no identity surface exists on either runtime.

The conclusion does not survive the search terms. The WordPress surface is built
under the name **Auth0**, which none of those five patterns matches:

| Present on WordPress | Path |
|---|---|
| `WP_MCP_AI_Auth0_Setup` — 415 lines, its own admin submenu page | `includes/admin/class-wp-mcp-ai-auth0-setup.php` |
| Auth0 bearer verification — discovery, JWKS fetch + cache, `iss`/`aud`/scope checks | `includes/rest/class-wp-mcp-ai-rest-authenticator.php` |
| Auth0 subject → WordPress user bridge (userinfo, Management API) | `includes/integrations/class-wp-mcp-ai-integration-auth0-github.php` |
| A published OIDC pointer for agents | `includes/a2a/class-wp-mcp-ai-a2a-agent-card.php` |
| The section that configures all of it | `includes/admin/sections/class-wp-mcp-ai-section-authentication.php` |

This is §95's lesson repeating: the earlier finding searched a plausible name,
not the actual one. A negative result from a name-guess is not evidence of
absence.

## 2. What the WordPress surface actually is

`class-wp-mcp-ai-section-authentication.php` is a seven-sub-tab settings section.
The sub-tabs are the real shape, and they are not seven flavours of one thing:

| Sub-tab | Keys | What it configures |
|---|---|---|
| Auth0 Configuration | `auth0_domain`, `auth0_audience`, `auth0_required_scope` | a trusted token issuer |
| GitHub Bridge | `enable_auth0_github_bridge`, `auth0_management_client_id`, `auth0_management_client_secret` | subject → user resolution |
| WordPress.com/Gravatar | `enable_wordpress_gravatar_bridge`, `wordpress_gravatar_userinfo_endpoint` | a second subject → user resolution |
| Simple JWT Login | `enable_simple_jwt_login` | a third-party plugin's tokens |
| Guest Access | `guest_token_lifetime`, `credential_lifetime_days` | token lifetimes |
| REST API Capabilities | `rest_enable_assistant_list/create/delete`, `sse_enable_post_method`, `oauth_disable_open_registration`, `require_confirm_destructive_ops` | API policy, not identity |
| Magic Link Sign-In | `enable_magic_login`, `magic_login_app_origin` | a first-party passwordless login |

Separately, `admin.php?page=wp-mcp-ai-auth0-setup` is a one-page wizard whose
`wp_mcp_ai_auto_configure_auth0` handler accepts a pasted bearer token, decodes
the JWT **payload without verifying it**, and persists the issuer host as
`auth0_domain` plus `aud[0]` as `auth0_audience`.

So the row called "Identity provider setup" is really four different things
wearing one title: **an issuer trust list**, **two identity-resolution bridges**,
**API policy toggles**, and **one login method**.

## 3. What this runtime already has

All nineteen keys are present in the generated catalogue, on the
`authentication` tab, in seven sections that match the WordPress sub-tabs
one-for-one, at **tenant** scope, with `console: hidden`:

| WordPress sub-tab | Console section id | Keys |
|---|---|---|
| Auth0 Configuration | `authentication__auth0` | 3 |
| GitHub Bridge | `authentication__auth0_github` | 3 |
| WordPress.com/Gravatar | `authentication__wpcom_gravatar` | 2 |
| Simple JWT Login | `authentication__jwt` | 1 |
| Guest Access | `authentication__guest` | 2 |
| REST API Capabilities | `authentication__rest_api` | 6 |
| Magic Link Sign-In | `authentication__magic_link` | 2 |

They render on `/admin/settings` — `SettingsSchema::forSurface('admin')` returns
every field, and `BuildsSettingsForm` draws one Filament tab per catalogue tab
with one section per `section_id`. They are deliberately **not** on `/ops`:
`forSurface('ops')` admits `scope === 'operator'` only, and §17's rule is that a
tenant field must never appear on a panel with no estate selected.

### 3.1 The finding that decides this proposal

**No runtime code reads any of the nineteen keys.** A grep for each across
`app/`, `config/`, `routes/` and `database/` returns zero files for all nineteen.
The administrator can set an Auth0 domain, a required scope, a GitHub bridge, a
magic-link origin and six API policies, save them, and every one of those values
lands in a settings row that nothing consults.

`DiagnosticsController` makes the contradiction explicit: it reports
`auth0_configured => false` as a literal, so the diagnostics page says "not
configured" no matter what the form contains.

`SettingsSchema`'s own docblock names this failure: conflating what a surface
*shows* with what it *does* "is how a reduced surface becomes a UI fiction". The
form is currently the fiction.

The runtime's real authentication model is narrower than the nineteen fields
imply: `config/auth.php` defines the session `web` guard only, plus Sanctum for
estate, assistant and personal tokens. There is no second identity system, no
guest token, and no magic link.

## 4. The options

**A — port all the behaviour.** Build Auth0 JWKS verification, the GitHub bridge,
the WordPress.com/Gravatar bridge, Simple JWT Login, guest tokens, magic links
and the six API policies.

Rejected. Three of those are WordPress-plugin-shaped by construction:
`enable_simple_jwt_login` validates tokens issued by a *WordPress plugin* that
does not exist here; `enable_wordpress_gravatar_bridge` resolves *WordPress.com*
identities; guest tokens exist to let an anonymous visitor chat on a public
WordPress page. Porting them would add three identity systems to a runtime whose
value is that it has one.

**B — reclassify, gate the fiction, build one behaviour at a time.** ★

**C — leave the row `planned`.** Rejected: the form is already live and
editable. `planned` describes a surface that is not built, and this one is half
built in the most expensive direction — it accepts writes.

## 5. Recommendation — B, with these decisions

### D1 — The row is mis-scoped: it is a tenant surface, not an operator one

Every one of the nineteen keys is `scope: tenant` in the catalogue, because each
is an estate's own trust configuration. An operator on `/ops` has no estate
selected, so rendering them there would edit `default` while appearing to edit
"the platform" — the exact failure §17 was written to prevent.

The contract row changes from `audience: operator · ops: planned · writer: none`
to `audience: tenant-admin · ops: null · admin: inert · writer: admin`.

### D2 — The state is `inert`, a new vocabulary entry

The closed vocabulary had no word for this row. `subset` was the closest, and it
is wrong twice over: it means "rendered, reduced field set" (this field set is
complete) and the audit counts it as **built**, so correcting a documentation
row would have moved the burn-down from 36 to 37 built without a line of
behaviour changing. That is the inflation §14 exists to prevent.

So the vocabulary gains one state:

> **`inert`** — rendered and writable, but no runtime code reads the value; the
> form is not yet a behaviour.

It is not `planned` (the form is live, and administrators can already write to
it) and not `absent` (it is present, and it is the shape parity will take). Its
`projection` is mandatory, on the same reasoning as R3: an inert surface must
say what it renders and what is missing.

The audit script gains an `inert` line and stops before counting it as built;
`SurfaceOwnershipTest` requires a `projection` for it.

### D3 — If SSO is built, it is OIDC first, and SAML is a separate increment

Industry practice is unambiguous here: where a tenancy model already supports
per-tenant configuration, "OIDC with just-in-time provisioning is a contained
piece of work and SAML plus SCIM follow as separate increments" ([Softonoma][1]).
This model does support per-tenant configuration — the fields are already
tenant-scoped rows.

[1]: https://www.softonoma.com/blog/enterprise-sso-multi-tenant-saas

So: authorization code + **PKCE only**, discovery from
`https://{auth0_domain}/.well-known/openid-configuration`, verify `iss` + `aud` +
RS256 against **that tenant's** JWKS, no implicit flow, no ID-token-as-API-token.
SAML is not built until a customer requires it, and then as its own proposal.

### D4 — Provisioning is deny-by-default, and never links by email

The published anti-pattern list is the specification ([Pathrule][2]): selecting a
tenant from an **unverified** email domain, accepting an assertion for the wrong
audience, linking accounts by **mutable email alone**, and keeping access after
the IdP deprovisions a user.

[2]: https://www.pathrule.io/patterns/enterprise-sso-saml-oidc

Therefore: a subject with no existing membership is **denied**; just-in-time
creation is opt-in only, gated on `email_verified` **and** an explicit per-estate
domain allowlist ([OneUptime][3]); a subject is never matched to an existing user
by email; membership removal is idempotent and re-evaluated on every login.

[3]: https://oneuptime.com/blog/post/2026-01-30-just-in-time-provisioning/view

### D5 — Five of the seven sub-tabs are not identity, and are re-homed

`rest_enable_assistant_list/create/delete`, `sse_enable_post_method`,
`oauth_disable_open_registration` and `require_confirm_destructive_ops` are **API
and tool policy**. Two of them already have a different mechanism here — write
approval (`App\Models\Approval`, `/admin/approvals`) satisfies the intent of
`require_confirm_destructive_ops`, and `lib/core`'s `ReadOnlyToolGuard` enforces
read-only tool surfaces. They belong to a security-policy decision, not this one.

`enable_simple_jwt_login` and `enable_wordpress_gravatar_bridge` are recorded
**out of scope**: they configure software this runtime does not have.

That leaves three things that are genuinely identity: issuer trust (Auth0),
magic-link login, and provisioning.

### D6 — The one-click wizard is not ported as-is

Its only real function is deriving `auth0_domain` and `auth0_audience` from an
**unverified** JWT payload. On a tenant-scoped runtime that is an unverified
input writing a security-relevant value, and the value it derives is the very
thing later assertions are validated against — deriving trust from an artifact
you have not validated is backwards.

If a helper is ever wanted, it must verify against a domain the operator typed
first, not infer the domain from the token. Recommendation: no wizard. Two text
fields and a deep link to the Auth0 dashboard.

### D7 — Magic link is its own decision

Magic-link sign-in is the only piece of the WordPress surface that is a
**first-party login for the console itself**, and its WordPress design is sound:
a hashed single-use token, 15-minute expiry, rate-limited requests, and uniform
responses to prevent account enumeration. It is also the cheapest way to let a
console user in without WordPress credentials.

It is still a separate decision — a login path is not a settings field — and it
should be argued on whether console login without WordPress is wanted.

### D8 — The dead toggles are marked, not hidden

Hiding the nineteen fields would make the form silently diverge from WordPress,
which is the drift §14.2 exists to catch. The S-1 pattern already solves this for
operator-scope fields on `/admin`: render them **disabled with a hint**. The same
treatment applies here — the capability exists upstream and is not implemented in
this runtime, and the administrator is told so.

## 6. Slices

| Slice | Scope | State |
|---|---|---|
| **I1** | This proposal; `inert` added to the contract vocabulary, the audit and the test; the row corrected; §100 in Proposal 149 | **shipped** — plan §100 |
| **I2** | A runtime `UnimplementedSettings` list, disabled-with-hint rendering, writes refused, and a source-scan test that fails when a marked key gains a reader | **shipped** — plan §101 |
| **I3** | Auth0 bearer verification on the estate API — JWKS, `iss`/`aud`/scope, no session, no provisioning. Makes `auth0_domain`, `auth0_audience`, `auth0_required_scope` real | **shipped** — plan §102 |
| **I4** | Magic-link sign-in, if D7 is accepted | **shipped** — plan §103 |
| **I5** | OIDC SSO with deny-by-default JIT (D3, D4) — only when a customer requires console SSO | product call |

I3 before I5 matters: accepting a bearer token on the API needs no browser
session, no membership mapping and no provisioning rule. It is the narrow piece,
and it is the one the documentation already promises.

**Deviation on I2, recorded deliberately.** This section originally asked for a
`runtime: not-implemented` marker in the catalogue. That is the wrong home:
`resources/settings/catalogue.json` is generated from the WordPress plugin's
section classes, the plugin cannot know what this runtime implements, and a
hand-edited marker would be erased by the next regeneration. The marker is
therefore a runtime list — `App\Support\Settings\UnimplementedSettings` — living
next to the behaviour it describes, with a test that scans the runtime for every
listed key.

## 7. Risks

1. **I1 builds nothing.** The board gets more honest and the estate still cannot
   use Auth0. That is the point of separating the correction from the build, but
   it must not be reported as progress on SSO.
2. **I2 changes the appearance of nineteen fields** on `/admin/settings`. It needs
   a visual pass, and the hint text must name the follow-up slice rather than say
   "coming soon".
3. **Per-tenant OIDC has a cross-tenant confusion hazard**: the tenant must be
   resolved *from the signed state parameter* before any assertion is validated,
   or estate A's assertion can be replayed against estate B's configuration. This
   is the single highest-risk line in I5 and deserves its own test class.
4. **Re-homing six keys (D5) hands them to a decision that does not exist yet.**
   Until it does, they stay marked rather than silently dropped.

## 8. What would change the decision

- **A customer asking to sign into the console with their own IdP** moves I5 up
  and makes D3/D4 urgent rather than eventual.
- **A requirement to accept Auth0 tokens on the estate API** is already I3 and
  needs no new decision.
- **A SAML-only customer** would break "OIDC first" — the answer is still a
  separate increment, not a reason to build both at once.
- **Evidence that the nineteen fields are being set on real estates** would raise
  I2 from hygiene to a correctness fix, because those administrators are
  currently configuring nothing.
