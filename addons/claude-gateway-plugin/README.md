# NV oOS Gateway — Claude Code plugin

Connect Claude Code to the **NV oOS MCP Gateway**, the public, fleet-scoped
endpoint for [NV oOS](https://nvoos.pro) (Open Operator System) WordPress
sites. After a one-time configuration, Claude works with namespaced
`<site-slug>_<tool>` tools — on the public demo site these are the `demo_*`
tools: environment status, deep research with citations, content workflows,
and store tools over streamable HTTP.

This plugin bundles **no executable code**: it ships a skill and a
`/connect` command (context only) plus one remote MCP server definition.
The server itself runs on `mcp.nvoos.pro`.

## Install

From the plugin's own marketplace (this repository):

```
/plugin marketplace add nvdigitalsolutions/nvoos-claude-gateway-plugin
/plugin install nvoos-gateway@nvoos-claude-marketplace
```

On install, Claude Code prompts for a **Gateway API key**. The key is
stored securely as a sensitive `userConfig` value (never in settings.json
or the repository) and substituted into the MCP server's `Authorization`
header. Keys are issued by the gateway operator, are site-scoped, and each
site's Fleet Operator allowlist decides which tools a key may call.

**No key?** Use the gateway's OAuth 2.1 flow instead — see `/connect`.

## What this plugin does

| Component | What it runs / sends |
| --- | --- |
| `skills/gateway/SKILL.md` | Markdown instructions loaded into Claude's context. No code. |
| `commands/connect.md` | Markdown slash command (`/nvoos-gateway:connect`). No code. |
| `mcpServers.nvoos` | One remote MCP server at `https://mcp.nvoos.pro/mcp` over streamable HTTP. Claude Code sends the configured API key as `Authorization: Bearer <key>` to **that origin only**. Tool calls are forwarded by the gateway to the bound NV oOS site. |

There are no hooks, no `bin/` executables, no monitors, and no bundled MCP
server process — nothing runs on your machine.

## Authentication options

1. **Gateway API key** (default): prompted on install, stored in the
   platform credential store, sent only to `https://mcp.nvoos.pro`.
2. **OAuth 2.1**: `claude mcp add --transport http nvoos
   https://mcp.nvoos.pro/mcp`, then sign in from `/mcp`. The gateway serves
   RFC 9728 protected-resource metadata; the Auth0 tenant supports dynamic
   client registration. This flow is verified live for the ChatGPT
   connector and is expected to work identically from Claude Code.

## Data flow

- Tool calls (`tools/list`, `tools/call`) go from Claude Code to
  `https://mcp.nvoos.pro/mcp` over HTTPS. The gateway proxies each call to
  the bound NV oOS WordPress site using that site's own server-side
  operator token — your gateway key or OAuth token never travels upstream.
- The gateway enforces per-key rate limits, site bindings, and per-site
  tool allowlists. Private client sites are not exposed by the public demo
  configuration.
- No telemetry, analytics, or third-party services are contacted by this
  plugin. It is a single JSON manifest plus two Markdown files.

## Repository hygiene

- `version` in `.claude-plugin/plugin.json` is bumped with every release so
  installs receive updates.
- The monorepo ships a dependency-free validator at
  `bin/validate-claude-plugin.mjs` (in
  `nvdigitalsolutions/mcp-ai-wpoos`) — run
  `node bin/validate-claude-plugin.mjs . --strict` from the plugin folder
  to check the manifest, the marketplace catalog, component front matter,
  and README/license requirements without needing the Claude Code CLI.
  The validator is dev tooling and is deliberately **not** shipped in the
  plugin distribution.
- The mirror repository (`nvdigitalsolutions/nvoos-claude-gateway-plugin`)
  is synced one-way from `addons/claude-gateway-plugin/` in the
  `nvdigitalsolutions/mcp-ai-wpoos` monorepo — never commit to the mirror
  directly.

## Privacy & terms

- [Privacy policy](https://nvdigitalsolutions.github.io/nvoos-claude-gateway-plugin/privacy)
- [Terms of service](https://nvdigitalsolutions.github.io/nvoos-claude-gateway-plugin/terms)

## License

GPL-3.0-or-later — see [LICENSE](./LICENSE).
