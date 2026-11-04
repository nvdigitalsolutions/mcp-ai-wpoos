---
name: nvoos-gateway
description: Operate NV oOS WordPress sites through the NV oOS MCP Gateway from Claude Code — namespaced <site-slug>_<tool> tools, key scoping, OAuth 2.1, background research, and health checks. Use when working with gateway tools (demo_*) or when asked about the gateway connection.
---

# NV oOS MCP Gateway

The gateway is a public, fleet-scoped MCP endpoint aggregating the native
MCP bridges of bound NV oOS (Open Operator System) WordPress sites. One API
key — or one OAuth authorization — authenticates against every site bound
to that credential.

## Tool namespacing

Tools are always namespaced `<site-slug>_<tool>` — e.g. `demo_deep_research`,
`console_create_post`. The site part of the name decides which WordPress
site executes the tool. Call tools with the prefixed name exactly as listed;
never strip the prefix.

## Plugin-scoped tool names in permission rules

When this plugin provides the server, every tool call also carries a
plugin-scoped name for permission rules, hook matchers, and
`allowed-tools` lists:

```
mcp__plugin_nvoos-gateway_nvoos__<site-slug>_<tool>
# e.g. mcp__plugin_nvoos-gateway_nvoos__demo_get_environment_status
```

In hook `server` fields the server is named `plugin:nvoos-gateway:nvoos`.
A matcher written against the bare server key (`mcp__nvoos__.*`) never
fires for a plugin-bundled server.

## Scoping

- Every key maps to a set of sites; tools outside the key's bindings are
  not listed and are rejected with `-32602`.
- Each site enforces its own Fleet Operator allowlist server-side: a key
  can only see and call what the site's operator credential permits.
- Read-only keys exist (demo keys): write-capable tools fail with 403.

## OAuth 2.1 (alternative to API keys)

The production gateway is also an RFC 9728 protected resource (Auth0
tenant, live since 2026-11-01). To connect with OAuth instead of a key,
add the server manually and sign in:

```
claude mcp add --transport http nvoos https://mcp.nvoos.pro/mcp
# then run /mcp and select the server to complete the browser sign-in
```

Claude Code discovers the authorization server from the gateway's
protected-resource metadata. Dynamic client registration is enabled on the
Auth0 tenant, and the flow is the same one verified live for the ChatGPT
connector (end-to-end from Claude Code: expected, not yet recorded).

## Long-running tools

Tools such as `deep_research` (standard/comprehensive depth) can exceed
client-side tool timeouts in immediate mode. Prefer:

- `run_mode: "background"` — returns immediately; results are cached for
  one hour and served on the next call with the same topic, or
- a raised client timeout (`MCP_TIMEOUT=180000` in Claude Code).

## Health & errors

- Public health: `GET https://mcp.nvoos.pro/health` (no auth).
- A failing upstream site degrades the tool list with a
  `_meta.gateway.errors` entry instead of failing the whole list.
- `-32603` with "upstream timeout" means the site is slow or the gateway
  budget was exceeded — retry with background mode for long tools.

## Empty tools/list — diagnosis

An empty `tools: []` with **no** `_meta.gateway.errors` entry means the bound
upstream(s) answered — it is a scoping problem, not an outage. Work the
chain in order:

1. `GET /health` (public) and `GET /health/full` (Bearer key): check the
   key's `bindings`, each site's upstream `url`, and `lastOkAt`/`lastError`.
2. Confirm with `tools/call` on a tool the site definitely exposes: a
   `-32603` reading "Tool \"X\" is outside this operator credential's
   allowlist" (HTTP 403) is the signature of an empty operator allowlist.
3. Fix on the site, not the gateway: Settings → External Operators → edit
   the operator credential → populate the tool allowlist (slugs, fnmatch
   globs, `group:<toolkit>` entries). Scoping is per-request and stateless —
   no gateway restart or key rotation is needed; the namespaced tools
   appear on the next `tools/list`.

## Conventions

- Prefer read-only tools for checks; keep writes explicit and minimal.
- Verify credentials before reporting auth failures — a 401 means the
  key is wrong, rotated, or not bound to the requested site.
