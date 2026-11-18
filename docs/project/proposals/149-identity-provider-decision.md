# Proposal 149 — `identity-provider` decision brief (track P2)

**Parent:** [`149-laravel-admin-parity-implementation-plan.md`](./149-laravel-admin-parity-implementation-plan.md) §46
**State:** **blocked on an owner decision.** No code can start before it.
**Owner decision required.** **Unblocks:** order item 19 (`identity-provider`, contract `planned`).

## 1. Verified state

- **No OIDC/SAML implementation exists on either runtime.** A repo-wide search for
  `openid`, `oidc` and `saml` across `includes/`, `lib/` and the Laravel app returns
  only the A2A agent card and bundled documentation — no auth code.
- **No auth dependency is installed.** `docker/laravel/app-skeleton/composer.json`
  requires Laravel, Filament, Octane, Sanctum, MCP, Tinker and the three `nvoos/*`
  packages. There is no Socialite, no `league/oauth2-client`, no SAML library.
- **`auth0-setup` is a different surface.** `includes/admin/class-wp-mcp-ai-auth0-setup.php`
  configures **Auth0 tokens for the MCP API** (a bearer-token issuer for assistants),
  not single sign-on for the admin panel. The proposal already dispositions it
  separately (`auth0-setup` → P, Wave 2). Do not conflate the two.
- **Panel auth today is local**: email + password, Sanctum session cookie, CSRF via
  `X-CSRF-TOKEN`. Panels are `/ops` and `/admin`, gated by `AdminAccessPolicy`.

So this is **new product**, not parity. The contract row says so (`writer: none`,
`planned`) and must stay that way until a client exists.

## 2. The decision

| Option | Scope | Cost | What it buys |
|---|---|---|---|
| **A. OIDC only** (recommended) | Authorization Code + PKCE against one issuer (discovery URL), RP-initiated logout | One dependency (`league/oauth2-client` or a small discovery+JWT client), one controller pair, one settings panel | Covers Okta, Entra ID, Google Workspace, Keycloak, Auth0 — the whole mainstream |
| **B. OIDC + SAML** | A adds SAML 2.0 SP | A second, heavier dependency (`onelogin/php-saml` or similar) and a certificate-rotation story | Only if a named customer requires SAML |
| **C. Defer** | Record the row `absent` with this brief as the reason | None | Removes a permanent `planned` row from the burn-down |

Recommendation: **A**, and only when a real deployment asks for it. **C** is the
honest interim state if no one has asked — a `planned` row with no decision is the
placeholder problem §32 fixed for the `/ops` tail.

## 3. What option A requires, in order

1. **Settings**: issuer URL, client ID, client secret (secret scope), scopes, and a
   `enabled` toggle. Platform scope, `/ops/settings` only — an estate must not be able
   to point the panel at its own issuer.
2. **Mapping**: `sub` → `users.oidc_subject` (unique, nullable). Email is *not* an
   identity: a subject that changes email must keep its account.
3. **Provisioning rule** — pick one and record it:
   - **deny** an unknown subject (safest; an operator pre-creates the user), or
   - **create** a user with **no** estate membership (the panel then refuses them via
     `AdminAccessPolicy::accessTenantPanel`, which is already fail-closed).
   Recommendation: deny for `/ops`, create-without-membership for `/admin`.
4. **Break-glass**: local password login must remain possible for at least one
   operator account, or an IdP outage locks NV Digital out of its own panels.
5. **Audit**: login success/failure and subject→user mapping write `nvoos_admin_audit`
   rows (no tokens, no assertions).
6. **Logout**: RP-initiated logout, and a local session invalidation that does not
   depend on the IdP answering.

Then `canAccessPanel` stays exactly as it is — identity proves *who*, membership and
capability still decide *what*. That separation is the reason this port is safe.

## 4. Acceptance criteria for the decision

- [ ] One of A / B / C chosen and recorded on §46, with the reason.
- [ ] If A or B: the provisioning rule (deny vs create-without-membership) recorded.
- [ ] If C: the contract row changes `planned` → `absent`, with this brief as the note.
- [ ] If A or B: the slice is scoped (settings, mapping, provisioning, break-glass,
      audit, logout) and scheduled — not started blind.

## 5. What this track is *not*

- Not an MCP API auth change. Assistants keep using Sanctum/credentials; `auth0-setup`
  and `token-manager` are untouched.
- Not multi-tenant SSO per estate. Option A proves an operator; estate membership is
  a separate grant, and a tenant-supplied IdP would be a different (much larger)
  decision.
