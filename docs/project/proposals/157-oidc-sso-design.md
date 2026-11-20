# Proposal 157 — OIDC SSO: the bounded slice, and the parts that need a decision

**Status:** design recorded 2026-11-20 for slice **I5** of
[Proposal 156](156-identity-decision.md). **Nothing is built.** This proposal
exists so the next loop implements a decided design rather than re-deriving it,
and so the parts that are *not* decided are visible rather than assumed.

**Related:** [Proposal 156](156-identity-decision.md) D3 (OIDC first, SAML later),
D4 (deny-by-default provisioning), D5 (bridges out of scope);
[Proposal 149](149-laravel-admin-parity-implementation-plan.md) §102 (bearer
verification, the `auth0_subject` link), §103 (magic link — the session pattern
SSO reuses).

## 1. What is already built, and what that leaves

Three pieces of I5 exist already, each shipped for a different reason:

| Piece | Where | What it gives I5 |
|---|---|---|
| RS256 verification — JWKS, `iss`, expiry, algorithm refusal | `Auth0TokenVerifier` (§102) | the ID-token check, minus an audience rule |
| `users.auth0_subject` + `nvoos:auth0-link` | §102 | the subject → user mapping, deny by default |
| Session sign-in that returns the console's user payload | `MagicLinkController` (§103) | the callback's last step |

So I5 is not "build SSO from nothing". It is: **add the browser flow, and reuse
the verification and the mapping.**

## 2. The bounded increment — and the one it excludes

**In scope (I5a):** a user whose Auth0 `sub` is *already linked* signs in through
the estate's IdP and lands in the console with a session.

**Out of scope (I5b):** just-in-time provisioning. A subject with no link is
**denied**, exactly as it is for bearer tokens. No user is created, and nothing is
matched on an email claim.

That split is what makes I5a buildable now. Provisioning is the part that needs a
product decision (D4: deny by default, opt-in JIT gated on `email_verified` **and**
a per-estate domain allowlist, never linking by mutable email). Building the flow
behind a deny-by-default rule requires no new decision, changes no membership
model, and is useful on its own — an estate that links its staff gets SSO.

## 3. Where the client registration lives

The catalogue cannot carry it. `resources/settings/catalogue.json` is generated
from the WordPress plugin's section classes, and the plugin has no OIDC client —
so `auth0_oidc_client_id` cannot be a setting without forking the generator.

It does not need to be. An OIDC client registration is **deployment
infrastructure**, like the routing export token (§74) or the mesh key: one
registration per deployment, rotated by the person who owns the Auth0 tenant, not
adjustable per estate from a form. So it is configuration:

```php
// config/oos.php
'auth0' => array(
    'oidc' => array(
        'client_id' => (string) env('NVOOS_AUTH0_CLIENT_ID', ''),
        'client_secret' => (string) env('NVOOS_AUTH0_CLIENT_SECRET', ''),
        'redirect_uri' => (string) env('NVOOS_AUTH0_REDIRECT_URI', ''),
    ),
),
```

Empty `client_id` or `redirect_uri` means the flow is not configured, and
`/auth0/start` answers 404 — the same invisibility rule as the magic-link routes.

The **domain and audience** stay where §102 put them: estate settings, because an
estate's audience is per-tenant data. The client registration is the deployment's,
because it is one Auth0 application serving every estate on that deployment.

## 4. The flow, precisely

```
GET  /api/v1/auth0/start?estate=<id>     → 302 to the IdP
GET  /api/v1/auth0/callback?code=&state= → 302 to the console, session started
```

`/start`:
1. 404 unless the flow is configured and the estate has an `auth0_domain`.
2. Resolve discovery: `https://{domain}/.well-known/openid-configuration`,
   cached an hour. Take `authorization_endpoint`, `token_endpoint`, `issuer`,
   `jwks_uri`.
3. Generate `state` (43 chars), `code_verifier` (43 chars), `nonce` (43 chars).
   `code_challenge = BASE64URL(SHA256(verifier))`, method `S256`.
4. Store `{state, verifier, nonce, estate, return_to}` in the **session** under one
   key, replacing any previous entry (one flow at a time per session).
5. Redirect to `authorization_endpoint?response_type=code&client_id=…&redirect_uri=…&scope=openid profile email&state=…&nonce=…&code_challenge=…&code_challenge_method=S256`.

`/callback`:
1. 404 unless configured.
2. Read and **delete** the session entry. No entry, or `state` mismatch → the
   uniform failure. Deleting first is what makes the state single-use.
3. POST `grant_type=authorization_code&code=&redirect_uri=&client_id=&client_secret=&code_verifier=`
   to `token_endpoint`. Any failure → uniform failure. **Records the error**, because
   a mis-registered client is our problem, not the caller's.
