---
description: Connect and verify the NV oOS MCP Gateway from Claude Code
---

# Connect to the NV oOS MCP Gateway

## Verify the connection

```bash
claude mcp list          # nvoos should appear with its tool count
```

If the server shows an auth error, re-check the key in `/config`
(Gateway API key) — it must be a key issued by the gateway operator
and sent as `Authorization: Bearer <key>`.

## Smoke-test a call

Ask Claude to list the MCP tools and call a read-only one, e.g.:

> Call the deep_research tool (prefixed with the site slug, e.g.
> demo.deep_research) with topic "Ada Lovelace" and depth "basic".

## Timeout tuning for long tools

Long-running tools (deep_research at standard/comprehensive depth) can
exceed the default client-side tool timeout. Raise it for this session:

```bash
export MCP_TIMEOUT=180000
```

or prefer `run_mode: "background"` in the tool arguments — results are
cached for one hour and retrieved on the next call.

## Key management

Keys are issued by the operator of the gateway (`https://mcp.nvoos.pro/`)
and map to a set of sites. Tool scoping is enforced server-side by each
site's Fleet Operator allowlist. Rotate keys via `/config` when a key is
compromised — the gateway supports overlap rotation without downtime.
