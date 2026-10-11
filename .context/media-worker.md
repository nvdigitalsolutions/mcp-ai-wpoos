# NV oOS Media Worker Sidecar

> **GSD Context File** — Load this when working on the media worker (`addons/media-worker/`), the plugin sidecar client, or any Pro service that routes through the worker.
> Last reviewed: October 7, 2026 (v1.1.99, worker v3.4.0 — #6941's synthetic external-targets notes; the worker version bump stays release-process-owned per #6895).

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
- Version: **v3.4.0** (multi-tenant v2.4.0 → Phase 2 → Phase 3 W1–W7 → crawling + Crawl4AI facade → status monitoring module → Wave 1 image enhance/upscale routes).
- **Version sync (all five spots must move together on every bump — the
  #6881 miss, fixed by #6895, is the precedent):** `package.json` →
  `package-lock.json` (root `packages[""]` + the `design-media-worker` entry) →
  `src/index.js`'s `/api/health` `version:` field → `src/index.js`'s
  `/api/health/full` `version:` field → `src/status/handlers.js`
  `WORKER_VERSION` (feeds the fleet-status heartbeat payload). A partial bump
  leaves a deployed worker reporting the old version on `/api/health*` and in
  every status heartbeat — grep `addons/media-worker/src/` for the old version
  string after any bump.

## Key Paths

| Area | Files |
|---|---|
| Worker server / routes | `addons/media-worker/src/` (`index.js`, `routes/*.js`, `middleware/*.js`, `utils/*.js`) — incl. `routes/crawl.js` (native crawling), `routes/crawl4ai.js` (Crawl4AI facade), `utils/crawl-extract.js`, `utils/llm-extract.js`, `routes/status.js` + `status/*` (fleet monitoring, v3.3.0) |
| Env configuration | `addons/media-worker/.env.example` (canonical variable reference) |
| Deployment guides | `docs/operations/deployment/media-worker-docker-setup.md`, `media-worker-velocity-setup.md` |
| Load testing | `addons/media-worker/bin/load-test/` (k6 kit + split decision table) |
| WordPress probe | `addons/media-worker/bin/probe-wordpress.php` |
| Plugin client trait | `includes/traits/trait-wp-mcp-ai-media-worker-client.php` |
| Usage reporter | `includes/class-wp-mcp-ai-media-worker-usage-reporter.php` (daily cron, opt-in) |
| Pro service routing | `addons/pro/includes/npm-integration-filters.php`, `addons/pro/includes/services/*` |
| Pro settings | `addons/pro/includes/admin/class-wp-mcp-ai-media-worker-settings.php` (worker-routed packages listed) |
| Proposals | `docs/project/proposals/025` (deployment/hardening), `026` (multi-tenancy Phase 1), `027` (Phase 2 spec), `028` (Phase 3 scale), `031` (crawling + Crawl4AI facade) |

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

## Image Enhance / Upscale / Edit Routes (v3.4.0, W1a + W2a)

Sharp-native real implementations behind the plugin's placeholder
image-production tools (issue #6877, plan
`docs/project/plans/image-production-sidecar-cluster-plan.md`).

- `POST /api/image/enhance` — multipart `file` + fields `sharpen` (bool or
  0–1 strength), `contrast` (multiplier, default 1), `saturation`
  (multiplier, default 1), `denoise` (bool → `.median(1)`), `strength`
  (0–1 master scaling toward neutral). Envelope mirrors `/optimize` +
  `width`/`height` + an `enhancements` echo of the ops actually applied.
- `POST /api/image/upscale` — multipart `file` + `factor` (2/4/8, validated)
  + `kernel` (`nearest`/`cubic`/`lanczos3`, default lanczos3). Output
  dimensions capped at **8192px** per side (shared constant with
  `addons/pro/bin/sharp-process.js` and the
  `WP_MCP_AI_Sharp_Image_Processing` Pro trait). Responds with honest
  `upscale_method: 'lanczos3'` until Wave 3 lands an AI engine.
- `POST /api/image/edit` (W2a) — multipart `file` + `operation`
  (`colorize` | `style_transfer`), `style` (preset slug; the
  `STYLE_PROMPTS` map mirrors the plugin tools' `get_style_prompts()`),
  `prompt` (optional override), `provider` (`auto` default → Gemini →
  OpenAI → Replicate key chain). Gemini edits via
  `gemini-3.1-flash-image` (inline source image), OpenAI via
  `gpt-image-1` `images.edit`, Replicate via predictions polling
  (`REPLICATE_COLORIZE_MODEL`, default `deoldify/deoldify`;
  `REPLICATE_STYLE_TRANSFER_MODEL` required for style_transfer — no
  deterministic default is assumed). No key → `503
  capability_unavailable` (capability `image_editing`) per the existing
  contract.
- All three routes preserve the source image format and carry the standard
  `success`/`original_size`/`optimized_size`/`savings_*`/`b64` envelope so
  the plugin's `WP_MCP_AI_Media_Worker_Client` needs no new parsing paths.

## Crawling & Crawl4AI Facade (v3.2.0)

- `POST /api/crawl/markdown` — single URL → clean Markdown.
- `POST /api/crawl/markdown-batch` — multiple URLs (sync or queued async via the shared queue).
- `POST /api/crawl/links` — extract links from a page.
- `POST /api/crawl4ai/*` — Crawl4AI-compatible facade so the plugin's `run_crawl4ai_job` remote mode can target the worker as a drop-in Crawl4AI replacement.
- Two-tier extraction (pullmd-style): **static** HTTP fetch → Readability → Turndown first (redirect hops re-validated, zero browser cost); **browser** tier (hardened Chromium, existing SSRF request interception) used automatically when static output is too thin or via `render: "always"`.
- **Every URL passes the shared SSRF guard** (`utils/safe-url.js` `resolvePublicUrl` / `validatePublicUrl`) before any fetch or navigation — do not bypass it in new crawl features.
- `utils/llm-extract.js` extracts structured data from page content using a configured LLM provider (keys resolved via `utils/provider-keys.js`).
- Toolkit memory estimate accounts for the worker sidecar — see `docs/features/TOOLKIT_MEMORY_TRACKING.md`.

## Full-Crawl4AI Proxy (031 Phase 3, v1.1.65)

- `POST /api/crawl/full` + `GET /api/crawl/full/task/:task_id` — when `CRAWL4AI_FULL_URL` points at a real Crawl4AI deployment, the worker acts as the SSRF-validated, token-gated forwarder (`submitFullCrawl()` / `getFullTaskStatus()` in `routes/crawl.js`).
- **SSRF guard runs on every target BEFORE proxying** — private/loopback targets can never reach the Python service through the worker. The upstream itself is deliberately NOT SSRF-checked (reaching a private sibling container is the point).
- Envelope contract: `503 service_not_configured` when the env var is unset, `502 upstream_unreachable` on upstream failure, `400` for invalid payloads; the body is forwarded contract-preserving so the plugin's Crawl4AI client needs no proxy awareness.
- **TEMP_ROOT allowlist (proposal 028 Q5):** under `STRICT_PATHS=1` (or `STRICT_PDF_PATHS=1`) an explicit `TEMP_ROOT` is the allowlisted sandbox root in single-tenant mode; the strict-path default flip stays deferred to worker 4.0.0.
- Worker version stays **v3.2.0** — the feature shipped without a package bump; treat `package.json` as authoritative over the proposal's stale "3.2.1+" note (reconciled in the v1.1.65 pass).

## Status Monitoring Module (v3.3.0)

- Opt-in (`STATUS_ENABLED=1`): the worker doubles as the fleet status
  service. Connected sites push signed heartbeats to
  `POST /api/status/heartbeat` (slug always derived from `X-Site-Token`,
  never the payload); the dead man's switch sweeper (`status/sweeper.js`)
  flips sites to `at_risk` then `major_outage` on missed beats.
- Summary/history/metrics: `GET /api/status/summary`, `/sites/:slug`,
  `/history/:slug?days=`, `/metrics` (OpenMetrics, opt-in). Public,
  allowlisted page at `GET /status` behind `STATUS_PUBLIC_PAGE=1`.
- Synthetic checks (`STATUS_SYNTHETIC_ENABLED=1`) probe public sites over
  the shared SSRF guard (`status/checks.js`); heartbeat-fresh + synthetic-
  down = `partial_outage` (split-brain).
- **External targets (v3.4.0 line, #6941):** heartbeat-less targets join via
  `STATUS_EXTERNAL_TARGETS=gateway=https://mcp.nvoos.pro/health`
  (comma-separated `slug=url`); `seedExternalTargets()` seeds them
  idempotently, `startSyntheticLoop()` probes the enabled ones and writes
  only `record.synthetic` — the sweeper remains the **single owner of
  status transitions**. Per-target enables: `STATUS_<SLUG>_SYNTHETIC=1` +
  `STATUS_<SLUG>_SYNTHETIC_URL` override. Synthetic-only mode reports
  `operational` on a passing probe, `major_outage` on a failing one,
  `unknown` before the first probe. Two #6941 fixes: the split-brain
  fallback reads `record.synthetic.checkedAt` (the old `.checked` key was
  set by no code), and the sweeper clears `downSince` on recovery (matches
  the heartbeat handler). Probe URL fallback honors `site.syntheticUrl`
  (`checks.js`).
- Status taxonomy is the plugin's five-value service-status taxonomy plus
  the internal `at_risk` band (`status/state.js` severity table) — keep
  them in sync with `Interface_WP_MCP_AI_Service_Status_Source`.
- Plugin side (`includes/`, v1.1.93): `WP_MCP_AI_Media_Worker_Config`
  (shared URL/token chain), `WP_MCP_AI_Status_Heartbeat` (5-min-tick
  emitter, opt-in), `WP_MCP_AI_Status_Alert_Poller` (pull-diff
  `wp_mcp_ai_site_status_event`), `remote_monitor` status source, tools
  `get_fleet_status` / `get_site_uptime`, REST `GET /mcp-ai/v1/status/sites`,
  and Pro incident automation (`WP_MCP_AI_Pro_Status_Alerts`).
- Full design: `docs/project/plans/media-worker-status-monitoring-plan.md`.

## Canonical Facts (avoid drift)

- 12 route groups: browser, code, data, document, email, image, ocr, pdf,
  social, video, workflow — plus `crawl`, `crawl4ai` (v3.2.0) and `status`
  (v3.3.0); image gained `enhance` + `upscale` (W1a) and `edit` (W2a) in
  v3.4.0.
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
  and the status heartbeat/config/poller are Base
- `addons/media-worker/README.md` — worker-side docs
- `docs/project/plans/media-worker-status-monitoring-plan.md` — status module design
- Folder READMEs for `addons/pro/includes/services/` when editing a routed service
