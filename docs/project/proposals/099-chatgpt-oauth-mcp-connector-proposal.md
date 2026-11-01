# Proposal 099 — ChatGPT OAuth 2.1 Connector for the MCP Gateway

**Date:** 2026-11-01
**Status:** PROPOSED — awaiting owner decisions (D1–D5)
**Estimated Effort:** 3–4 dev-days (Phases 0–5) + ~1 day maintainer Auth0 provisioning
**Priority:** MEDIUM — closes the last "blocked" line in the gateway directory-submission pack
**Related:** [055-nvoos-mcp-gateway-proposal.md](./055-nvoos-mcp-gateway-proposal.md),
[061-mcp-registry-publishing-proposal.md](./061-mcp-registry-publishing-proposal.md),
[094-mcp-gateway-policy-governance-proposal.md](./094-mcp-gateway-policy-governance-proposal.md),
`addons/mcp-gateway/` (README "OAuth 2.1 resource server (opt-in)" + CHANGELOG follow-up
"Auth0 tenant provisioning (RFC 8414 + DCR + PKCE, scopes, consent); scripted PKCE end-to-end +
ChatGPT 'Add custom MCP server' test"), `docs/operations/deployment/mcp-gateway-directory-submission.md`

---

## Executive Summary

The fleet gateway is live (`https://mcp.nvoos.pro`), registry-published, and
verifiable end-to-end — **except for ChatGPT's native MCP connector**, which
requires OAuth 2.1 (authorization-code + PKCE against an authorization
server) and refuses static bearer keys. The gateway already implements the
**protected-resource half** of that flow (RFC 9728 metadata, `WWW-Authenticate`
challenges, zero-dependency JWT/JWKS validation, `site:<slug>` scope
binding — shipped in PR #6945, 58/58 tests, currently inert: the live
`/.well-known/oauth-protected-resource` returns 404). This proposal closes
the gap by provisioning the **authorization-server half** (Auth0 — the
platform convention), wiring three env vars, and running the scripted PKCE
end-to-end plus the ChatGPT "Add custom MCP server" walkthrough — turning
the gateway into a dual-mode surface (static keys *and* OAuth) with zero
changes to the upstream proxy model.

---

## Problem Statement

**Current state** (verified live 2026-11-01):

1. The gateway authenticates with **static API keys only**
   (`GATEWAY_PUBLIC_KEYS`). ChatGPT's native MCP connector mandates the
   OAuth 2.1 authorization-code + PKCE flow; static headers are not an
   option, so the connector cannot add `https://mcp.nvoos.pro/mcp`.
2. The **resource-server code already exists but is dormant**:
   `GATEWAY_OAUTH_ISSUER` unset → `GET /.well-known/oauth-protected-resource`
   returns 404 → no client can start the flow.
3. There is **no authorization server** for the fleet: no RFC 8414 issuer
   metadata, no dynamic client registration (RFC 7591), no consent screen,
   no scopes minted for gateway access.
4. The directory-submission pack still lists "ChatGPT native MCP connector —
   blocked: requires OAuth" as the residual gap; the gateway CHANGELOG
   carries the same item as an Unreleased follow-up.

**Goal state.** A ChatGPT user selects "Add custom MCP server" → pastes
`https://mcp.nvoos.pro/mcp` → the connector discovers the AS (RFC 8414) →
browser consent → PKCE token exchange → the gateway validates the JWT
(RFC 9728), maps `site:<slug>` scopes to bound sites, and proxies exactly as
it does for static keys. Static keys keep working unchanged alongside.

---

## Goals & Non-Goals

### Goals

1. **Auth0 tenant (platform convention)** — a free-tier tenant exposing
   RFC 8414 discovery + JWKS, with DCR enabled, a consent screen, and the
   gateway registered as a resource (`aud` = `https://mcp.nvoos.pro`).
2. **Scope-based site binding** — `site:<slug>` scopes (already implemented
   in the resource server) plus a read-only `site:read` scope advertised for
   future fine-grained enforcement; the ChatGPT demo flow ships with
   `site:demo`.
3. **Dual-mode auth** — OAuth alongside static keys; `GATEWAY_OAUTH_ISSUER`
   set on Velocity; boot **fails closed** when issuer is set but JWKS is
   unreachable at first validation (existing behaviour) and warns when the
   three OAuth env vars are partially configured.
4. **Scripted PKCE end-to-end test** — a hermetic `node:test`/bin harness
   replaying the RFC 8414 → authorize → PKCE → token → `tools/list`
   sequence against a local fake AS + the real gateway code path (the
   existing `src/oauth/*.test.js` suites are the unit layer; this adds the
   flow-level layer).
5. **Live ChatGPT walkthrough** — a documented, repeatable "Add custom MCP
   server" run against production, recording the exact consent UX and any
   connector quirks into the gateway skill.

### Non-Goals

- Replacing static keys or the per-site Fleet Operator model (the gateway
  still exchanges for each site's own `op_` token upstream — OAuth tokens
  are never forwarded, no confused-deputy passthrough).
- Building or self-hosting an IAM product (Auth0 is the platform convention;
  the gateway stays a stateless Node service).
- Per-user identity, usage billing, or paid auth tiers.
- ChatGPT **plugin** changes (`addons/chatgpt-plugin` remains the per-site
  experimental bridge, separate from this work).

---

## Research Findings (industry standards)

- **ChatGPT native MCP connector requirements** (verified 2026-11-01 against
  the directory research from this session): remote MCP servers must run
  OAuth 2.1 (authorization-code + PKCE) with RFC 8414 discovery and dynamic
  client registration; static API-key remotes are not accepted. PulseMCP and
  mcp.directory confirm the same policy for the official connector.
- **RFC 9728 (protected resource metadata)** — the gateway already serves
  the document shape at the root and the `/mcp` path-insertion variant when
  configured, and challenges with `resource_metadata` + `scope`.
- **RFC 8414 / RFC 7591 / RFC 7636** — the AS side must expose
  `/.well-known/oauth-authorization-server`, accept dynamic registrations,
  and enforce PKCE (S256). Auth0's free tier covers all three out of the box.
- **Codebase precedent** — `addons/mcp-gateway/src/oauth/` (jwt.js,
  resource-server.js + tests) already implements: JWKS caching (fail-closed,
  one refetch on unknown kid), `iss`/`aud`/`exp`/`nbf`/scope validation,
  `site:<slug>` scope→binding mapping, `insufficient_scope` 403, and the
  "OAuth tokens never forwarded upstream" invariant.
- **Live verification ladder** (this session's gateway-ops skill) — the
  `well-known` 404 is the one-line health check that OAuth is inert; after
  wiring, it must return 200 + the RFC 9728 document.

---

## Design Decisions

1. **D1 — Auth0 as the authorization server.** Platform convention, free
   tier sufficient, RFC 8414 + DCR + consent built in. (Owner: approve
   tenant + choose the tenant domain, e.g. `nv-oos.us.auth0.com`.)
2. **D2 — Scopes.** `site:<slug>` grants (already the implemented model)
   + `site:read` advertised for future read-only grants. The ChatGPT demo
   flow requests `site:demo openid profile`-minimum scopes; the demo
   operator's allowlist (`get_environment_status`, `deep_research`) stays
   the deep enforcement layer.
3. **D3 — Dual-mode, no migration.** Static keys and OAuth coexist; nothing
   about key issuance, rotation, or `GATEWAY_PUBLIC_KEYS` changes.
4. **D4 — Consent copy.** One screen explaining fleet scoping
   ("NV oOS Gateway wants to access the demo site's tools on your behalf —
   site:demo") — authoring is an owner-approved copy task in Phase 1.
5. **D5 — Env wiring.** `GATEWAY_OAUTH_ISSUER` (tenant), optional
   `GATEWAY_OAUTH_JWKS_URI`, `GATEWAY_OAUTH_RESOURCE=https://mcp.nvoos.pro`
   on the Velocity app; redeploy (the gateway-ops deploy ladder applies —
   verify via the well-known 200 + `WWW-Authenticate` challenge on a 401).
6. **D6 — Registry metadata.** `addons/mcp-gateway/server.json` gains the
   OAuth discovery URL once live (the registry models auth via declared
   headers today; the OAuth resource metadata link is the upgrade), with a
   re-publish via `mcp-publisher` (description-cap + device-flow gotchas in
   the gateway-ops skill).
7. **D7 — Testability.** The PKCE e2e harness uses a local fake AS
   (in-process, like the existing fake-upstream suites) — no network, no
   secrets; the live walkthrough is a manual, documented step only.

---

## Architecture

```
ChatGPT connector                        Auth0 (AS)                      NV oOS Gateway (mcp.nvoos.pro)
──────────────                          ──────────                      ──────────────────────────────
1. GET /.well-known/oauth-authorization-server (RFC 8414) ──────────────► discovery + jwks_uri + DCR endpoint
2. Dynamic client registration (RFC 7591, PKCE) ───────────────────────► client_id
3. Browser: authorization_code + PKCE S256 ────────────────────────────► consent (scopes: site:demo)
4. Token endpoint: code + verifier ────────────────────────────────────► JWT (aud = mcp.nvoos.pro, scope: site:demo)
5. POST /mcp  Authorization: Bearer <JWT> ──────────────────────────────────────────────────────────────►
   6. RFC 9728 validation: signature/JWKS cache, iss, aud, exp/nbf, scopes → key = "oauth:<sub>", sites = [demo]
   7. tools/list → namespaced demo.* via the site's own Fleet Operator token (never the OAuth token)
```

- The gateway's `site:<slug>` → bound-site mapping is scope-based, exactly
  like the static-key map; tokens granting no configured site get
  403 `insufficient_scope`.
- 401 responses carry `WWW-Authenticate: Bearer resource_metadata="…",
  scope="…"` so the connector can start/repair the flow.

---

## Security & Threat Model

- **Fail-closed JWKS**: unknown `kid` refetches once, then rejects; a
  failing JWKS transport rejects (already tested).
- **No confused deputy**: OAuth tokens are validated and then *exchanged*
  for each site's Fleet Operator token upstream — OAuth bearer material
  never leaves the gateway.
- **Scope = least privilege**: the ChatGPT demo flow mints only `site:demo`;
  client sites (`sascha`, `ideabits`) are unreachable without their own
  scopes, mirroring today's per-key isolation.
- **DCR abuse**: the AS's dynamic-registration surface is limited by Auth0
  tenant policy (rate limits, allowed callback URLs); documented in the ops
  runbook.
- **Metadata drift**: `GATEWAY_OAUTH_RESOURCE` defaults to
  `https://mcp.nvoos.pro`; a mismatched `aud` fails validation — the e2e
  harness covers the negative case.
- **Consent & revocation**: consent is AS-side; revoked/invalid tokens
  surface as 401 with the RFC 9728 challenge (no session state — the
  gateway stays stateless).

---

## Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Auth0 provisioning drags (tenant, DCR, consent UX) | Medium | Phase 1 is a checklist + owner approval; the resource-server code is already tested and inert, so no gateway code blocks on it |
| ChatGPT connector quirks surface only in the live walkthrough | Medium | Phase 4 is explicitly manual + documented into the gateway-ops skill; the connector's spec is pinned before the walkthrough |
| Dual-mode confusion (which auth does a caller use?) | Low | README + landing page updated; static keys documented as the operator path, OAuth as the ChatGPT path |
| Registry metadata stale after OAuth ships | Low | D6 re-publish; the 061 plan's verification URLs are the check |
| Partial OAuth env config (issuer set, JWKS broken) | Low | Existing fail-closed JWKS validation; Phase 2 verification ladder includes the well-known 200 + challenge check |

---

## Phased Implementation Plan

### Phase 0 — Verification & fixtures (0.5 d)

- Re-read `src/oauth/*` + tests; pin the ChatGPT connector spec and Auth0
  free-tier feature set (DCR + PKCE availability).
- Scaffold the fake-AS fixture for the e2e harness (RFC 8414 metadata +
  JWKS + token endpoint, in-process).

### Phase 1 — Auth0 provisioning (maintainer, ~1 d, D1/D4)

- Create the tenant; register the resource (`aud`), enable DCR, author the
  consent copy; produce the issuer + JWKS URIs and the tenant checklist.

### Phase 2 — Gateway wiring & live verification (0.5 d)

- Set the three env vars on Velocity; redeploy; verify: well-known 200,
  401 `WWW-Authenticate` challenge, static keys still green, public
  `/health` unchanged (gateway-ops deploy ladder).

### Phase 3 — Scripted PKCE end-to-end (1–1.5 d)

- `addons/mcp-gateway/bin/oauth-e2e.js` (or a `node:test` suite): discovery
  → DCR → authorize → PKCE S256 → token → `initialize`/`tools/list` with the
  gateway pointed at the fake AS; negative cases (bad signature, wrong aud,
  missing scope → 403 `insufficient_scope`, expired token).

### Phase 4 — Live ChatGPT walkthrough (0.5–1 d)

- Manual run of "Add custom MCP server" with the production endpoint;
  record the consent UX, any connector quirks, and a namespaced
  `demo.get_environment_status` success; update the gateway-ops skill.

### Phase 5 — Docs, registry, landing (0.5 d)

- README OAuth section → "configured" state; landing page gains the
  ChatGPT one-liner; `server.json` + `mcp-publisher` re-publish (D6);
  directory-submission pack's ChatGPT note flipped from blocked to live.

---

## Open Questions (resolved in implementation)

1. Auth0 tenant domain + DCR limits for the free tier (Phase 1 checklist).
2. Exact consent copy (D4 — owner approval).
3. Whether the ChatGPT flow requires `offline_access`/refresh tokens
   (resolved by the Phase 4 walkthrough; the gateway ignores refresh
   semantics — validation only).
4. Registry schema support for advertising the OAuth metadata URL (D6 —
   fall back to the existing `Authorization`-header declaration if the
   schema has no OAuth field).

## Decision Required

1. **Approve** the Auth0 tenant + consent copy (D1, D4).
2. **Approve** `site:<slug>` as the public scope vocabulary (D2).
3. **Approve** dual-mode (static keys + OAuth) with no migration (D3).
4. **Approve** the Phase 4 live walkthrough against production with the
   demo scope (D5/D7).

---

## Deliverables Summary

- Auth0 tenant checklist + consent copy (Phase 1)
- Gateway OAuth env wiring on Velocity + live verification log (Phase 2)
- Hermetic PKCE e2e harness + negative-case suite (Phase 3)
- ChatGPT "Add custom MCP server" walkthrough doc + skill update (Phase 4)
- Registry re-publish + docs flip of the directory-submission "blocked"
  line to live (Phase 5)
