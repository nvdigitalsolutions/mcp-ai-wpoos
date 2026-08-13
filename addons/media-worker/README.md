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
| Video | `/api/video/generate`, `/api/video/process`, `/api/video/info` |
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

## Documentation

- [Sidecar Architecture & Implementation Report](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/project/proposals/media-worker-sidecar-proposal.md)
- [Docker Setup Guide](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/operations/deployment/media-worker-docker-setup.md)
- [Cloudways Velocity Setup Guide](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/operations/deployment/media-worker-velocity-setup.md)
- [Cloud Deployment & Security Plan](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/alpha-working/docs/project/proposals/025-media-worker-cloud-deployment-security-implementation-plan.md)

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
