# @nvdigitalsolutions/nvoos-mcp-bridge

Zero-dependency **MCP stdio ↔ HTTP relay** for [NV oOS (Open Operator System)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress sites.

Every NV oOS site exposes a JSON-RPC 2.0 MCP endpoint at
`https://<site>/wp-json/mcp-ai/v1/mcp`. Most editors and agents (Zed, Claude
Desktop, Cursor, Codex) spawn MCP servers over **stdio** — this package
bridges the two transports. Two binaries ship:

| Binary | Use when |
|--------|----------|
| `nvoos-mcp` | The site is reachable over HTTPS (normal case) |
| `nvoos-mcp-ssh` | The site is SSH-only (locked-down VPS / Cloudways droplet) — owns the `ssh -N -L` port-forward for you |

```bash
npx -y @nvdigitalsolutions/nvoos-mcp-bridge@latest
```

---

## Quick start (Zed)

`zed: open settings` → add to `context_servers`:

```jsonc
"nv-oos": {
  "source": "custom",
  "command": {
    "path": "npx",
    "args": ["-y", "@nvdigitalsolutions/nvoos-mcp-bridge@latest"],
    "env": {
      "MCP_AI_BASE_URL": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "MCP_AI_TOKEN":    "cred_xxxxx.SECRET"
    }
  }
}
```

Then reload the Agent Panel (`agent: restart language servers` → or reopen
the panel). Use the **Fleet Operator** admin screen (`Settings → External
Operators`) to mint a scoped operator token and get a ready-pasted block.

### SSH-only sites

```jsonc
"nv-oos-ssh": {
  "source": "custom",
  "command": {
    "path": "npx",
    "args": ["-y", "@nvdigitalsolutions/nvoos-mcp-bridge@latest", "ssh"],
    "env": {
      "MCP_AI_SSH_USER": "your-ssh-user",
      "MCP_AI_SSH_HOST": "203.0.113.10",
      "MCP_AI_SSH_PORT": "22",
      "MCP_AI_TOKEN":    "op_xxxxx.SECRET"
    }
  }
}
```

> Pass `ssh` as the first argument to select the SSH binary via npx
> (npx exposes only the primary bin as the bare package name). SSH auth is
> key-only: `ssh <user>@<host>` must work non-interactively first.

### Other clients

Same env vars, different shells — Claude Desktop (`claude_desktop_config.json`
→ `mcpServers`), Cursor (`.cursor/mcp.json`), VS Code (`.vscode/mcp.json`):

```json
{
  "mcpServers": {
    "nv-oos": {
      "command": "npx",
      "args": ["-y", "@nvdigitalsolutions/nvoos-mcp-bridge@latest"],
      "env": {
        "MCP_AI_BASE_URL": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
        "MCP_AI_TOKEN": "cred_xxxxx.SECRET"
      }
    }
  }
}
```

On Windows hosts, some clients need the `cmd` wrapper:
`"command": "cmd", "args": ["/c", "npx", "-y", "@nvdigitalsolutions/nvoos-mcp-bridge@latest"]`.

---

## Configuration

All configuration is environment variables — **never** put tokens in `args`
(they would show in process listings).

### `nvoos-mcp` (HTTPS relay)

| Variable | Required | Default | Purpose |
|----------|----------|---------|---------|
| `MCP_AI_BASE_URL` | ✅ | — | Full URL of the MCP endpoint, e.g. `https://example.com/wp-json/mcp-ai/v1/mcp` |
| `MCP_AI_TOKEN` | ⚠️ | — | Bearer credential (`cred_xxxxx.SECRET` or operator `op_xxxxx.SECRET`); unauthenticated if unset |
| `MCP_AI_HOST_HEADER` | | — | Override the HTTP `Host` header (sites that canonical-redirect on Host behind proxies/tunnels) |
| `MCP_AI_HTTP_TIMEOUT` | | `120000` | Request timeout in ms |
| `MCP_AI_ENV_FILE` | | `~/.nvoos-bridge.env` | Env file for secrets (see below) |

### `nvoos-mcp-ssh` (SSH variant)

Adds `MCP_AI_SSH_USER`, `MCP_AI_SSH_HOST` (both required), `MCP_AI_SSH_PORT`
(default 22), `MCP_AI_SSH_REMOTE_HOST` (default `localhost`),
`MCP_AI_SSH_REMOTE_PORT` (default 80), `MCP_AI_LOCAL_PORT` (default: free
port), `MCP_AI_SSH_CMD` (default `ssh`), `MCP_AI_SSH_EXTRA_ARGS`,
`MCP_AI_SSH_BATCH_MODE`, `MCP_AI_SSH_READY_MS` (default 15000). Same token,
Host-header, timeout, and env-file variables as the HTTPS relay.

### Secret file

Keep tokens out of settings files:

```bash
# ~/.nvoos-bridge.env  (chmod 600)
MCP_AI_BASE_URL=https://your-site.com/wp-json/mcp-ai/v1/mcp
MCP_AI_TOKEN=cred_xxxxx.SECRET
```

Values already present in the process environment **win** over the file, so
per-project overrides still work.

---

## Behavior contract

- Reads one JSON-RPC 2.0 object per stdin line, POSTs it to the endpoint
  (`Authorization: Bearer …`), writes the response as one stdout line.
- **Notifications** (no `id`) get no response, ever.
- All diagnostics go to **stderr** — stdout carries MCP messages only.
- On stdin EOF, in-flight requests drain before exit.
- The SSH tunnel is torn down when the relay exits — including on hard kill
  (the bridge holds `ssh`'s stdin open for exactly this reason).

## Development

The package files under `bin/` are **byte-identical copies** of the repo's
`bin/mcp-bridge.js`, `bin/mcp-bridge-ssh.js`, and `bin/utils/env-file.js` —
the repo `bin/` is canonical. Edit there, then:

```bash
npm run sync               # refresh the copies
npm run sync -- --check    # drift gate (run in CI)
npm test                   # node:test suite (in-process fake endpoint/ssh)
npm run pack:dry-run       # inspect the tarball before publishing
```

Optional Docker E2E against a live NV oOS site (skips when env is unset):

```bash
MCP_AI_BASE_URL=http://localhost:8000/wp-json/mcp-ai/v1/mcp \
MCP_AI_TOKEN=cred_xxxxx.SECRET \
npm run test:e2e
```

## License

GPL-3.0-or-later — see [LICENSE](./LICENSE).
