# Media Worker on Cloudways Velocity — Setup & Operations Guide

Deploy the Design Stack Media Worker to **Cloudways Velocity** (managed
Node.js hosting: Git-based deploys, NGINX + PM2, managed backups and
monitoring) and connect it to the mcp-ai-wpoos WordPress plugin.

> **Prerequisite:** The worker must run **v2.2.0+** (security hardening).
> Never expose an older build on a public URL — v2.1.x has no auth,
> no SSRF guard, and launches Puppeteer with `--no-sandbox`.
> See `docs/project/proposals/025-media-worker-cloud-deployment-security-implementation-plan.md`.

---

## 1. Provision the Velocity Application

1. **Connect the repo:** import `nvdigitalsolutions/mcp-ai-wpoos-media-worker`,
   branch `main` (the subtree mirror of `addons/media-worker/` — see the
   Repository Sync section in the worker README).
2. **Build settings:**
   - Node version: **22**
   - Package manager: **npm**
   - Root directory: repo root
   - Entry file: `src/index.js` (or start command `npm start`)
3. **Environment variables** (see `.env.example` in the repo):

   | Variable | Required | Value |
   |---|---|---|
   | `NODE_ENV` | yes | `production` |
   | `PORT` | no | injected by the platform |
   | `TRUST_PROXY` | yes | `1` (NGINX in front) |
   | `WORKER_API_TOKEN` | **yes** | ≥32 random characters (see §3) |
   | `AUTH_MODE` | yes | `strict` |
   | `REDIS_URL` | no | only if PM2 runs in cluster mode |
   | `PUPPETEER_EXECUTABLE_PATH` | no | only if Chromium exists on the server |
   | `PUPPETEER_SKIP_DOWNLOAD` | recommended | `true` — skips the ~170 MB Chromium download at install time; the bundled binary can't run on Velocity without system libs anyway |
   | provider keys | no | `OPENAI_API_KEY`, `GEMINI_API_KEY`, etc. |

   **Never set** `SSRF_ALLOW_PRIVATE` or `ALLOW_NO_SANDBOX` on Velocity.

4. Deploy and verify the **public** health endpoint:
   ```bash
   curl https://<velocity-app-url>/api/health
   # → { "status": "ok", "service": "design-media-worker", "version": "2.4.0", "uptime": … }
   ```
   The full capability matrix requires auth:
   ```bash
   curl -H "X-Site-Token: <WORKER_API_TOKEN>" https://<velocity-app-url>/api/health/full
   ```

## 2. Connect WordPress

1. In `wp-config.php` (or Settings → Media Worker):
   ```php
   define( 'WP_MEDIA_WORKER_URL', 'https://<velocity-app-url>' );
   define( 'WP_MEDIA_WORKER_TOKEN', '<the same WORKER_API_TOKEN>' );
   ```
   The plugin sends `X-Site-Token` on every sidecar request; the worker
   verifies it with a timing-safe comparison.
2. **Settings → Media Worker → Test Connection** should show "Connected".
   Capabilities such as `video_processing` / `browser_automation` will show
   as unavailable if the platform lacks ffmpeg/Chromium (§5).

## 3. Generating & Rotating the Token

Generate once, store in both places (worker env + WordPress constant):

```bash
node -e "console.log(require('crypto').randomBytes(32).toString('base64url'))"
```

**Rotation procedure** (two-step, no downtime):

1. Set the **new** token as an additional accepted value on the worker
   (`WORKER_API_TOKEN=<new>` with the old value kept as
   `WORKER_API_TOKEN_PREVIOUS` for the overlap window — both are
   compared timing-safely).
2. Update the WordPress constant/option.
3. Verify Test Connection, then remove the old value and restart the worker.

## 4. Monitoring & Alerts

- Uptime check on `GET /api/health` (no auth needed, minimal data).
- Alert on 5xx spikes and on `429` counts (rate limiting misconfiguration
  shows up here first — see `RATE_LIMIT_*` env vars).
- Watch worker logs for `[Auth]`, `[Browser]` and SSRF rejection messages.
- Velocity provides managed backups; the worker itself is stateless except
  the Redis-backed queue (flush queue on restore).

## 5. System Binaries (ffmpeg / Chromium) — Capability Gap

Velocity images are managed Node.js stacks; they may not include ffmpeg or
Chromium. The worker detects binaries at boot and degrades **gracefully**:

| Capability | Without binary | With binary |
|---|---|---|
| Video process/info (`/api/video/*`) | `503 capability_unavailable` → WP local fallback | full |
| Browser screenshot/PDF (`/api/browser/*`, `/api/pdf/generate`) | routes error → WP local fallback | full (sandboxed Chromium) |
| Chart rendering (`/api/data/render-chart`) | `503 capability_unavailable` → WP local fallback | full |
| PDF rasterization (`/api/pdf/render`) | `503 capability_unavailable` → WP local fallback | full |
| Image, document, OCR, email, data | unaffected | unaffected |

### Native module `canvas` — install-time safety

Chart rendering and PDF rasterization use the native `canvas` package
(`canvas@3.x` via `chartjs-node-canvas`, `canvas@2.x` directly). It is
listed under `optionalDependencies`, so **a build failure can never fail the
deploy** — npm skips it, the routes above return `503 capability_unavailable`,
and WordPress falls back through its local service cascade.

