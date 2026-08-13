# Hermes Operator Setup — Runbook

How to wire Nous Research's [Hermes Agent](https://hermes-agent.nousresearch.com)
as an external operator of NV oOS sites, using the Fleet Operator addon.

> Plan: [`docs/project/proposals/024-hermes-agent-fleet-operator-implementation-plan.md`](../../project/proposals/024-hermes-agent-fleet-operator-implementation-plan.md)

## Prerequisites

- NV oOS **v1.1.52+** on the target site (v1.1.51 and earlier reject
  `ping`/`notifications/initialized` with HTTP 400).
- Fleet Operator addon activated on the target site.
- Hermes installed (desktop app or gateway) on the machine that will drive
  tool calls. Config lives at `~/.hermes/config.yaml` + `~/.hermes/.env`.

## 1. Create the operator credential (per site)

WP admin: **Settings → External Operators → Create operator credential**.

- **Label:** `Hermes` (or per-site, e.g. `Hermes-store-b`).
- **Act as user:** the human whose capabilities Hermes may use.
- **Mode:** `Read + write` (approval gates still apply) or `Read only`.
- **Allowed tools:** start small. Example:

  ```
  get_site_summary
  get_recent_posts
  search_content
  create_post
  group:content_publishing
  woo_products
  ```

- Copy the token **immediately** — it is shown once. If lost, revoke and
  recreate.

Or via WP-CLI:

```bash
wp mcp-ai operator create Hermes --user=1 --tools=get_site_summary,get_recent_posts,create_post --mode=readwrite
```

## 2. Add the generated config to Hermes

The admin page prints both fragments on creation. For CLI: `wp mcp-ai operator config <id> --token=<op_xxxx.SECRET>`.

`~/.hermes/.env`:

```
NVOOS_CONSOLE_TOKEN=op_xxxxxxxx.SECRET
```

`~/.hermes/config.yaml`:

```yaml
mcp_servers:
  console:
    url: "https://example.com/wp-json/mcp-ai/v1/mcp"
    headers:
      Authorization: "Bearer ${env:NVOOS_CONSOLE_TOKEN}"
    tools:
      include:
        - get_site_summary
        - get_recent_posts
        - create_post
    trust: untrusted  # approve every write-capable tool call
```

Then `hermes /reload-mcp` (or restart the desktop app).

## 3. Verify

Ask Hermes: "list the tools on the console site." It must show **only** the
allowlisted tools. Then run one read (`get_site_summary`) and one write
(`create_post` as draft). Check **Settings → External Operators → Last used**
and the base plugin's activity log for `operator_id` attribution.

## 4. Verify rejection paths (do this once per site)

- Call a tool outside the allowlist by name → must fail with
  "outside this operator credential's allowlist".
- Use a token from site A against site B → must fail with audience mismatch.
- Revoke the credential → next call fails immediately (kill switch).

## 5. Operate

- You talk to Hermes (WhatsApp/Signal/Telegram/CLI/desktop); Hermes calls the
  sites. Keep `trust: untrusted` until you trust it on a given site.
- Approvals appear in Hermes itself and in the site's **Agent Command Center
  → Approvals** for destructive-ops-gated tools.
- Review `docs/operations/fleet/` and the skills pack
  (`hermes skills tap add nvdigitalsolutions/nvoos-hermes-skills`).

## 6. Zed as a second operator (SSH-only sites)

Hermes is an MCP *client*, not a server — to control the site from Zed's
Agent Panel, register Zed as a second operator of the same console. If the
site has no public web route (SSH only), use the repo's SSH bridge
(`bin/mcp-bridge-ssh.js`) — it owns the port-forward and speaks
newline-delimited stdio, which is exactly what Zed's context server client
expects.

1. **Authorize a key** — append your public key to the SSH user's
   `~/.ssh/authorized_keys` on the server and confirm
   `ssh <user>@<host> -p <port>` works non-interactively.
2. **Create a second operator credential** (section 1) — label it `Zed` so
   audit entries are attributed separately from Hermes.
3. **Add the server in Zed** — Settings → AI → MCP Servers → Add Local
   Server, or `settings.json`:

   ```json
   {
     "context_servers": {
       "hermes-console": {
         "command": "node",
         "args": ["bin/mcp-bridge-ssh.js"],
         "env": {
           "MCP_AI_SSH_USER": "your-ssh-user",
           "MCP_AI_SSH_HOST": "203.0.113.10",
           "MCP_AI_SSH_PORT": "2222",
           "MCP_AI_SSH_REMOTE_PORT": "80",
           "MCP_AI_TOKEN": "op_xxxx.SECRET"
         }
       }
     }
   }
   ```

   Keep the token out of version control with `~/.nvoos-bridge.env`
   (`MCP_AI_TOKEN=op_xxx.SECRET`) — process env wins over the file, so the
   Zed entry above can omit the token entirely.
4. **Verify** — green dot next to `hermes-console`, `tools/list` shows only
   the allowlist, and the site's activity log attributes runs to `Zed`.

See `bin/README.md` → "MCP Bridge" for the full env-var reference, the
Cloudways web-port probe (`80`/`8080`/`443`), and the `MCP_AI_HOST_HEADER`
fallback for force-HTTPS redirects. Tests: `node bin/test-mcp-bridge-ssh.js`.

## 7. Zed driving Hermes itself (WebUI MCP server)

Section 6 makes Zed a *second operator of the console*. If you instead want
Zed to **talk to the Hermes agent** (start runs, read sessions) through the
box's WebUI, use `bin/hermes-mcp-server.js` — an MCP server that speaks the
WebUI REST API (login + session cookie + synchronous chat) over public HTTPS.
No SSH tunnel is involved.

1. Point it at the WebUI and keep the password out of settings.json:

   `~/.nvoos-bridge.env`:
   ```
   HERMES_WEBUI_URL=https://hermes-box.example.com:9610
   HERMES_WEBUI_PASSWORD=…
   ```

   Zed `context_servers` entry:
   ```json
   {
     "context_servers": {
       "hermes": {
         "command": "node",
         "args": ["bin/hermes-mcp-server.js"]
       }
     }
   }
   ```

2. Tools exposed: `hermes_chat` (send a message, wait for the answer),
   `hermes_list_sessions`, `hermes_session_detail`. Session cookies expire
   after 1h — the server re-logins automatically.

3. Verify — green dot in Settings → AI → MCP Servers, then ask the Zed agent
   to run `hermes_list_sessions`.

Tests: `node bin/test-hermes-mcp-server.js`. Full reference:
`bin/README.md` → "hermes-mcp-server.js".

## Troubleshooting

| Symptom | Fix |
|---|---|
| `initialize` rejected / version error | Update NV oOS to v1.1.52+ (protocol negotiation fallback landed in v1.1.51). |
| HTTP 400 on `ping` / `notifications/initialized` | Same — method enum fix shipped in v1.1.52. |
| 401 `operator_audience_mismatch` | Token minted on another URL (www vs non-www, http vs https). Recreate the credential on the canonical URL, or use the `wp_mcp_ai_operator_audience_url` filter for proxies. |
| 429 rate limited | Raise the per-operator rate limit or slow Hermes' tool churn. |
| Tool missing from `tools/list` | It is not in the allowlist — extend it in WP admin and re-run `/reload-mcp`. |

## Security notes

- Hermes stores secrets as plaintext files: keep `chmod 600 ~/.hermes/.env`.
- Run the Hermes gateway under systemd/Docker (unprivileged), never `nohup &`.
- Revoking a credential is your global kill switch — it takes effect on the
  next request.
