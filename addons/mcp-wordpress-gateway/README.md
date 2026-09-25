# mcp-wordpress Gateway

Auth-gated **Streamable HTTP** MCP server built on
[docdyhr/mcp-wordpress](https://github.com/docdyhr/mcp-wordpress) (MIT,
Aionda GmbH) internals, deployed on **Cloudways Velocity** and connected to
NV oOS assistants through the plugin's **Remote Sites → MCP Server**
connection type.

```
Velocity app (single Node process):
  [token auth: X-MCP-Token, timing-safe] → [native Streamable HTTP MCP server]
   /healthz (public, minimal)               /mcp (401 without token)
   MCP server = upstream McpServer + all 71 tools, policy-filtered at
                registration time (SDK StreamableHTTPServerTransport)
WordPress:  Remote Sites → MCP Server: url https://<app>/mcp,
            auth_type custom_header, X-MCP-Token
Targets:    WordPress REST API via least-privilege Application Passwords
            (multi-site config up to 50 sites)
```

## Why this exists

- mcp-wordpress is **stdio-only**; the plugin's Remote Sites MCP connection
  speaks **HTTP** (Streamable HTTP JSON-RPC). This app serves the same
  upstream tools over Streamable HTTP natively — a single Node process, no
  child processes, no stdio, no gateway binaries.
- The **native 30-tool port** (base plugin, PR #6777) already covers
  same-site management — this gateway is for managing **other/multiple
  sites**.
- Full plan & rationale:
  [`docs/operations/deployment/mcp-wordpress-velocity-setup.md`](../../docs/operations/deployment/mcp-wordpress-velocity-setup.md).

## Quick start (local)

```bash
npm install
cp .env.example .env    # set MCP_GATEWAY_TOKEN (≥32 chars) + WP credentials
npm start               # public :3000
```

Verify:

```bash
curl http://localhost:3000/healthz                          # {"status":"ok",...}
curl http://localhost:3000/mcp                              # 401
curl -H "X-MCP-Token: <token>" \
     -H "content-type: application/json" \
     -H "accept: application/json, text/event-stream" \
     -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' http://localhost:3000/mcp
```

## Protocol & client requirements

- **Streamable HTTP, stateless** — every request is independent
  (2026-07-28 style). The server negotiates the protocol revision per
  client; with the pinned SDK (1.30.1) the negotiated version is
  `2025-11-25`.
- Clients MUST send `Accept: application/json, text/event-stream`
  (Streamable HTTP spec requirement — the server answers `406 Not
  Acceptable` otherwise). The plugin's MCP App client satisfies this;
  verify with **Test Connection** before rollout.
- `initialize` returns no session id (stateless mode); clients must
  continue without `mcp-session-id`.

## Tool selection (allow/deny)

All 71 upstream tools are exposed by default; exposure is controlled at
**two layers**:

1. **Per-assistant gating in the plugin** (primary UX): after Test
   Connection → Discover Tools, enable only the tools each assistant needs.
2. **Gateway policy (defence in depth)**: env-driven allow/deny applied at
   tool **registration** time — filtered tools are absent from tools/list
   AND rejected on tools/call. Patterns support a trailing `*`; deny wins.
   Changing the policy requires a gateway restart.

| Variable | Default | Example |
|---|---|---|
| `MCP_TOOLS_ALLOW` | `*` | `wp_list_*,wp_get_post,seo_analyze_content` |
| `MCP_TOOLS_DENY` | *(empty)* | `wp_delete_user,wp_update_settings,wp_*_application_password` |

Example — content team (no user/settings/admin tools):

```dotenv
MCP_TOOLS_ALLOW=*
MCP_TOOLS_DENY=wp_create_user,wp_update_user,wp_delete_user,wp_get_site_settings,wp_update_site_settings,wp_*_application_password
```

## Environment variables

| Variable | Required | Purpose |
|---|---|---|
| `MCP_GATEWAY_TOKEN` | **yes** | ≥32 chars; sent by the plugin as `X-MCP-Token` |
| `MCP_GATEWAY_TOKEN_PREVIOUS` | no | Rotation-window overlap token |
| `PORT` | no | Public port (Velocity injects this) |
| `MCP_TOOLS_ALLOW` / `MCP_TOOLS_DENY` | no | Tool policy (§ above) |
| `NODE_ENV` | yes (prod) | `production` |
| `LOG_LEVEL` | no | `info` (default), `debug`, `none` |
| `WORDPRESS_SITE_URL` / `WORDPRESS_USERNAME` / `WORDPRESS_APP_PASSWORD` | yes | Passed through to mcp-wordpress |
| `WORDPRESS_AUTH_METHOD` | yes | `app-password` |

## WordPress credentials (least privilege)

- Dedicated bridge user per target site (`mcp-bridge`), **Editor** by
  default. Use an **Administrator** Application Password only when the
  user/settings/application-password tools are genuinely needed — and then
  constrain them with `MCP_TOOLS_DENY` + per-assistant gating.
- One Application Password per consumer; revoke immediately on any leak;
  rotate on the gateway-token cadence.

## Tests

```bash
npm test            # node --test: config, tool policy, HTTP surface (22 tests)
npm run smoke       # full chain: real MCP server + policy + auth (no network needed)
npm run check:pins  # dependency pin gate (upstream is version-locked by design)
```

## Deploy flow (same as the media worker)

```
monorepo PR (addons/mcp-wordpress-gateway/**)
  → merge to alpha-working/main
  → sync-mcp-wordpress-gateway.yml (git subtree split)
  → force-push main on nvdigitalsolutions/nvoos-mcp-wordpress
  → Velocity auto-deploy (Node 22, entry src/index.js, npm ci)
```

Never commit to the standalone mirror directly — the next sync overwrites
it. Dependency bumps go through the monorepo `package.json` /
`package-lock.json`; the deep imports from `mcp-wordpress/dist/` are safe
because the version is pinned and CI-gated (`check:pins`).

## Credits

- [docdyhr/mcp-wordpress](https://github.com/docdyhr/mcp-wordpress) — MIT, © 2025 Aionda GmbH
- [Model Context Protocol TypeScript SDK](https://github.com/modelcontextprotocol/typescript-sdk) — MIT
- [Express](https://expressjs.com/) — MIT
