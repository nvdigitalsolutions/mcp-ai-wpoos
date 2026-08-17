# Media Worker Docker Setup — Design Stack

**Environment:** Windows 11 + WSL2 + Docker Desktop  
**Project:** `F:\DOCKER\design-stack`  
**Plugin:** `F:\GITHUB\mcp-ai-wpoos` (bind-mounted into WordPress)  
**Date:** August 2026

---

## Architecture

```
  Host: Windows 11 + WSL2 + Docker Desktop
  Docker Network: design-stack_default

  ┌──────────────┐   ┌──────────┐   ┌───────────┐
  │  wordpress   │   │    db    │   │   redis   │
  │   :8092      │   │  :3308   │   │  :6379    │
  └──────┬───────┘   └──────────┘   └─────┬─────┘
         │                                │
         │ wp_remote_post()               │ job queue
         ▼                                ▼
  ┌──────────────────────────────────────────┐
  │         media-worker :3100               │
  │                                          │
  │  AI Image Gen (DALL·E, Gemini, SDXL...)  │
  │  Image Optimization (Sharp)              │
  │  Video Processing (FFmpeg)               │
  │  Social Publishing (Twitter, FB, IG, LI) │
  │  ── Document Pipeline ──                 │
  │  PDF (extract, render, generate, merge)  │
  │  Excel (exceljs), Word (docx)            │
  │  OCR (tesseract.js)                      │
  │  Email (nodemailer, mjml)                │
  │  Code (prettier)                         │
  │  ── Utilities ──                         │
  │  Translate, Language Detect, QR Code     │
  │  Math (KaTeX), Calendar (ICS)            │
  │  Charts (Chart.js), Geospatial (Turf)    │
  │  Browser (Puppeteer)                     │
  │  Workflow Orchestration (Redis)          │
  └──────────────────────────────────────────┘
```

---

## Service Inventory

| Service | Container | Host Port | Purpose |
|---|---|---|---|
| WordPress | design-wp | 8092 | PHP/WordPress + mcp-ai-wpoos plugin |
| MySQL | design-db | 3308 | Database |
| Redis | design-redis | 6379 | Object cache + job queue |
| Media Worker | design-worker | 3100 | AI generation, document pipeline, utilities |
| Redis Commander | design-redis-ui | 8083 | Redis admin UI |
| WP Seed | design-wp-seed | — | Auto-setup (runs once) |
| Backup | design-backup | — | Daily config backup |
| Skill Sync | design-skill-sync | — | Daily skill pull from GitHub |

---

## Quick Start

```bash
# Start everything
wsl docker compose up -d

# Rebuild media-worker after code changes
wsl docker compose build --no-cache media-worker
wsl docker compose up -d media-worker

# Restart WordPress to pick up plugin changes
wsl docker compose restart wordpress

# View logs
wsl docker compose logs -f media-worker
wsl docker compose logs -f wordpress

# Stop everything
wsl docker compose down
```

---

## Media Worker — NPM Packages

### Image / AI (existing)
```
@google/generative-ai, openai, axios, sharp, canvas
```

### Video (existing)
```
fluent-ffmpeg, gif-encoder, subtitle
```

### Document Pipeline (new)
```
pdf-parse, pdfjs-dist, pdfkit, pdf-lib     # PDF
exceljs                                      # Excel
docx                                         # Word
tesseract.js                                 # OCR
puppeteer                                    # Browser / PDF render
```

### Communication (new)
```
nodemailer                                   # Email send
mjml                                         # Email templates
```

### Code (new)
```
prettier                                     # Code formatting
```

### Utilities (new)
```
franc, iso-639-1                             # Language detection
google-translate-api-x                       # Translation
qrcode                                       # QR codes
katex                                        # Math rendering
ics                                          # Calendar ICS
chart.js, chartjs-node-canvas                # Chart rendering
@turf/turf                                   # Geospatial analysis
```

### Infrastructure
```
express, cors, dotenv, ioredis, multer, cheerio, turndown
```

---

## Dockerfile

