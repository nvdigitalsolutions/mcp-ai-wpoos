# Changelog — NV oOS MCP Gateway

## Unreleased (on the 0.1.1 line — v1.1.99 window, PRs #6941–#6945)

- **OAuth 2.1 resource server (Phase 1)** — with `GATEWAY_OAUTH_ISSUER` set,
  the gateway becomes an RFC 9728 protected resource alongside static API
  keys (inert — 404 / unchanged 401s — when unconfigured): RFC 9728 metadata
  (`GET /.well-known/oauth-protected-resource` + the `/mcp` path-insertion
  variant), 401 `WWW-Authenticate` challenges with `resource_metadata` +
  scopes, 403 `insufficient_scope` for scope-less tokens,
  zero-dependency JWT validation (node:crypto RS256/384/512 + ES256/384,
  in-memory JWKS cache with TTL + rotation refetch, strict
  iss/aud/exp/nbf/scope, fail-closed), scope-based site binding
  (`site:<slug>`), and **no token passthrough** (OAuth tokens never travel
  upstream — confused-deputy discipline). Config:
  `GATEWAY_OAUTH_ISSUER` / `GATEWAY_OAUTH_JWKS_URI` /
  `GATEWAY_OAUTH_RESOURCE` (absolute https, boot fails closed).
- **Claude Code plugin + CLI example** — `.claude-plugin/plugin.json`
  (sensitive `userConfig` key feeding the MCP Authorization header via
  `${user_config.api_key}`), a `/connect` command, and the gateway skill
  (tool namespacing, scoping, background mode); the mirror sync doubles as
  the marketplace source.
- **Listing prep** — GPL-3.0-or-later `LICENSE` + `license` field (the
  mirror showed as unlicensed) and
  `docs/operations/deployment/mcp-gateway-directory-submission.md`
  (mcpservers.org / mcp.directory / pulsemcp fields + per-directory notes).
- **Icon** — `assets/mcp-gateway.svg` + `/assets` static serving
  (same-origin, 1-day cache) before the auth/404 layers; landing-page logo
  + favicon; Dockerfile copies the asset.

Follow-ups (later phases): Auth0 tenant provisioning (RFC 8414 + DCR +
PKCE, scopes, consent); scripted PKCE end-to-end + ChatGPT "Add custom MCP
server" test; `server.json` + `mcp-publisher` registry submission
(namespace decision); deployment guide + submission-pack doc updates.

## 0.1.1 — 2026-10-06

- `initialize` negotiates the protocol version with the client
  (`2026-07-28` → `2024-11-05`; highest mutually supported, defaulting to
  `2024-11-05`) instead of hardcoding `2026-07-28` — strict clients (Zed,
  Claude Desktop) no longer abort with "Unsupported protocol version".
- Mirror sync no longer force-pushes: each sync snapshots the subtree as
  one commit on a linear mirror `main`, so hosting auto-deploys (Cloudways
  Velocity) fire on every sync instead of staying on stale clones.

## 0.1.0 — 2026-10-05

- Initial public gateway: bearer API-key auth, per-key rate limits,
  `<site-slug>.<tool>` tool namespacing, health endpoints.
