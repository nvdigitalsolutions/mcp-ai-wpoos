# ChatGPT Plugin — NV oOS Gateway

A **ChatGPT / Codex plugin** that connects OpenAI's agent surfaces (ChatGPT,
Codex CLI, ChatGPT desktop, and the Agents API) to the **NV oOS MCP Gateway**
— the public, fleet-scoped MCP endpoint for the NV oOS (Open Operator
System) platform:

```
ChatGPT / Codex ──plugin package──▶ https://mcp.nvoos.pro/mcp
                                     (fleet gateway, protocol 2026-07-28)
```

The gateway aggregates the native MCP bridges of its bound WordPress sites
behind one endpoint. This plugin is the **public-directory entry**: after a
one-time OAuth consent, users explore the public demo site (`demo` →
nvoos.pro) through namespaced `demo_*` tools (`demo_get_environment_status`,
`demo_deep_research`, …). The gateway enforces per-key rate limits, policy
rules, and per-site scoping — this plugin exposes the public demo site only,
never private client sites.

> Companion packages: `addons/chatgpt-plugin/` (the per-site "NV oOS Site
> Bridge" bridge, distributed per installation) and `addons/mcp-gateway/`
> (the gateway server itself). See
> `docs/project/proposals/101-nvoos-gateway-chatgpt-plugin-implementation-plan.md`.

## Repo sync & distribution

This folder is synced from the monorepo to the standalone mirror
**`nvdigitalsolutions/nvoos-chatgpt-gateway-plugin`** by
`.github/workflows/sync-chatgpt-gateway-plugin.yml` (git subtree split,
force-push to `main`) — the same pattern as the other addons:

- **Never commit to the mirror directly** — the next sync overwrites it.
  Version bumps (`plugin.json` + `.codex-plugin/plugin.json` in lockstep),
  changelog entries, and CI changes go through the monorepo.
- **The mirror must be self-contained.** Everything it needs ships inside
  this folder: `LICENSE`, `CHANGELOG.md`, `.github/workflows/ci.yml`,
  dependency-free `bin/` scripts (Node built-ins only).
- **The MCP URL is canonical.** Unlike the site-bridge package, `mcp.json`
  and `.mcp.json` carry the real gateway URL — the plugin's origin is
  locked to `https://mcp.nvoos.pro/mcp` for its lifetime (OpenAI origin
  rules). `bin/validate.mjs` enforces this.
- **`docs/` is the publisher Pages site** (landing, privacy, terms) served
  from the mirror's GitHub Pages; it is excluded from the plugin ZIP.

Git-backed marketplace install:

```bash
codex plugin marketplace add nvdigitalsolutions/nvoos-chatgpt-gateway-plugin
```

## Authentication

The published plugin uses **OAuth 2.1** (authorization-code + PKCE) against
the gateway's Auth0 tenant — ChatGPT links the connection through its OAuth
UI. Static gateway API keys remain valid for Codex CLI, Agents API
sandboxes, and developer-mode local connections (Tier 1–2):
`MCP_AI_BASE_URL=https://mcp.nvoos.pro/mcp` + `MCP_AI_TOKEN=<key>`.