```dockerfile
FROM node:22-alpine

# System dependencies
RUN apk add --no-cache \
    ffmpeg \
    chromium \
    nss freetype harfbuzz ca-certificates ttf-freefont \
    cairo-dev pango-dev jpeg-dev giflib-dev librsvg-dev \
    vips-dev \
    fontconfig \
    build-base python3 pkgconfig pixman-dev

ENV PUPPETEER_SKIP_DOWNLOAD=true
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser

WORKDIR /app

# Install dependencies (build tools removed after native module compilation)
COPY package.json package-lock.json* ./
RUN npm ci --only=production 2>/dev/null || npm install \
    && apk del build-base python3 pkgconfig pixman-dev

COPY src/ ./src/
RUN mkdir -p /data/temp && chmod 777 /data/temp

EXPOSE 3100
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD wget --no-verbose --tries=1 --spider http://localhost:3100/api/health || exit 1

CMD ["node", "src/index.js"]
```

---

## Docker Compose (key excerpts)

### WordPress Plugin Mount
```yaml
wordpress:
  environment:
    WORDPRESS_CONFIG_EXTRA: |
      define( 'WP_MEDIA_WORKER_URL', 'http://media-worker:3100' );
  volumes:
    - ${MCP_PLUGIN_PATH:-/mnt/f/GITHUB/mcp-ai-wpoos}:/var/www/html/wp-content/plugins/mcp-ai-wpoos
```

### Media Worker
```yaml
media-worker:
  build:
    context: ./media-worker
    dockerfile: Dockerfile
  container_name: design-worker
  ports:
    - "${WORKER_PORT:-3100}:3100"
  environment:
    NODE_ENV: development
    PORT: "3100"
    REDIS_URL: redis://redis:6379
    WORDPRESS_URL: http://wordpress:80
  volumes:
    - ./media-worker/src:/app/src:ro
    - ./config/wp-uploads:/app/output
```

### .env
```env
MCP_PLUGIN_PATH=/mnt/f/GITHUB/mcp-ai-wpoos
```

> **Important:** Use WSL paths (`/mnt/f/...`) not Windows paths (`F:/...`) in `.env` — Docker on WSL2 doesn't understand Windows drive letters.

---

## Testing the Sidecar

### From Host
```bash
# Health check
curl http://localhost:3100/api/health

# Code formatting
curl -X POST http://localhost:3100/api/code/format \
  -H "Content-Type: application/json" \
  -d '{"code":"const x=1;","options":{"parser":"babel"}}'

# PDF generation
curl -X POST http://localhost:3100/api/pdf/generate \
  -H "Content-Type: application/json" \
  -d '{"html":"<h1>Test</h1><p>Hello world</p>"}'

# OCR
curl -X POST http://localhost:3100/api/ocr/recognize \
  -H "Content-Type: application/json" \
  -d '{"source":"/app/output/test.png","language":"eng"}'

# Math rendering
curl -X POST http://localhost:3100/api/data/render-math \
  -H "Content-Type: application/json" \
  -d '{"latex":"E=mc^2"}'

# Chart rendering
curl -X POST http://localhost:3100/api/data/render-chart \
  -H "Content-Type: application/json" \
  -d '{"type":"bar","data":{"labels":["A","B"],"datasets":[{"label":"T","data":[10,20]}]}}'
```

### From WordPress Admin
Go to **Settings → Media Worker** and click **Test Connection** — shows green indicator with all 18 capabilities listed.

### From WordPress CLI
```bash
wsl docker compose exec -T wordpress sh -c \
  "curl -s http://media-worker:3100/api/health"
```

---

## Troubleshooting

### "Trait not found" errors
The trait file exists but isn't autoloaded. Check `includes/bootstrap/loader.php` has the `require_once` for `trait-wp-mcp-ai-media-worker-client.php`.

### "Sorry, you are not allowed to access this page"
The admin page parent slug or priority is wrong. The page uses `add_options_page()` at priority 30.

### OCR route returns empty reply
Tesseract.js v5.1.1 crashes on `logger: undefined`. Always pass a function or omit the logger option entirely. Use `createWorker()` with explicit lifecycle (create → recognize → terminate).

### canvas npm install fails on Alpine
Add `build-base python3 pkgconfig pixman-dev` before `npm install`, remove after. The canvas package has no pre-built binaries for Node 22 on musl.

### Docker fails with "invalid volume specification"
The `.env` file has a Windows path (`F:/...`). Change to WSL path (`/mnt/f/...`).

### WordPress shows generic "critical error"
Enable `WP_DEBUG_DISPLAY` in `wp-config.php`:
```php
define( 'WP_DEBUG_DISPLAY', true );
```

### Media worker uses old code after file changes
The `docker compose restart` may not pick up bind mount changes. Use `docker compose down media-worker && docker compose up -d media-worker` for a clean restart.
