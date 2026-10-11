# MCP Gateway — Directory Submission Pack

Ready-to-paste listing fields for submitting the NV oOS MCP Gateway to
MCP directories. Verify the live endpoint before submitting:
`https://mcp.nvoos.pro/health`.

## One-line description

> Fleet-scoped MCP server for NV oOS WordPress sites — one API key, every
> bound site; tools namespaced as `<site-slug>.<tool>` over streamable HTTP.

## Longer description (listing body)

> **NV oOS Gateway** is a public, fleet-scoped MCP server for the NV oOS
> (Open Operator System) WordPress platform. It speaks streamable HTTP
> (MCP 2026-07-28 with protocol negotiation back to 2024-11-05) and
> aggregates the native MCP bridges of every bound WordPress site behind one
> endpoint: authenticate once with a public API key and the whole fleet's
> tools appear namespaced as `<site-slug>.<tool>`.
>
> - Per-key rate limits and key rotation (two-step, no downtime)
> - Server-side scoping: each site's Fleet Operator allowlist decides what a
>   key can see and call; read-only demo keys available
> - Graceful degradation: a failing site shows up in `_meta.gateway.errors`
>   without breaking the fleet
> - Stateless — no sessions; `/health` (public) and `/health/full` (keyed)
>   endpoints for monitoring

## Listing fields

| Field | Value |
|---|---|
| Name | NV oOS Gateway |
| Website / landing | `https://mcp.nvoos.pro/` |
| Source | `https://github.com/nvdigitalsolutions/nvoos-mcp-gateway` (GPL-3.0-or-later) |
| Category | Developer Tools / Web & CMS |
| Transport | Streamable HTTP (`POST https://mcp.nvoos.pro/mcp`) |
| Auth | Bearer API key in the `Authorization` header (required) |
| Demo key | `<DEMO_KEY>` — read-only, bound to the `demo` site. **Insert the real key at submission time; never commit it to this repo.** |
| Health | `https://mcp.nvoos.pro/health` (public, no auth) |
| Logo | TODO — NV oOS branding asset (square PNG/SVG); host next to the landing page |

## Install config — Claude Desktop / Claude Code

```json
{
  "mcpServers": {
    "nvoos": {
      "type": "http",
      "url": "https://mcp.nvoos.pro/mcp",
      "headers": { "Authorization": "Bearer <DEMO_KEY>" }
    }
  }
}
```

Claude Code CLI:

```
claude mcp add --transport http nvoos https://mcp.nvoos.pro/mcp --header "Authorization: Bearer <DEMO_KEY>"
```

## Install config — Zed / VS Code / Cursor (stdio relay)

```
npx -y @nvdigitalsolutions/nvoos-mcp-bridge
# env: MCP_AI_BASE_URL=https://mcp.nvoos.pro/mcp
# env: MCP_AI_TOKEN=<DEMO_KEY>
```

## Per-directory notes

- **mcpservers.org** — PR-based; fields above cover the requirements. The
  demo key must show at least one tool that succeeds end-to-end (see open
  item below) because reviewers probe the listing.
- **mcp.directory / pulsemcp** — same fields; follow each site's current
  submission form.
- **Official MCP Registry (registry.modelcontextprotocol.io)** — **blocked
  until OAuth 2.1 ships** (RFC 8414/9728 discovery + dynamic client
  registration). Remote servers with static API-key auth are not accepted;
  the gateway's static-key auth is the v1 state and OAuth is the documented
  upgrade path (`addons/mcp-gateway/README.md`).
- **ChatGPT native MCP connector** — same blocker: requires OAuth. Until
  then the ChatGPT route is the `addons/chatgpt-plugin` package (per-site
  bridge, experimental).

## Open items before submitting

1. **Demo tool that succeeds** — the `demo` key allowlists only
   `deep_research`, which fails on `nvoos.pro` until a web-search provider
   key is configured there. Either configure the search provider or
   allowlist a self-contained read-only tool (e.g. `get_environment_status`)
   on the `demo` operator (Settings → External Operators → Allowed tools).
2. **Logo** — add a square branding asset and update the Logo field above.
3. **Uptime monitor** — point an external monitor at `/health` before the
   listing goes up; include the status-page URL in the listing if available.
   In-fleet option: the media worker's status module monitors the gateway as
   a synthetic-only external target
   (`STATUS_EXTERNAL_TARGETS=gateway=https://mcp.nvoos.pro/health` +
   `STATUS_GATEWAY_SYNTHETIC=1`), with the worker's public `/status` page
   (`STATUS_PUBLIC_PAGE=1`) as the listing's status URL.
