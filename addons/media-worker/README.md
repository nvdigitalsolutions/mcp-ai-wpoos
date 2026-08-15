# Media Worker Sidecar

Optional Node.js sidecar for the mcp-ai-wpoos WordPress plugin. Offloads heavy NPM-package operations from WordPress to a dedicated Docker container. When available, service classes automatically route via HTTP. When unavailable, existing local fallbacks continue unchanged.

## Structure

```
addons/media-worker/
├── Dockerfile
├── package.json
├── README.md
├── .dockerignore
└── src/
    ├── index.js          # Express server + health check
    └── routes/
        ├── image.js      # AI image generation + optimization
        ├── video.js      # Video processing + generation
        ├── social.js     # Social media publishing
        ├── workflow.js   # Redis-backed job orchestration
        ├── pdf.js        # PDF extract, render, generate, merge, watermark
        ├── document.js   # Excel + Word generation
        ├── ocr.js        # Tesseract.js OCR
        ├── email.js      # Nodemailer + MJML
        ├── code.js       # Prettier formatting
        ├── data.js       # Translate, language detect, QR, math, ICS, charts, geospatial
        └── browser.js    # Puppeteer screenshot + PDF
```

## Quick Start (Standalone)

```bash
# Monorepo:
cd addons/media-worker
docker build -t media-worker .
docker run -p 3100:3100 media-worker

# Standalone repo (this folder is the repo root):
docker build -t media-worker .
docker run -p 3100:3100 media-worker
```

## Docker Compose (with WordPress)

```yaml
media-worker:
  build:
    context: ./addons/media-worker
  ports:
    - "3100:3100"
  environment:
    - REDIS_URL=redis://redis:6379

wordpress:
  environment:
    WORDPRESS_CONFIG_EXTRA: |
      define( 'WP_MEDIA_WORKER_URL', 'http://media-worker:3100' );
```

## Endpoints

| Group | Routes |
|---|---|
| Image | `/api/image/generate`, `/api/image/optimize`, `/api/image/providers` |
| Video | `/api/video/generate`, `/api/video/process`, `/api/video/info`, `/api/video/download/:name` |
| Social | `/api/social/post`, `/api/social/generate-content` |
| PDF | `/api/pdf/extract`, `/api/pdf/render`, `/api/pdf/generate`, `/api/pdf/merge`, `/api/pdf/watermark` |
| Document | `/api/document/excel`, `/api/document/word` |
| OCR | `/api/ocr/recognize` |
| Email | `/api/email/send`, `/api/email/compile-mjml` |
| Code | `/api/code/format`, `/api/code/check-syntax` |
| Data | `/api/data/translate`, `/api/data/language-detect`, `/api/data/qrcode`, `/api/data/render-math`, `/api/data/generate-ics`, `/api/data/render-chart`, `/api/data/analyze-geospatial` |
| Browser | `/api/browser/screenshot`, `/api/browser/pdf` |
| Health | `GET /api/health` |

## Plugin Integration

The WordPress plugin auto-detects the sidecar. Set the constant in `wp-config.php`:

```php
define( 'WP_MEDIA_WORKER_URL', 'http://media-worker:3100' );
```

Or configure via **Settings → Media Worker** in the WordPress admin.

## Security Model (v2.2.0+)

The worker is designed to sit behind network isolation (Docker sidecar) or —
with these controls — on a managed public host like Cloudways Velocity:

- **Auth:** every `/api/*` route requires an `X-Site-Token` header matching
  the `WORKER_API_TOKEN` env var (timing-safe compare). The WordPress plugin
  sends this automatically (`WP_MEDIA_WORKER_TOKEN` constant or the
  `wp_mcp_ai_media_worker_token` option). `/api/health` stays public and
  minimal; `/api/health/full` is authenticated.
- **SSRF guard:** user-supplied URLs are validated (protocol allowlist,
  private/reserved-range blocklist incl. IPv6 and obfuscated forms, DNS
  resolution checks) before any fetch or Puppeteer navigation.
- **Puppeteer:** Chromium runs **with its sandbox** (`--no-sandbox` is
  stripped even if passed via `PUPPETEER_ARGS`); rendered-page requests are
  intercepted and re-validated; downloads denied; launches capped.
- **Rate limiting:** global + per-route-group limits, env-tunable.
- **Hardening:** Helmet headers, restricted CORS (`ALLOWED_ORIGINS`), 10 MB
  JSON body limit, structured request logs (no secrets), no stack traces in
  production responses, graceful shutdown.

All environment variables are documented in [.env.example](.env.example).

## Multi-Tenant Mode (v2.4.0+)

Optional shared-worker mode for serving **multiple WordPress sites** from one
instance. Set `SITE_TOKENS` (JSON map of site slug → token) plus
`AUTH_MODE=strict`; every site then gets:

- its own token (per-site `WP_MEDIA_WORKER_TOKEN` — the plugin already sends
  `X-Site-Token` and `X-Site-Url`, no plugin changes needed),
- a namespaced scratch filesystem (`TEMP_ROOT/sites/<slug>/`) with strict
  caller-supplied path checks (`403 path_not_allowed`),
- site-scoped Redis queue keys and workflow status,
- per-site rate-limit budgets (`RATE_LIMIT_IMAGE_SITE-A=60` overrides),
- audit logs tagged `site=<slug>` with `X-Site-Url` change warnings.

PDF routes (`/api/pdf/extract`, `/api/pdf/render`) accept multipart file
uploads so managed hosts without a shared volume can still use them.
Single-tenant deployments are unaffected. Full design: proposal
[026](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/project/proposals/026-media-worker-multi-tenancy-sidecar-proposal.md).

### Per-Site Provider Keys & Observability (Phase 2, v2.5.0)

- **`SITE_PROVIDER_KEYS`** — JSON map of site slug → credential object
  (`openai_api_key`, `gemini_api_key`, `firefly_client_id`, social tokens, …)
  resolved per tenant before falling back to the shared pool;
  `PROVIDER_KEYS_STRICT=1` disables the shared-pool fallback for hard
  isolation.
- **Per-site usage counters** — `tenants.usage` tracks provider usage per
  site on image/social routes.
- **Grouped temp TTLs** — `TEMP_TTL_UPLOAD/VIDEO/BROWSER/DOC` per-group
  lifetimes with storage stats.
- **Cluster warnings + k6 kit** — boot warnings on PM2 cluster mode;
  `bin/load-test/` k6 load tests with a split decision table.

Full spec: proposal
[027](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/project/proposals/027-media-worker-multi-tenancy-phase2-spec.md)
(implemented, PR #5868).

### Operational Scale (Phase 3, v3.0.0)

- **WordPress multisite per-blog tokens** — additive option chain in the
  plugin sidecar client.
- **Usage Reporter** — plugin-side daily cron snapshots `tenants.usage`
  into an option and fires `wp_mcp_ai_media_worker_usage_updated`
  (opt-in, off by default).
- **Env-var merges** — `SITE_TOKEN_<SLUG>` / `SITE_PROVIDER_KEYS_<SLUG>`
  merge over the JSON maps for platform env-size limits (env wins).
- **Opt-in Redis rate-limit store** — `RATE_LIMIT_REDIS=1` with
  `rate-limit-redis` optional dependency; required for PM2 cluster mode.
- **`PROVIDER_KEYS_FILE` hot-reload** — watched JSON file with atomic
  replace; malformed updates are rejected and the previous map stays
  active.
- **Strict-path note** — single-tenant PDF path checks stay permissive;
  set `STRICT_PATHS=1` to enforce the site namespace now (boot notice
  included).

Full plan: proposal
[028](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/project/proposals/028-media-worker-phase3-proposal.md).

## Documentation

- [Sidecar Architecture & Implementation Report](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/project/proposals/media-worker-sidecar-proposal.md)
- [Docker Setup Guide](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/operations/deployment/media-worker-docker-setup.md)
- [Cloudways Velocity Setup Guide](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/operations/deployment/media-worker-velocity-setup.md)
- [Cloud Deployment & Security Plan](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/project/proposals/025-media-worker-cloud-deployment-security-implementation-plan.md)
- [Multi-Tenancy Sidecar Proposal (Phase 1)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/project/proposals/026-media-worker-multi-tenancy-sidecar-proposal.md)
- [Phase 2 Spec — Per-Site Provider Keys & Scale Guide](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/project/proposals/027-media-worker-multi-tenancy-phase2-spec.md)
- [Phase 3 Proposal — Scale Without Breaking Changes](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/project/proposals/028-media-worker-phase3-proposal.md)
- [Load-Test Kit (k6)](bin/load-test/README.md)

## Repository Sync

This folder is mirrored one-way to the standalone repo
[nvdigitalsolutions/mcp-ai-wpoos-media-worker](https://github.com/nvdigitalsolutions/mcp-ai-wpoos-media-worker)
via the `sync-media-worker.yml` git-subtree GitHub Action. Changes committed
directly to the standalone repo are overwritten by the next sync — contribute
changes through the monorepo instead.

## Credits

All third-party npm packages are declared in `package.json`. The v2.2.0
security release added **helmet** ^8.0.0 (MIT, security headers) and
**express-rate-limit** ^7.5.0 (MIT, rate limiting). The full per-package
license table lives in the monorepo root [`CREDITS.md`](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/CREDITS.md)
under *"JavaScript Dependencies — Add-ons → addons/media-worker/"*.