4. Verify the `id_token` with the §102 verifier against the discovery `issuer` and
   **`aud` = client_id** (not the API audience — an ID token is minted for the
   application, an access token for the API). Assert `nonce` equals the stored one.
5. `sub` → `User::where('auth0_subject', …)`. **None → uniform failure. No
   provisioning, no email matching.**
6. `Auth::login($user, true)`, regenerate the session.
7. 302 to the stored `return_to` if it is same-origin, otherwise the console root.

### 4.1 The highest-risk line: which estate's configuration

The callback has no `X-Tenant-Id`, and it must not trust one if it did. So the
**estate is bound into the session state at `/start`**, where the caller names it,
and read back from the same session entry at `/callback`.

What makes that safe is the pairing: the authorization request is built from that
estate's discovery document and verified against that estate's issuer and JWKS, so
a token minted for estate A cannot satisfy a callback whose state says estate B —
the issuer check fails. This is the §102 rule (the selector picks configuration,
never authority) moved into the session, and it deserves its own test class
because getting it wrong is cross-tenant token confusion.

### 4.2 What is *not* in the state

No open redirect: `return_to` is validated against the app origin and dropped
silently if it is not same-origin. No client secret in a URL. No `code_verifier`
outside the session — PKCE is pointless if the verifier travels with the code.

## 5. What the user sees

Success is a 302 into the console with a session, indistinguishable from the
password path. Every failure is the same destination — the sign-in screen with one
sentence — because "unknown subject", "expired state", "bad token" and "client
misconfigured" must not be distinguishable to a caller (and only the last is worth
an operator's time, which is why it is logged rather than shown).

## 6. Slices

| Slice | Scope | State |
|---|---|---|
| **I5a-1** | `Auth0OidcClient`: discovery (cached), `authorizeUrl()`, `exchangeCode()`. Config keys. No routes. | not started |
| **I5a-2** | `Auth0SsoController`: `/start` + `/callback`, session state, PKCE, nonce, deny-by-default mapping, uniform failures. **This is the slice that carries the risk.** | not started |
| **I5a-3** | Console: a "Sign in with SSO" button, `/features` gains `sso`, the `/auth0/start` link. | not started |
| **I5b** | JIT provisioning — **needs a product decision** (§8). | blocked on decision |
| **I5c** | SAML — a separate increment, only if a customer requires it (D3). | not requested |

## 7. Test plan for I5a-2

The pattern is proven twice in this repo: mint real tokens with a local keypair,
fake only the network (`Http::fake` for discovery, token endpoint and JWKS).

1. `/start` redirects with `state`, `nonce`, `code_challenge`, `S256`, the
   registered `client_id` and `redirect_uri` — and `code_challenge` equals
   BASE64URL(SHA256(verifier)) for the verifier it stored.
2. `/callback` with a valid code mints a session for a **linked** subject and
   redirects same-origin.
3. **Unlinked subject → uniform failure, and no user is created.**
4. State mismatch → uniform failure; the session entry is consumed either way
   (a replayed state cannot succeed even with a fresh code).
5. `nonce` mismatch → uniform failure.
6. ID token signed by another key → uniform failure.
7. ID token for the wrong `aud` (the API audience rather than `client_id`) →
   uniform failure. This is the mistake most likely to be made in implementation.
8. Token endpoint 400 → uniform failure, error logged.
9. Estate A's callback with estate B's token → uniform failure (the §4.1 class).
10. `return_to` to another origin is ignored.
11. Flow not configured → 404 on both routes.
12. A linked subject who is a member of no estate: authentication succeeds and
    `ResolveApiTenant` refuses afterwards, which is where that rule lives.

## 8. What still needs a decision before I5b

1. **JIT on or off by default.** D4 says off. Confirm that is the product intent,
   because it means a new staff member cannot self-serve.
2. **The allowlist's home.** `email_verified` plus a domain list is per estate
   data, which means a new setting — and therefore a generator change, or a
   companion table, or an env var per estate. This is the concrete reason I5b is
   not a code-only slice.
3. **What a provisioned subject gets.** Membership in the estate that issued the
   login, with which role? `viewer` is the only safe default, and it makes the
   first login a dead end unless someone promotes them — which may be the point.

## 9. What would change this design

- **A customer who wants SAML** does not change I5a; SAML replaces the client, not
  the flow contract (§4 is protocol-agnostic above step 3).
- **A deployment that runs several Auth0 applications** (one per estate) breaks
  §3's "one registration per deployment" and would move `client_id` back into
  estate settings — a real possibility, and the reason §3 is written as a
  deployment assumption rather than a fact.
- **A decision to allow email matching** would undo D4 and should not be made
  quietly: it is an account-takeover vector, and §4.1's isolation does not help
  against it.
