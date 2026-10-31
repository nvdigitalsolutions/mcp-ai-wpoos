# Changelog — NV oOS MCP Gateway

## Unreleased

- Fix the `VERSION` constant in `src/index.js` — the 0.2.0 bump updated
  `package.json` but missed the constant, so `/health` reported 0.1.1 and
  the deploy-verification runbook could not tell builds apart. The constant
  now carries a comment requiring it to track `package.json`; the
  `.claude-plugin/plugin.json` manifest got the same bump; and the health
  test now asserts `/health` reports the `package.json` version instead of
  pinning a hardcoded string.
- Add `PUBLIC_SITE_SLUGS` — a privacy filter for the public `/health`
  endpoint: comma-separated slugs visible without auth (`*` or unset = all
  configured sites, the legacy behaviour). Private client sites stay
  invisible to unauthenticated callers and their degradation no longer
  flips the public status; `/health/full` always reports every site.
  Unknown slugs are logged as boot warnings, never fatal — a typo hides
  sites (the safe direction) and must not take the fleet surface down.

Follow-ups (later phases): Auth0 tenant provisioning (RFC 8414 + DCR +
PKCE, scopes, consent); scripted PKCE end-to-end + ChatGPT "Add custom MCP
server" test; Hub-style create → test → publish lifecycle console (the
Complete Console Gateway page shipped the read-only catalog in 0.2.0).

Registry status: published 2026-11-01 as
`io.github.nvdigitalsolutions/nvoos-mcp-gateway` v0.2.0 (`active`,
`isLatest`) — a remote-only entry with the required `Authorization` header
declared `isSecret`.

## 0.2.0 — 2026-10-28

- **Policy & governance layer (Proposal 094)** — the gateway grows from an
  auth+proxy into a fleet governance point, all env/Git-configured,
  fail-closed, and stdlib-only (no new dependencies):
  - **Per-tool quotas** — `TOOL_QUOTAS=<pattern>:<rate>/<window>:<burst>`
    token buckets scoped `(public key × site × tool)`, RFC 9558
    `RateLimit-*` headers on every MCP response, 429 + JSON-RPC `-32099`
    on breach, and a `peek()` dry-run path that never consumes tokens.
  - **Policy rules engine** — declarative `allow | deny |
    require_approval` rules over tool globs, site, key, time window (IANA
    tz, day-of-week, midnight-crossing ranges), and geo country
    (`CF-IPCountry` + static `GEO_MAP`); first-match-wins; `tools/list`
    catalog filtering (denied tools never enter model context);
    403 + `-32001` / 428 + `-32002` with a redacted preview.
    `SHADOW_POLICY=1` evaluates + logs without enforcing.
  - **W3C trace propagation** — the gateway starts or inherits a
    `traceparent`, stamps it on every upstream request and log line, and
    passes it through so gateway logs join per-site `trace_id` /
    `correlation_id` audit trails on one ID.
  - **Fleet audit sink** — optional `AUDIT_SINK_URL` + `AUDIT_SINK_SECRET`
    HMAC-`sha256=` webhook (the platform OutboundWebhook pattern) emitting
    `deny` / `quota_exceeded` / `require_approval` / `call_ok`; tool
    arguments are never included; retry ×3 then drop, sink outage never
    blocks calls.
  - **Policy inspector** — operator-token `GET /admin/policy` +
    `POST /admin/policy-sim` replaying a candidate policy against the
    200-entry recorded-call ring buffer, plus `dryRun: true` on
    `tools/call` returning the decision envelope without executing.
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
