---
name: nvoos-gateway
description: Operate NV oOS WordPress sites through the NV oOS MCP Gateway — namespaced tools, key scoping, background research, and health checks. Use when working with tools prefixed <site-slug>.<tool> or when asked about the gateway.
---

# NV oOS MCP Gateway

The gateway is a public, fleet-scoped MCP endpoint aggregating the native
MCP bridges of bound NV oOS (Open Operator System) WordPress sites. One API
key authenticates against every site bound to that key.

## Tool namespacing

Tools are always namespaced `<site-slug>.<tool>` — e.g. `demo.deep_research`,
`console.create_post`. The site part of the name decides which WordPress
site executes the tool. Call tools with the prefixed name exactly as listed;
never strip the prefix.

## Scoping

- Every key maps to a set of sites; tools outside the key's bindings are
  not listed and are rejected with `-32602`.
- Each site enforces its own Fleet Operator allowlist server-side: a key
  can only see and call what the site's operator credential permits.
- Read-only keys exist (demo keys): write-capable tools fail with 403.

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

## Conventions

- Prefer read-only tools for checks; keep writes explicit and minimal.
- Verify credentials before reporting auth failures — a 401 means the
  key is wrong, rotated, or not bound to the requested site.