In practice the compiler is rarely needed on Velocity:

- `canvas@3.x` ships npm-hosted napi prebuilt binaries for linux-x64-gnu.
- `canvas@2.x` (2.9+) ships prebuilt binaries for linux-x64-gnu via
  `prebuild-install` (downloaded from GitHub releases during `npm install`).

Only if a prebuild is unavailable (musl image, blocked download) does npm
fall back to compiling — which then needs `libcairo2-dev`/`libpango1.0-dev`
and falls under the same Cloudways-support question as ffmpeg below.

Actions:

1. Ask Cloudways support whether SSH/apt installs are permitted on Velocity
   (Cloudways servers are Ubuntu; Velocity's managed model may restrict it).
2. If permitted: `apt-get install -y ffmpeg chromium` (pin versions), set
   `PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser`, restart the app.
3. If not permitted: run the worker **in Docker on a VPS instead** for the
   video/browser groups, or accept degraded operation. The Chromium sandbox
   must stay enabled either way — never `ALLOW_NO_SANDBOX` on a public host.

## 6. Hardening Notes (already built into v2.2.0)

- **Auth:** `X-Site-Token` timing-safe check on all `/api/*` routes
  (`/api/health` stays public and minimal).
- **SSRF guard:** protocol allowlist, private/reserved-range blocklist
  (IPv4/IPv6, obfuscated forms), DNS resolution validation, redirect
  re-validation, and browser-level request interception for Puppeteer.
- **Rate limiting:** global + per-route-group limits (env-tunable).
- **Puppeteer:** sandbox enforced, `--no-sandbox` stripped, non-root user
  in Docker, download denial, concurrency cap.
- **Logging:** structured request logs with correlation IDs; no secrets
  logged; no stack traces in `production` responses.
- Optional extra layer: put a Cloudflare WAF or Velocity firewall rule in
  front and allowlist the WordPress server's egress IP.

## 7. Shared Worker Mode (Multi-Tenant, v2.4.0+)

One Velocity app can serve **multiple WordPress sites** when the isolation
boundaries below are acceptable (same owner/agency network). For different
clients, prefer one app per site — the sidecar model.

### Configuration

1. Worker env vars:
   - `SITE_TOKENS={"site-a":"<tokenA>","site-b":"<tokenB>"}` — one slug per
     WordPress site (slug: `[a-z0-9-]{1,32}`, e.g. the client name).
   - `AUTH_MODE=strict` — multi-tenant mode always fails closed.
   - Optional: `SITE_TOKENS_PREVIOUS` (rotation overlap), `TEMP_ROOT`,
     `TEMP_TTL`, per-site rate limits (`RATE_LIMIT_IMAGE_SITE-A=60`).
2. Each WordPress site keeps its **own** token (per-site
   `WP_MEDIA_WORKER_TOKEN` constant or `wp_mcp_ai_media_worker_token` option):
   site-a → `<tokenA>`, site-b → `<tokenB>`. The plugin already sends
   `X-Site-Token` + `X-Site-Url` — no plugin changes needed.

### Per-site isolation (v2.4.0)

| Dimension | Isolation |
|---|---|
| Auth identity | token → site slug; `req.site` on every request; fail-closed |
| Scratch files | `TEMP_ROOT/sites/<slug>/`; caller-supplied paths must stay in the namespace (`403 path_not_allowed` otherwise) |
| PDF sources | multipart upload support + site-scoped paths (`/api/pdf/extract`, `/api/pdf/render`) |
| Job queues | `queue:<site>:*` Redis keys; `/api/workflow/status` scoped per site |
| Rate limits | per-site buckets keyed `site:<slug>:<ip>` with per-site overrides |
| Audit logs | `site=<slug>` + site host on every request line; `X-Site-Url` change warnings |

### Still shared (know your boundaries)

- Provider API keys — pooled billing/quotas (per-site key maps are Phase 2,
  see proposal 026).
- System binaries (ffmpeg/Chromium) and the global rate-limit budget.
- `/api/health/full` capability matrix (adds a `tenants` block: mode, count,
  slugs — never tokens).

### Verify

```bash
curl -H "X-Site-Token: <tokenA>" https://<velocity-app-url>/api/health/full
# → "tenants": { "mode": "multi", "count": 2, "slugs": ["site-a", "site-b"] }
```

## 8. Deployment Flow (One-way Sync)

```
monorepo PR (addons/media-worker/**)
  → merge to alpha-working/main
  → sync-media-worker.yml (git subtree split, ~20 min)
  → force-push main on mcp-ai-wpoos-media-worker
  → Velocity auto-deploy
  → CI in the standalone repo (node tests, npm audit, syntax check)
```

Never commit to the standalone repo directly — the next sync overwrites it.
Dependency bumps go through the monorepo's `package.json` / `package-lock.json`.

## 9. Rollout Checklist

- [ ] v2.4.0 synced to the standalone repo (check version in `/api/health`)
- [ ] Velocity app provisioned, env set (token ≥32 chars, `AUTH_MODE=strict`)
- [ ] `bin/test-endpoints.sh` run against the app URL with `MEDIA_WORKER_TOKEN` set
- [ ] WordPress constants/options updated; Test Connection green
- [ ] Monitoring/uptime alert on `/api/health`
- [ ] Token rotated after the first 24 hours
