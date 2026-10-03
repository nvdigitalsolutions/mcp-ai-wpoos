# ChatGPT Plugin — NV oOS Site Bridge

A **ChatGPT / Codex plugin** that connects OpenAI's agent surfaces (ChatGPT,
Codex CLI, ChatGPT desktop, and the Agents API) to a WordPress site running
**NV oOS (base + Pro)** through the site's own MCP bridge:

```
ChatGPT / Codex ──plugin package──▶ https://SITE/wp-json/mcp-ai/v1/mcp
                                     (NV oOS MCP endpoint, protocol 2026-07-28)
```

The plugin ships **its own MCP configuration** (`mcp.json` / `.mcp.json`),
three skills, and packaging scripts. Every tool call lands on the site, so the
site's existing security layers apply: assistant-scoped credentials, per-tool
allowlists, rate limits, cost tracking, and the restriction registry.

> This addon is the distribution package. The site-side companion changes
> (OAuth protected-resource metadata, per-tool `securitySchemes`) are proposed
> in the monorepo docs (links valid only in the `mcp-ai-wpoos` checkout):
> `docs/project/proposals/053-chatgpt-plugin-addon-proposal.md` and
> `053-chatgpt-plugin-addon-research.md`.

## Repo sync & distribution

This folder is synced from the monorepo to the standalone mirror
**`nvdigitalsolutions/nvoos-chatgpt-plugin`** by
`.github/workflows/sync-chatgpt-plugin.yml` (git subtree split, force-push to
`main`) — the same pattern as the other addons. Rules that follow from this:

- **Never commit to the mirror directly** — the next sync overwrites it.
  Version bumps (`plugin.json` + `.codex-plugin/plugin.json` in lockstep),
  changelog entries, and CI changes go through the monorepo.
- **The mirror must be self-contained.** Everything it needs ships inside
  this folder: `LICENSE`, `CHANGELOG.md`, `.github/workflows/ci.yml`,
  dependency-free `bin/` scripts (Node built-ins only — no `npm install`).
- **`mcp.json` / `.mcp.json` are tracked with the `YOUR-SITE.DOMAIN`
  placeholder.** The mirror always ships valid configs; `bin/stamp-site.mjs`
  stamps your site URL in place for local installs, and `bin/validate.mjs`
  fails CI if a stamped URL is ever committed.
- **Manifest URLs point at the mirror** (`homepage`, `websiteURL`) so the
  standalone repo's listing metadata is correct.

The mirror is also the distribution source for **Git-backed marketplaces**:

```bash
# Codex CLI / ChatGPT desktop: install the plugin straight from the mirror
codex plugin marketplace add nvdigitalsolutions/nvoos-chatgpt-plugin
```

Equivalent marketplace entry for a catalog JSON:

```json
{
  "name": "nvoos-site-bridge",
  "source": {
    "source": "git-subdir",
    "url": "https://github.com/nvdigitalsolutions/nvoos-chatgpt-plugin.git",
    "path": "./",
    "ref": "main"
  },
  "policy": { "installation": "AVAILABLE", "authentication": "ON_INSTALL" },
  "category": "Productivity"
}
```

## Files

| Path | Purpose |
|---|---|
| `plugin.json` | Portable Agent Plugins manifest (`agent-plugins.org` schema) with `extensions.com.openai` interface metadata |
| `.codex-plugin/plugin.json` | Compatibility overlay for legacy clients (`skills` + `mcpServers` pointers) |
| `mcp.json` | Portable MCP config (`type: streamable-http`) → site MCP endpoint |
| `.mcp.json` | Legacy plugin-format MCP config (`type: http`) → site MCP endpoint |
| `skills/*/SKILL.md` | `site-operations`, `content-studio`, `commerce-desk` skills |
| `marketplace.json` | Local marketplace entry for ChatGPT desktop / Codex testing |
| `bin/stamp-site.mjs` | Stamps the real site URL into the MCP configs (uncommitted local change) |
| `bin/package-plugin.mjs` | Builds the ZIP for Agents API `environment.plugins` uploads (POSIX + Windows) |
| `bin/validate.mjs` | Dependency-free CI gate: JSON, manifest lockstep, skills, marketplace |
| `.github/workflows/ci.yml` | Mirror-repo CI (runs after each sync) |
| `CHANGELOG.md`, `LICENSE` | Release history + GPL-3.0-or-later license for the mirror |
| `assets/` | Icon / logo / screenshots for the plugin directory (fill before submission) |

