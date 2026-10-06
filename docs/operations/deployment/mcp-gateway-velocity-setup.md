# MCP Gateway on Cloudways Velocity — Setup & Operations Guide

Deploy the **NV oOS MCP Gateway** to **Cloudways Velocity** (managed
Node.js hosting: Git-based deploys, NGINX + PM2, managed backups and
monitoring) as the public, fleet-scoped MCP endpoint at
`https://mcp.nvoos.pro`, and submit it to MCP directories.

> **Prerequisite:** the mirror repo `nvdigitalsolutions/nvoos-mcp-gateway`
> must exist and receive the one-way sync from
> `addons/mcp-gateway/` (root workflow `.github/workflows/sync-mcp-gateway.yml`,
> secret `MCP_GATEWAY_REPO_TOKEN`).
> Never expose an older build on a public URL — auth is the product.

---

## 1. Provision the Velocity Application

1. **Connect the repo:** import `nvdigitalsolutions/nvoos-mcp-gateway`,
   branch `main`.
2. **Build settings:**
   - Node version: **22**
   - Package manager: **npm**
   - Root directory: repo root
   - Entry file: `src/index.js` (or start command `npm start`)
3. **Environment variables** (Velocity dashboard):

   | Variable | Required | Value |
   |---|---|---|
   | `NODE_ENV` | yes | `production` |
   | `PORT` | no | injected by the platform |
   | `TRUST_PROXY` | yes | `1` (NGINX in front) |
   | `AUTH_MODE` | yes | `strict` |
   | `GATEWAY_PUBLIC_KEYS` | **yes** | `demo-key=site-a,site-b;internal-key=site-c` (see §3) |
   | `NVOOS_SITE_<SLUG>_URL` | per site | Full upstream URL incl. `/wp-json/mcp-ai/v1/mcp` |
   | `NVOOS_SITE_<SLUG>_TOKEN` | per site | Fleet Operator token (see §2) |
   | `ALLOWED_ORIGINS` | no | Comma-separated origins for browser clients |
   | `RATE_LIMIT_*` | no | Budget overrides (defaults are fine to start) |

4. Deploy and verify the **public** health endpoint:

   ```bash
   curl https://<velocity-app-url>/health
   # → { "status": "ok", "service": "design-mcp-gateway", "version": "0.1.0", "sites": { … } }
   ```

   And the public MCP discovery:

   ```bash
   curl https://<velocity-app-url>/mcp
   # → { "name": "NV oOS Gateway", "protocolVersion": "2026-07-28", … }
   ```

## 2. Upstream Site Credentials

For every bound site, create a dedicated **Fleet Operator** credential
(Settings → External Operators) scoped to the tools that should be publicly
reachable — e.g. a `read`-mode operator with an explicit allowlist. Paste the
token into `NVOOS_SITE_<SLUG>_TOKEN`. Tokens live **only** in the Velocity
env; they never appear in responses, logs, or the repo.

## 3. Public Keys & Rotation

Generate once per consumer (you, a demo key, a client key):

```bash
node -e "console.log(require('crypto').randomBytes(32).toString('base64url'))"
```

Map keys to site slugs in `GATEWAY_PUBLIC_KEYS`
(`key=slug-a,slug-b;other-key=slug-c`).

**Rotation procedure** (two-step, no downtime):

1. Set the **new** key in `GATEWAY_PUBLIC_KEYS` and keep the old value in
   `GATEWAY_PUBLIC_KEYS_PREVIOUS` (accepted with a one-time warning).
2. Update the consumer's client config.
3. Verify, then remove the old entry and restart.

## 4. Domain & TLS

1. Add `mcp.nvoos.pro` as the application domain in Velocity (managed TLS).
2. Point DNS at the Velocity hostname (A record or CNAME per Velocity's
   instructions).
3. Verify `https://mcp.nvoos.pro/health` and `/mcp` over the domain, then
   update the landing page/README references.

## 5. Monitoring & Alerts

- Uptime check on `GET /health` (no auth, minimal data).
- Alert on 5xx spikes and on `429` counts (rate limiting misconfiguration
  shows up here first).
- Watch logs for `[Auth]` warnings (previous-key use, short keys) and
  upstream error entries; the health registry marks degraded sites.
- Velocity provides managed backups; the gateway is stateless — restoring
  is just redeploying.

## 6. Directory Listing Checklist

Used when submitting to mcpservers.org, mcp.directory, pulsemcp, and the
official MCP Registry:

- [ ] Endpoint: `https://mcp.nvoos.pro/mcp` (streamable HTTP, GET + POST)
- [ ] Auth: bearer API key in the `Authorization` header (state clearly)
- [ ] Demo key: read-only operator allowlist; published in the listing
- [ ] Example config (Claude Desktop + Zed via `nvoos-mcp-bridge`)
- [ ] Landing page: `https://mcp.nvoos.pro/`
- [ ] GitHub repo: `nvdigitalsolutions/nvoos-mcp-gateway` (public)
- [ ] Logo + one-line description (NV oOS branding)
- [ ] Uptime monitor on `/health`

## 7. Deployment Flow & Auto-Deploy

```
monorepo PR (addons/mcp-gateway/**)
  → merge to alpha-working/main
  → sync-mcp-gateway.yml (snapshot commit, fast-forward push, no --force)
  → push main on nvoos-mcp-gateway
  → Velocity auto-deploy (webhook fires on the normal push)
```

The sync deliberately pushes **without `--force`**: the mirror main is a
linear fast-forward line, so the Velocity auto-deploy webhook fires on every
sync. The earlier subtree-split force-push rewrote history each run, which
left Velocity's clone on stale commits and silently disabled auto-deploys.

### If auto-deploy stops firing (stale build)

1. In Deployment Manager, check the **Commit** column of the latest
   deployment against the mirror's `main` tip — a SHA that does not exist
   in the mirror history means the clone is stale.
2. **Disconnect and reconnect the GitHub repo** (fresh clone), then deploy.
3. Verify the live build via the version marker: `/health` must report the
   version in `addons/mcp-gateway/package.json`, and
   `POST /mcp` → `initialize` must echo a negotiated `protocolVersion`.

---

See also the media-worker guide (`media-worker-velocity-setup.md`) for the
shared Velocity operational patterns (build settings, PM2 behaviour, backup
notes).
