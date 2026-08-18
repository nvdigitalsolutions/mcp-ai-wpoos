# NV oOS Media Worker Sidecar

> **GSD Context File** — Load this when working on the media worker (`addons/media-worker/`), the plugin sidecar client, or any Pro service that routes through the worker.
> Last reviewed: August 2026 (v1.1.58, worker v3.0.0).

---

## What It Is

`addons/media-worker/` is a Docker-based Node.js sidecar that offloads heavy
NPM-package operations (image/video generation, PDF/Word/Excel, OCR, email,
browser automation, social publishing) from WordPress to a separate process.
When the worker is reachable, plugin services route via HTTP; when not, the
existing local fallbacks run unchanged.

- Monorepo folder is mirrored one-way to the standalone repo
  `mcp-ai-wpoos-media-worker` via `.github/workflows/sync-media-worker.yml`
  — never commit to the standalone repo directly.
- Version: **v3.0.0** (multi-tenant v2.4.0 → Phase 2 → Phase 3 W1–W7).

## Key Paths

| Area | Files |
|---|---|
| Worker server / routes | `addons/media-worker/src/` (`index.js`, `routes/*.js`, `middleware/*.js`, `utils/*.js`) |
| Env configuration | `addons/media-worker/.env.example` (canonical variable reference) |
| Deployment guides | `docs/operations/deployment/media-worker-docker-setup.md`, `media-worker-velocity-setup.md` |
| Load testing | `addons/media-worker/bin/load-test/` (k6 kit + split decision table) |
| WordPress probe | `addons/media-worker/bin/probe-wordpress.php` |
| Plugin client trait | `includes/traits/trait-wp-mcp-ai-media-worker-client.php` |
| Usage reporter | `includes/class-wp-mcp-ai-media-worker-usage-reporter.php` (daily cron, opt-in) |
| Pro service routing | `addons/pro/includes/npm-integration-filters.php`, `addons/pro/includes/services/*` |
| Pro settings | `addons/pro/includes/admin/class-wp-mcp-ai-media-worker-settings.php` (worker-routed packages listed) |
| Proposals | `docs/project/proposals/025` (deployment/hardening), `026` (multi-tenancy Phase 1), `027` (Phase 2 spec), `028` (Phase 3 scale) |

## Architecture Rules

1. **Routing is additive.** Worker routing never removes the local fallback
   path — every routed service keeps its in-WordPress implementation as the
   no-worker default.
2. **Multi-tenant mode fails closed.** `SITE_TOKENS` + `AUTH_MODE=strict`:
   every `/api/*` request must carry a known site token; files, queues, and
   rate limits are namespaced per site slug.
3. **Per-site provider keys resolve before the shared pool.**
   `SITE_PROVIDER_KEYS` / `SITE_PROVIDER_KEYS_<SLUG>` → shared env pool →
   `503 capability_unavailable`. `PROVIDER_KEYS_STRICT=1` disables the
   shared-pool fallback.
4. **Phase 3 is opt-in.** Defaults preserve previous behavior;
   `RATE_LIMIT_REDIS=1` (cluster mode), `PROVIDER_KEYS_FILE` hot-reload,
   `STRICT_PATHS=1` are all explicit opt-ins.
5. **Plugin-side token chain (multisite-aware, Phase 3 W1):**
   `WP_MEDIA_WORKER_TOKEN` constant → per-blog option →
   `wp_mcp_ai_media_worker_token` site option.

## Canonical Facts (avoid drift)

- 11 route groups: browser, code, data, document, email, image, ocr, pdf,
  social, video, workflow.
- Security baseline: timing-safe `X-Site-Token` auth, SSRF guard, sandboxed
  Puppeteer, express-rate-limit, Helmet, structured logs, split health
  endpoints (`/api/health/basic`, `/api/health/full`).
- Rotation: `WORKER_API_TOKEN_PREVIOUS` (single-tenant) and
  `SITE_TOKENS_PREVIOUS` (multi-tenant) accept the previous token during the
  overlap window.
- Cluster mode: in-memory queue stays single-process — Redis queue requires
  `REDIS_URL`; Redis rate-limit store requires `RATE_LIMIT_REDIS=1` +
  `rate-limit-redis` optional dependency.
- Node engine floor **≥ 22.12.0** (puppeteer 25 requirement; Docker and CI
  images are `node:22`). Puppeteer downloads are skipped with the canonical
  `PUPPETEER_SKIP_DOWNLOAD` env var — the legacy
  `PUPPETEER_SKIP_CHROMIUM_DOWNLOAD` name is ignored by newer releases.

## Also Load

- `.context/security-checklist.md` — worker hardening entries (always)
- `.context/settings-storage.md` — how the plugin stores worker tokens/options
- `.context/pro-vs-base.md` — worker routing lives in Pro services; the trait
  is Base
- `addons/media-worker/README.md` — worker-side docs
- Folder READMEs for `addons/pro/includes/services/` when editing a routed service