## Connect (three tiers)

### Tier 1 — Local / personal use (works today, no site changes)

The site's **assistant credentials** (`cred_xxx.SECRET`, minted per assistant
in **AI Assistants → API Credentials**) work with Codex and the Agents API,
which accept raw bearer tokens through environment variables:

```bash
# 1. Stamp your site URL into the MCP configs.
node bin/stamp-site.mjs https://your-site.com

# 2. Export the assistant credential as the bearer token.
export NVOOS_MCP_TOKEN='cred_xxxxxxxxxxxxxxxx.SECRET'
```

- **Codex CLI / ChatGPT desktop:** register the connection in developer mode
  and reference `bearer_token_env_var: NVOOS_MCP_TOKEN`, or install the local
  marketplace: `codex plugin marketplace add ./addons/chatgpt-plugin`.
- **Agents API (self-hosted sandbox):** copy the folder into the workspace and
  list it under `environment.capability_directories`; set `NVOOS_MCP_TOKEN` in
  the environment. For OpenAI-hosted sessions, upload the ZIP built by
  `node bin/package-plugin.mjs` via `environment.plugins`.
- Never put the token in plugin files or archives — env var only.

### Tier 2 — Team / workspace distribution

Workspace admins publish the locally installed plugin to their ChatGPT
workspace (Plugins → Personal → Publish). Each user links their own assistant
credential or OAuth account; the site's per-assistant tool gating is the
authorization boundary.

### Tier 3 — Public ChatGPT plugin (requires site-side OAuth work)

Published plugins that expose user data or writes must authenticate with
**OAuth 2.1 authorization-code + PKCE** per the MCP authorization spec —
ChatGPT does not accept machine-to-machine grants or custom API keys. The
proposal defines the site changes: an RFC 9728
`/.well-known/oauth-protected-resource` document, `WWW-Authenticate`
challenges on the MCP endpoint, per-tool `securitySchemes`, and a profile
tool. Auth0 (already integrated with NV oOS) is OpenAI's recommended
authorization server.

## Security model

- **Least privilege:** each connection uses one assistant-scoped credential;
  enable only the tools that assistant needs.
- **Secrets:** never committed. `cred_` tokens are shown once and stored
  hashed on the site.
- **Site-side gates still apply:** rate limiting, cost tracker, destructive
  ops gate, security audit logger — ChatGPT traffic is not exempt.
- **WAF/CDN:** allow `POST /wp-json/mcp-ai/v1/mcp` with
  `Accept: application/json, text/event-stream` (see the remote-client setup
  guide's troubleshooting section).

## Submission checklist (Tier 3)

- [ ] Replace placeholder `privacyPolicyURL` / `termsOfServiceURL` in `plugin.json`.
- [ ] Add `assets/icon.png`, `assets/logo.png`, and screenshots.
- [ ] Site exposes the OAuth protected-resource metadata and challenges (proposal Phase 2).
- [ ] Fill test prompts/responses and localization info in the submission portal.

## Credits / references

- OpenAI Agents API plugins guide — https://developers.openai.com/api/docs/guides/agents-api/tools/plugins
- Package your plugin (portable + legacy manifest formats) — https://developers.openai.com/plugins/build/plugins
- Plugin MCP authentication (OAuth 2.1) — https://developers.openai.com/plugins/build/auth
- MCP authorization specification — https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization
