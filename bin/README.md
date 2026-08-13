# Utility Scripts - NV oOS

This directory contains utility scripts for development, testing, and maintenance of the NV oOS plugin.

## Migration Scripts

### `migrate-settings-to-connections.php`

Migrates API credentials from plugin settings to Remote Site Connections.

**Usage:**
```bash
# Dry run (shows what would be migrated)
php bin/migrate-settings-to-connections.php --dry-run

# Run migration
php bin/migrate-settings-to-connections.php

# With verbose output
php bin/migrate-settings-to-connections.php --verbose
```

**Supported Services:**
- iSAMS (School Management)
- Flowhub (POS/Retail)
- PayHere (Payment Gateway)
- QuickBooks (Accounting)

**Documentation:** See [docs/REMOTE_CONNECTION_MIGRATION.md](../docs/REMOTE_CONNECTION_MIGRATION.md) for detailed migration guide.

---

## MCP Bridge (stdio ↔ HTTP)

### `mcp-bridge.js` — HTTP relay for local MCP clients

Dependency-free Node relay that bridges a newline-delimited JSON-RPC stdio
channel (what Zed's context server client speaks) to the NV oOS MCP endpoint.

**Usage (Zed `context_servers`):**
```json
{
  "command": "node",
  "args": ["bin/mcp-bridge.js"],
  "env": {
    "MCP_AI_BASE_URL": "https://your-site/wp-json/mcp-ai/v1/mcp",
    "MCP_AI_TOKEN": "cred_xxxxx.SECRET"
  }
}
```

| Env var | Default | Purpose |
|---|---|---|
| `MCP_AI_BASE_URL` | — (required) | Full URL of the MCP endpoint |
| `MCP_AI_TOKEN` | — | Bearer credential (`cred_…` or `op_…`) |
| `MCP_AI_HOST_HEADER` | unset | Override the HTTP `Host` header for tunnelled/proxied endpoints |
| `MCP_AI_HTTP_TIMEOUT` | `120000` | Request timeout in ms |

Notifications (JSON-RPC messages without an `id`) are forwarded but never
answered on stdout, per the MCP spec.

### `mcp-bridge-ssh.js` — for SSH-only sites

Same bridge, but it owns the SSH port-forward so no manual tunnel is needed:
it starts `ssh -N -L`, waits until the forward accepts connections, then runs
`mcp-bridge.js` against `127.0.0.1`. The tunnel dies with the bridge — its
stdin is held open so that even a hard kill (Zed terminating the server)
closes the pipe and makes `ssh` exit rather than orphaning the forward.

**Prerequisite:** SSH key auth. `ssh <user>@<host> -p <port>` must work
non-interactively (add your public key to the server's `~/.ssh/authorized_keys`).
Password prompts are not supported — a spawned process has no TTY.

**Usage (Zed `context_servers`):**
```json
{
  "command": "node",
  "args": ["bin/mcp-bridge-ssh.js"],
  "env": {
    "MCP_AI_SSH_USER": "your-ssh-user",
    "MCP_AI_SSH_HOST": "203.0.113.10",
    "MCP_AI_SSH_PORT": "2222",
    "MCP_AI_TOKEN": "op_xxxx.SECRET"
  }
}
```

| Env var | Default | Purpose |
|---|---|---|
| `MCP_AI_SSH_USER` | — (required) | SSH login user |
| `MCP_AI_SSH_HOST` | — (required) | SSH host |
| `MCP_AI_SSH_PORT` | `22` | SSH port |
| `MCP_AI_SSH_REMOTE_HOST` | `localhost` | Web-server host, from the server's perspective |
| `MCP_AI_SSH_REMOTE_PORT` | `80` | Web-server port (Cloudways stacks may use `8080` for Apache; probe with `curl` over SSH) |
| `MCP_AI_LOCAL_PORT` | auto free port | Pin the local forward port (normally not needed) |
| `MCP_AI_SSH_EXTRA_ARGS` | — | Extra ssh args, e.g. `"-i C:/keys/id_ed25519 -o ProxyJump=bastion"` |
| `MCP_AI_SSH_BATCH_MODE` | `1` | Set `0` only with an SSH_ASKPASS helper configured |
| `MCP_AI_SSH_READY_MS` | `15000` | Tunnel startup budget |
| `MCP_AI_BASE_PATH` | `/wp-json/mcp-ai/v1/mcp` | MCP route path |
| `MCP_AI_HOST_HEADER` / `MCP_AI_HTTP_TIMEOUT` | — | Forwarded to the relay |
| `MCP_AI_ENV_FILE` | `~/.nvoos-bridge.env` | Env file (KEY=value lines) — keep the token out of `settings.json` by putting it here instead |

**Verify the web port before first use** — over SSH, run
`curl -s -o /dev/null -w "%{http_code}\n" http://localhost:80/wp-json/mcp-ai/v1/status`
(and `:8080` / `:443`). If you get `301`, the app has a force-HTTPS rule:
set `MCP_AI_HOST_HEADER` to the canonical site host, or tunnel the HTTPS port
with `MCP_AI_SSH_REMOTE_PORT=443` + a hosts entry so the certificate matches.

**Tests:** `node bin/test-mcp-bridge-ssh.js` — exercises tunnel startup,
roundtrip, notification suppression, timeout errors, and orphan-free teardown
using an in-process fake ssh + fake MCP endpoint (no infrastructure needed).

### `hermes-mcp-server.js` — drive a Hermes Agent from Zed

MCP server that translates tool calls into the Hermes Agent **WebUI REST API**
(login + session cookie + synchronous chat). Zed spawns it as a local stdio
context server; it talks to the WebUI over public HTTPS — no SSH, no tunnel.

| Tool | What it does |
|---|---|
| `hermes_list_sessions` | List agent sessions (id, title, model, counts, workspace) |
| `hermes_chat` | Send a message and wait for the agent's answer (optionally a specific `session_id`) |
| `hermes_session_detail` | Full detail for one session |

| Env var | Default | Purpose |
|---|---|---|
| `HERMES_WEBUI_URL` | — (required) | WebUI base URL, e.g. `https://box.example.com:9610` |
| `HERMES_WEBUI_PASSWORD` | — (required) | WebUI login password |
| `HERMES_SESSION_ID` | — | Default session for `hermes_chat` (else newest) |
| `HERMES_CHAT_TIMEOUT` | `300000` | Chat request timeout (agent runs take minutes) |
| `HERMES_WEBUI_INSECURE` | unset | `1` to skip TLS verification (self-signed certs) |

Session cookies expire (1h TTL on the WebUI) — the server re-logins
automatically on 401/403/302 and retries once.

**Usage (Zed `context_servers`):** keep the password out of settings.json
via `~/.nvoos-bridge.env` (`HERMES_WEBUI_URL=…`, `HERMES_WEBUI_PASSWORD=…`):
```json
{
  "command": "node",
  "args": ["bin/hermes-mcp-server.js"]
}
```

**Tests:** `node bin/test-hermes-mcp-server.js` — handshake, tool dispatch,
session fallback, cookie-expiry re-login, and stdin-EOF drain against a fake
WebUI (no real infrastructure needed).

---

## Other Utility Scripts

For information about screenshot capture tools, see [README-SCREENSHOT-TOOLS.md](README-SCREENSHOT-TOOLS.md).

For other development utilities, run individual scripts with `--help` flag where available.

## Places Enrichment Tools

Scripts for importing, cleaning, and enriching Places CPT data from HTTrack mirrors and APIs.

### `enrich_places.php` — Batch Geocoding

Fills missing coordinates using Nominatim (free) or Google Geocoding API.

```bash
# Configure (optional)
echo '{"batch_size":10,"limit":200,"sleep":1,"provider":"nominatim","resume":true}' > wp-content/uploads/enrich_places_config.json

# Run
wp --user=1 eval-file bin/enrich_places.php
```

### `enrich_google.php` — Google Places Enrichment

Auto-fills ratings, Place IDs, phones, and websites via Google Places API.

```bash
wp --user=1 eval-file bin/enrich_google.php
```

### `enrich_social_timed.php` — Social Link Enrichment

Searches TripAdvisor, Booking.com, and Facebook with built-in rate limiting. Requires Brave Search API key in plugin settings.

```bash
# Configure
echo '{"batch_size":8,"search_delay":2,"max_searches":24,"targets":["tripadvisor","booking","facebook"]}' > wp-content/uploads/enrich_social_config.json

# Run (resumable)
wp --user=1 eval-file bin/enrich_social_timed.php
```

### `cleanup_places.php` — Reclassify & Clean

Fixes miscategorized places, deletes non-place content, creates new type taxonomies.

```bash
wp --user=1 eval-file bin/cleanup_places.php
```

### `link_place_children.php` — Parent-Child Linking

Links child places (attractions, hotels, tales) to their parent cities by URL matching.

```bash
wp --user=1 eval-file bin/link_place_children.php
```

### `enrich_contacts.php` — Manual Contact Data

Batch-applies known phone, email, website, and social links for key locations.

```bash
wp --user=1 eval-file bin/enrich_contacts.php
```

### `enhance_final.php` — Type Fixes & Metadata

Final pass for type corrections and known metadata (ratings, websites, Google Place IDs).

```bash
wp --user=1 eval-file bin/enhance_final.php
```
