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
   | `TOOL_QUOTAS` | no | Per-tool fleet quotas (see §8) |
   | `GATEWAY_POLICY` / `POLICY_FILE` | no | Declarative policy rules (see §8) |
   | `GATEWAY_ADMIN_TOKEN` | no | Operator token for `/admin/policy*` (see §8) |
   | `AUDIT_SINK_URL` / `AUDIT_SINK_SECRET` | no | HMAC-signed fleet audit webhook (see §8) |

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
- In-fleet option: the media worker's status module monitors the gateway as
  a synthetic-only external target — `STATUS_EXTERNAL_TARGETS=gateway=https://mcp.nvoos.pro/health`
  plus `STATUS_GATEWAY_SYNTHETIC=1` (probes `/health` through the SSRF
  guard; HMAC-signed webhook/email alerts on confirmed transitions; the
  worker's public `/status` page needs `STATUS_PUBLIC_PAGE=1`).
- Alert on 5xx spikes and on `429` counts (rate limiting misconfiguration
  shows up here first).
- Watch logs for `[Auth]` warnings (previous-key use, short keys) and
  upstream error entries; the health registry marks degraded sites.
- Velocity provides managed backups; the gateway is stateless — restoring
  is just redeploying.

## 6. Directory Listing Checklist

Used when submitting to mcpservers.org, mcp.directory, pulsemcp, and the
official MCP Registry:

- [x] Endpoint: `https://mcp.nvoos.pro/mcp` (streamable HTTP, GET + POST) — verified live 2026-10-07
- [x] Auth: bearer API key in the `Authorization` header (state clearly)
- [x] Demo key: read-only operator allowlist issued and bound to the `demo` site; publish the key value in each directory listing (never commit it to this repo)
- [x] Example config (Claude Desktop + Zed via `nvoos-mcp-bridge`)
- [x] Landing page: `https://mcp.nvoos.pro/`
- [x] GitHub repo: `nvdigitalsolutions/nvoos-mcp-gateway` (public) — ships `LICENSE` (GPL-3.0-or-later) and a `license` field in `package.json`
- [ ] Logo + one-line description (NV oOS branding)
- [ ] Uptime monitor on `/health`
- [ ] Demo tool that succeeds end-to-end: the `demo` key currently allowlists only `deep_research`, which needs a web-search provider key on the demo site — allowlist a self-contained read-only tool (e.g. `get_environment_status`) or configure a search provider before reviewers probe it

See the ready-to-paste listing fields in
[`mcp-gateway-directory-submission.md`](mcp-gateway-directory-submission.md).

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

### If the sync workflow never ran after a merge

1. Check the merge commit's **full message** — if the squash body absorbed a
   CI-skip marker from any commit merged into the PR branch, GitHub skips
   every workflow for that push (incident: the #6925 merge ran zero
   workflows and the mirror never received `0.1.1`).
2. Recover by merging a clean follow-up PR touching `addons/mcp-gateway/**`,
   or dispatch manually (workflow_dispatch, added in 0.1.1):
   `gh workflow run sync-mcp-gateway.yml --ref alpha-working`.

### Sync safety rails (0.1.1)

- The sync pushes **without `--force`** — the mirror main stays a linear
  fast-forward line, which is what keeps auto-deploys firing.
- The sync is **version-guarded**: a push whose incoming `package.json`
  version is older than the mirror tip's is skipped with a notice, so stale
  `main` promotions or out-of-order sync runs can never roll the public
  endpoint backwards.

## 8. Policy & Governance Layer (0.2.0)

The gateway doubles as the **fleet governance point**: per-tool quotas,
declarative policy rules, W3C trace propagation, and a signed audit
webhook — all stateless, env/Git-configured, fail-closed. Sites remain the
deep enforcement layer; gateway policy is a ceiling, never a floor
(delegate model).

| Variable | Required | Value |
|---|---|---|
| `TOOL_QUOTAS` | no | Compact per-tool fleet quotas: `<pattern>:<rate>/<window>:<burst>` semicolon-separated, e.g. `delete_*:5/1h:10;*.send_email:30/1h:30`. Rate is requests per window (`s`/`m`/`h`); burst is the token bucket capacity. Breach → 429 + RFC 9558 `RateLimit-*` headers + JSON-RPC `-32099`. |
| `GATEWAY_POLICY` | no | JSON policy (or point `POLICY_FILE` at a Git-versioned file): ordered rules with conditions `tool` (glob), `site`, `key`, `time` (`tz`, `days`, `start`/`end` midnight-crossing ranges), `geo` (`CF-IPCountry` + optional `GEO_MAP`) and effects `allow` / `deny` / `require_approval`. First match wins; denied tools are filtered from `tools/list`; `deny` → 403 + `-32001`, `require_approval` → 428 + `-32002` + redacted preview. |
| `SHADOW_POLICY` | no | `1` = evaluate + log the policy without enforcing (deploy dry-run). |
| `GEO_MAP` | no | Static `country:<site-or-key>` map when not behind Cloudflare. |
| `GATEWAY_ADMIN_TOKEN` | no | Operator token (`op_…`) for `GET /admin/policy` and `POST /admin/policy-sim`. Unset → `/admin/*` returns 401 (inert). |
| `AUDIT_SINK_URL` / `AUDIT_SINK_SECRET` | no | HMAC-`sha256=` signed webhook receiving `deny` / `quota_exceeded` / `require_approval` / `call_ok` events (no tool arguments). Retry ×3 then drop; sink outage never blocks calls. |
| `SITE_LIFECYCLE` | no | Per-site lifecycle state (`site=published|draft|retired`) surfaced by the catalog / Gateway page. |

**Policy change control:** Velocity has no hot-reload contract — **a
redeploy is the production policy change**, reviewable in Git and in the
Velocity deployment history. `SHADOW_POLICY=1` and the
`POST /admin/policy-sim` endpoint (replays a candidate against the
recorded-call ring buffer) are the pre-flight checks before a policy
redeploy.

**Client visibility:** every MCP response carries RFC 9558
`RateLimit-Limit` / `RateLimit-Remaining` / `RateLimit-Reset` headers and a
`traceparent`; `dryRun: true` on `tools/call` returns the full decision
envelope without executing — verified live on
`https://mcp.nvoos.pro` after the 0.2.0 deploy.

---

See also the media-worker guide (`media-worker-velocity-setup.md`) for the
shared Velocity operational patterns (build settings, PM2 behaviour, backup
notes).
