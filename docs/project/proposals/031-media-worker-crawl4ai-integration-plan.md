# Proposal 031 — Crawling in the Media Worker: Native Crawl Endpoints + Crawl4AI-Compatible Facade

**Version:** 1.2.0
**Date:** 2026-08-18
**Status:** Phases 1–3 implemented (Phase 3 = env-gated `/api/crawl/full` proxy, `CRAWL4AI_FULL_URL`; 503 when unset)
**Scope:** `addons/media-worker/` (Node.js sidecar), optional plugin-side configuration, documentation
**Related:** [CRAWL4AI_BUNDLING_RECOMMENDATION.md](../../features/tools/crawl4ai/CRAWL4AI_BUNDLING_RECOMMENDATION.md), [026](../026-media-worker-multi-tenancy-sidecar-proposal.md), [028](../028-media-worker-phase3-proposal.md), [sidecar-expansion-plan.md](../sidecar-expansion-plan.md), [PLAYWRIGHT_SERVICE_IMPLEMENTATION.md](../PLAYWRIGHT_SERVICE_IMPLEMENTATION.md)

> **Implementation note (v1.1.1, worker 3.1.1):** while wiring the facade's
> queue processor, a pre-existing gap surfaced — the worker's job queues had
> **no registered processors anywhere**, so `async_mode` workflow jobs and
> scheduled social posts were enqueued but never executed. Fixed by
> registering lazy processors for every enqueued job type
> (`workflow` × 3, `social-scheduled` × 1, `crawl` × 1, `crawl4ai` × 1) that
> replay jobs through the worker's own sync endpoints, reconstructing the
> caller's `X-Site-Token` from the worker's config (never stored in job
> data). See `README.md` → "Job Queue Processors (v3.1.1+)".
>
> **Implementation note (v1.2.0, worker 3.2.0):** `LLMExtractionStrategy` is
> now multi-provider — OpenAI, Google Gemini, Anthropic Claude, DeepSeek,
> and any OpenAI-compatible endpoint via `CRAWL_LLM_BASE_URL` (Ollama, Groq,
> OpenRouter, …) — with explicit override (`CRAWL_LLM_PROVIDER`),
> autodetection from the first configured credential, per-site credential
> resolution, and hardened JSON parsing of every provider response. See
> `src/utils/llm-extract.js` and the README "LLM extraction providers"
> section.
>
> **Implementation note (2026-08-26, worker v3.2.0+):** Phase 3 shipped as
> the env-gated proxy — `POST /api/crawl/full` + `GET /api/crawl/full/task/:id`
> forward to `CRAWL4AI_FULL_URL` (contract-preserving, SSRF-validated targets,
> token-gated like every `/api` route, `503 service_not_configured` when the
> env var is unset, `502 upstream_unreachable` on upstream failure). See
> `src/routes/crawl.js` → `submitFullCrawl()` / `getFullTaskStatus()` and the
> README "Managed Node.js hosts (Cloudways Velocity)" section. Compose wiring
> for the sibling container is intentionally left to deployers (the proxy is
> host-agnostic; a `crawl4ai` compose service example lives in the standalone
> repo's README).

---

## 1. Executive Summary

We researched whether the Crawl4AI service can or should be added to the media
worker. Findings:

1. **Crawl4AI is Python-only** (FastAPI + Playwright-Python, ~1 GB runtime,
   1–2.5 GB RAM per browser pool). It cannot be installed into the Node.js
   media worker as a dependency, and bundling the Python service *inside* the
   worker's Docker image is an anti-pattern (multi-process container) that
   industry guidance and this repo's own prior analysis both reject.
2. **The media worker already owns ~80% of Crawl4AI's machinery** — hardened
   Puppeteer/Chromium (`src/utils/browser.js`), an OWASP-grade SSRF guard
   (`src/utils/safe-url.js`), auth, rate limiting, multi-tenant scratch dirs,
   and a Redis job queue. It also already ships `turndown` and `cheerio`.
3. **The practical path is two-layered:**
   - **Phase 1** — implement crawling *natively* in Node (static fetch →
     Readability → Turndown, with headless-Chromium fallback for JS-heavy
     pages). Covers the dominant "URL → clean markdown" use case.
   - **Phase 2** — expose a **Crawl4AI-compatible REST facade**
     (`/api/crawl4ai/crawl` + `/api/crawl4ai/task/:id`) backed by the worker's
     Redis queue, so the WordPress plugin's existing `run_crawl4ai_job`
     remote mode can point `WP_MCP_AI_CRAWL4AI_BASE_URL` at the worker with
     **zero plugin code changes**. The media worker *becomes* a
     Crawl4AI-compatible service.
   - **Phase 3 (optional, full parity)** — run the real Crawl4AI as a
     *sibling* container in the same compose stack, optionally proxied behind
     the worker's auth. Only needed for LLM-based extraction strategies and
     deep crawling.

Effort estimate: **~1–1.5 developer-weeks** for Phases 1–2 (small, additive
surface), plus ~1 day for the optional Phase 3 compose wiring.

---

## 2. Research Summary (2026-08)

| Source | Finding |
|---|---|
| Crawl4AI docs (self-hosting) | Official deployment is its own Docker image (FastAPI + Playwright + Chromium, supervisord-managed); Docker Compose is the recommended method. Multi-arch amd64/arm64. |
| Crawl4AI repo analysis (DeepWiki) | Production image packages Redis + Gunicorn in one container via supervisord — heavy, Python-centric stack. |
| Kubernetes sidecar pattern docs | Companion containers are the endorsed way to pair services in different languages ("primary in Node.js while the sidecar is optimized in Rust/Go/Python"). Single-responsibility per container is the core principle. |
| Node.js scraping ecosystem | The standard Node equivalent of Crawl4AI's core path is Playwright/Puppeteer + `@mozilla/readability` + `turndown` (crawldown, pullmd, many MCP servers). Pullmd's tiering — static fetch first, headless browser only as fallback — is the cost-efficient industry pattern. |
| This repo, Jan 2026 | `CRAWL4AI_BUNDLING_RECOMMENDATION.md` already ruled against bundling Crawl4AI into the plugin; recommended a separate Docker service (`nvdigital/crawl4ai-service`) beside WordPress. |

**Verdict:** "Adding Crawl4AI to the media worker" is *possible and practical*
in exactly two forms — native Node reimplementation of the core capability
(Phases 1–2) and sibling-container sidecar (Phase 3). Embedding the Python
service inside the worker container is **not** recommended.

---

## 3. Options Evaluated

| # | Option | Feasible | Practical | Verdict |
|---|---|---|---|---|
| A | Bundle Crawl4AI (Python) inside the media-worker image | Technically yes (multi-stage + supervisord) | ❌ | Rejected — multi-runtime container, ~2 GB image, breaks Node CI/lifecycle, violates one-process-per-container |
| B | Native Node crawl endpoints in the worker (+ Crawl4AI-compatible facade) | ✅ | ✅ | **Do this (Phases 1–2)** |
| C | Crawl4AI as sibling container, optionally proxied by the worker | ✅ | ✅ (when parity needed) | **Optional Phase 3** |
| D | Worker as thin proxy to an external Crawl4AI host | ✅ | ✅ | Folded into Phase 3 (`CRAWL4AI_FULL_URL` proxy route) |

---

## 4. Target Architecture

```
┌────────────────────────────────────────────────────────────────────┐
│                  WordPress plugin (unchanged code)                 │
│  run_crawl4ai_job tool                                             │
│   ├─ local mode   → built-in HTTP fetch (unchanged, fallback)      │
│   └─ remote mode  → WP_MCP_AI_CRAWL4AI_BASE_URL                    │
│                     = http://media-worker:3100/api/crawl4ai        │
│                     (WP-Cron poller + job drawer work unchanged)   │
└──────────────────────────────┬─────────────────────────────────────┘
                               │ X-Site-Token
┌──────────────────────────────▼─────────────────────────────────────┐
│                     Media Worker (:3100, Node)                     │
│                                                                     │
│  /api/crawl/markdown          static fetch → Readability → Turndown│
│  /api/crawl/markdown-batch    + headless Chromium fallback         │
│  /api/crawl/links             (reuses hardened browser + SSRF)     │
│                                                                     │
│  /api/crawl4ai/crawl          Crawl4AI-compatible contract         │
│  /api/crawl4ai/task/:id       backed by Redis job queue            │
│                                                                     │
│  /api/crawl/full/*            (Phase 3 only) proxy to sibling      │
└──────────────────────────────┬─────────────────────────────────────┘
                               │ docker network (Phase 3 only)
                    ┌──────────▼──────────┐
                    │ crawl4ai (optional) │  unclecode/crawl4ai image
                    │ FastAPI :11235      │  mem_limit 2g, cpus 2
                    └─────────────────────┘
```

Routing rules (keeps `design-media-workflow` "MCP first / one clear path"
rule intact):

1. `run_crawl4ai_job` remote mode → media worker facade (Phase 2) — the
   single canonical crawling path when the worker is deployed.
2. `run_crawl4ai_job` local mode stays the no-worker fallback — unchanged.
3. Pro `web_browser` tool keeps its own Playwright service — **out of scope**
   for this proposal (consolidation deferred, noted in §11 open questions).

---

## 5. Phase 1 — Native Crawl Endpoints (Node, ~2–3 days)

### 5.1 New dependencies

| Package | Version | License | Purpose |
|---|---|---|---|
| `@mozilla/readability` | ^0.6.0 | Apache-2.0 | Article/main-content extraction (Firefox Reader View engine) |
| `jsdom` | ^24+ (current stable) | MIT | DOM for Readability in Node |

Already present and reused: `puppeteer` ^25.7, `turndown` ^7.2, `cheerio` ^1.0,
`axios`, `validator`.

> **Housekeeping:** add both packages to the *"JavaScript Dependencies —
> Add-ons → addons/media-worker/"* table in root `CREDITS.md`.

### 5.2 New files

```
addons/media-worker/src/
├── routes/crawl.js              # NEW — crawlRouter: /markdown, /markdown-batch, /links
├── utils/crawl-extract.js       # NEW — static HTML → { title, markdown, links } (readability+jsdom+turndown)
└── routes/crawl.test.js         # NEW — node --test unit suite (no browser required)
```

Plus small edits to `src/index.js` (wire router, health, startup log),
`src/middleware/rate-limit.js` (new `crawl` group), `package.json`
(deps), `.env.example` (new vars), `README.md` (endpoint table + structure).

### 5.3 Endpoint contracts

**`POST /api/crawl/markdown`**

```jsonc
// Request
{
  "url": "https://example.com/article",
  "render": "auto",            // "auto" | "never" | "always"  (default "auto")
  "wait_selector": null,       // optional CSS selector to await before extraction
  "timeout_ms": 30000,         // per-URL budget (clamped to CRAWL_TIMEOUT_MS)
  "include_links": false       // also return extracted <a> hrefs
}
// Response (200)
{
  "success": true,
  "title": "…",
  "markdown": "…",
  "final_url": "https://example.com/article?utm=…",
  "status_code": 200,
  "rendered": false,           // true when the headless browser was used
  "extraction_ms": 812,
  "links": []                  // only when include_links
}
```

**`POST /api/crawl/markdown-batch`** — `{ "urls": [...], "async_mode": false,
"callback_url": null }`. Sync mode returns `{ results: [...] }` with
per-URL error objects (never fail the whole batch); async mode enqueues a job
and returns `{ success, async: true, job_id }` (mirror of
`/api/workflow/social-package`).

**`POST /api/crawl/links`** — `{ "url": "…", "internal_only": false }` →
`{ success, links: [ { href, text, is_internal } ] }` via cheerio; supports
the `run_crawl4ai_job` `link_scan` mode.

### 5.4 Extraction tiering (industry pattern — pullmd-style)

1. **Static first.** `axios` GET with the crawl UA and a
   `CRAWL_MAX_BYTES` cap (default 5 MB — abort beyond it). Strip
   `<script>/<style>`, run Readability via jsdom. If the extracted text is
   ≥ `CRAWL_MIN_TEXT_CHARS` (default 200), return — no browser cost.
2. **Browser fallback** (`render: "auto"`): launch via
   `launchHardenedBrowser()`, `hardenPage(page)`, `page.goto` with the
   request-intercept SSRF guard active, then re-run the same static
   extraction on `page.content()`. `render: "always"` skips tier 1;
   `render: "never"` skips tier 2 (pure fetch mode, parity with the plugin's
   local fallback but with Readability quality).
3. Turndown converts the Readability article HTML to markdown
   (`turndown` is already a dependency — used by document routes today).

### 5.5 Security (inherited + new)

- **Entry validation:** `resolvePublicUrl(url)` before *every* fetch or
  navigation (DNS-rebinding control, §safe-url.js).
- **Redirect re-validation:** the browser path is already protected by
  `hardenPage()` request interception; the static path must re-validate
  every redirect hop with `resolvePublicUrl()` before following (Axios
  `maxRedirects: 5` + manual hop validation).
- **Downloads denied, sub-resource requests re-validated:** existing
  `hardenPage()` covers the browser tier.
- **Auth:** route sits behind the existing `/api` `authMiddleware`
  (`X-Site-Token`, timing-safe) — no new surface.
- **Rate limiting:** new `crawl` group in `GROUPS`
  (`windowMs: 10 min, limit: 20, env RATE_LIMIT_CRAWL`), auto-inheriting
  per-site overrides (`RATE_LIMIT_CRAWL_SITE-A`) and the Redis-store swap
  from Phase 3 W4 — zero extra work, the group map is generic.
- **Concurrency/memory:** `launchHardenedBrowser()` already caps concurrent
  Chromium launches (`PUPPETEER_MAX_CONCURRENT`); batch sizes capped by
  `CRAWL_MAX_URLS_BATCH` (default 10).
- **Output safety:** markdown is returned as JSON, never rendered by the
  worker; the plugin already escapes/scrubs crawl output before LLM
  streaming (existing content-sanitisation path).
- **Politeness/compliance:** add `CRAWL_USER_AGENT` (default
  `nvoos-media-worker/3.0 (+https://github.com/nvdigitalsolutions/mcp-ai-wpoos)`)
  and an optional `respect_robots_txt` request flag (default `false` for
  Crawl4AI parity, documented, with a warning in the route docs that
  operators should enable it for production crawling).

### 5.6 Health + observability

- `GET /api/health/full` gains `web_crawling: true` capability and
  `crawl: [ '/api/crawl/markdown', '/api/crawl/markdown-batch', '/api/crawl/links' ]`
  endpoints entry.
- Startup log line: `[Design Worker] Crawling: ✅ readability + turndown (chromium fallback: …)`.
- Each crawl logs one structured line (site, url host, tier used, ms, bytes)
  via `requestLogger` conventions — no page content in logs.

### 5.7 Tests

- `src/utils/crawl-extract.test.js` — fixture HTML (article with nav/sidebar
  noise, empty page, non-UTF8 page) → title/markdown/links assertions.
- `src/routes/crawl.test.js` — inject a fake fetch layer and a stub browser
  factory (no real Chromium — CI sets `PUPPETEER_SKIP_DOWNLOAD=true`):
  - SSRF rejection (private IP, obfuscated form, redirect to private host)
  - tier selection (static success → no browser call; thin page → browser
    fallback; `render: never` → no browser ever)
  - batch partial failure returns per-URL errors
  - 429 under crawl limiter; 401 without token
- `npm test` + `npm audit --audit-level=high` must stay green in
  `.github/workflows/ci.yml`.

---

## 6. Phase 2 — Crawl4AI-Compatible Facade (~2–3 days)

Goal: make the media worker a **drop-in Crawl4AI service** for the plugin's
existing remote mode, so "adding Crawl4AI to the media worker" is literally
true from the plugin's perspective — via one `wp-config.php` constant.

### 6.1 Contract mapping (from `WP_MCP_AI_Crawl4AI_Local_API` + service reference)

The plugin remote mode expects the Crawl4AI REST shape:

| Crawl4AI contract | Worker facade |
|---|---|
| `POST /crawl` `{ urls[], extraction_strategy, word_count_threshold, … }` → immediate `task_id` | `POST /api/crawl4ai/crawl` — validate all URLs with `resolvePublicUrl`, enqueue on Redis queue (`getQueue('crawl4ai', req.site)`), return `{ task_id }` immediately |
| `GET /task/{task_id}` → `{ status, results }` | `GET /api/crawl4ai/task/:task_id` — read job state from the queue's status store (same pattern as `/api/workflow/status`); map `completed → results[url]` |
| `NoExtractionStrategy` | Phase 1 markdown pipeline (default) |
| `JsonCssExtractionStrategy` | v1: cheerio CSS-selector extraction for simple `{selector, name}` schemas; unsupported shapes return per-URL error (documented) |
| `LLMExtractionStrategy` | multi-provider structured-JSON extraction (OpenAI, Gemini, Anthropic, DeepSeek, or any OpenAI-compatible `CRAWL_LLM_BASE_URL`); `501` when no provider is configured (implemented v3.2.0 — no longer a Phase 3 dependency) |
| `word_count_threshold` | honored in the Phase 1 tier gate (return empty/skip below threshold) |

Task IDs are opaque strings (same rule as `WP_MCP_AI_Crawler`). Job TTL
`CRAWL_TASK_TTL_MS` (default 30 min) with Redis-backed status; the
in-memory fallback (no `REDIS_URL`) works for single-process deployments,
matching existing queue behavior.

### 6.2 Plugin-side activation (zero code changes)

```php
// wp-config.php
define( 'WP_MCP_AI_CRAWL4AI_BASE_URL', 'http://media-worker:3100/api/crawl4ai' );
```

Everything else — the `run_crawl4ai_job` remote branch,
`WP_MCP_AI_Crawler` WP-Cron polling, the admin Crawl4AI monitor, the chat
Jobs/Tasks drawer (`WP_MCP_AI_Job_Source_Crawl4AI`) — works unchanged
because it already speaks this contract.

> Also document the admin-settings equivalent (Crawl4AI Base URL field) for
> non-Docker deployments, and the fallback behavior when the worker is
> unreachable (plugin remote mode errors → local mode).

### 6.3 Verification

- `addons/media-worker/bin/test-endpoints.sh` gains crawl4ai cases.
- `addons/media-worker/bin/probe-wordpress.php` gains a facade round-trip
  (constant → `/api/crawl4ai/crawl` → poll task → markdown bytes > 0).
- Manual: `docker compose` up → set constant → run `run_crawl4ai_job` from
  an assistant and confirm the job drawer shows completion.

---

## 7. Phase 3 (Optional) — Full Crawl4AI Sibling Container (~1 day)

Only when deep/BFS crawling, session reuse, caching, or exact upstream
Crawl4AI feature parity are required (structured LLM extraction is no
longer a Phase 3 reason — it is native since worker v3.2.0). Follows the
repo's existing bundling recommendation and the Kubernetes sidecar
rationale.

1. **Compose service** next to `media-worker`:

   ```yaml
   crawl4ai:
     image: unclecode/crawl4ai:latest   # or the pinned nvdigital mirror
     ports: ["11235:11235"]
     environment: [...]
     mem_limit: 2g
     cpus: 2
     restart: unless-stopped
   ```

2. **Worker proxy** `POST /api/crawl/full` (+ `/api/crawl/full/:path` GET):
   - enabled only when `CRAWL4AI_FULL_URL` is set (else `503 service_not_configured`);
   - pre-validates all URLs with `resolvePublicUrl` before forwarding
     (defense in depth even though Crawl4AI has its own validation);
   - forwards the site token to the proxy's own auth and strips it from the
     upstream call; adds its own `crawl`-group rate limit.
3. **Routing rule:** `WP_MCP_AI_CRAWL4AI_BASE_URL` then points either at the
   facade (default) or the proxy (`/api/crawl/full`) for full parity.
4. Document the decision matrix: Phase 1/2 covers ~90% of `run_crawl4ai_job`
   usage; Phase 3 is for deep crawls, session reuse, and upstream parity.

### 7.1 Managed Node.js hosts (Cloudways Velocity) — compatibility rules

Velocity is a managed **Node.js-only** stack (Git deploys, NGINX + PM2 —
no Docker, no Python runtime). Phase 3 must therefore never be allowed to
break a Velocity deployment:

1. **Zero Python in the worker.** The proxy route is pure Node (HTTP
   forwarding). The worker never imports, spawns, or bundles Python, and
   the npm dependency set is unchanged — the Velocity build/PM2 process is
   identical with or without Phase 3.
2. **Inert by default.** Without `CRAWL4AI_FULL_URL` the proxy answers
   `503 service_not_configured`. On Velocity the variable is simply never
   set — the compose sibling cannot exist there anyway (Docker-only
   deployments use it).
3. **Full parity on Velocity = external hosting.** Host the Python
   Crawl4AI elsewhere (VPS/container host or a managed scraping API) and
   either set `CRAWL4AI_FULL_URL` on the Velocity worker (worker becomes
   the SSRF-validated, token-gated forwarder) or point
   `WP_MCP_AI_CRAWL4AI_BASE_URL` directly at the remote service in
   WordPress (skips the worker entirely — the plugin's original hybrid
   remote mode).
4. **Velocity Chromium caveat (applies to Phase 1 too).** Velocity images
   may lack Chromium/system libs; the crawl endpoints' static tier works
   fully, the browser-fallback tier surfaces per-URL errors, and
   `render: "never"` is the fully-supported Velocity mode — same graceful
   degradation as the existing `browser_automation` capability flag
   (see `docs/operations/deployment/media-worker-velocity-setup.md` §5).

---

## 8. Explicit Non-Goals

- No changes to the plugin's `run_crawl4ai_job` tool logic, `WP_MCP_AI_Crawler`
  poller, or the local HTTP fallback.
- No Python inside the media-worker container.
- No consolidation of the Pro `web_browser` Playwright service (open question
  §11, candidate for a follow-up proposal).
- No screenshot/PDF changes — `/api/browser/*` stays as-is.

---

## 9. Work Item Checklist

### Phase 1 — Native crawl

- [x] W1.1 `package.json`: add `@mozilla/readability` (^0.6.0), `jsdom` (^26.1.0)
- [x] W1.2 `src/utils/crawl-extract.js` (readability + turndown + link extraction)
- [x] W1.3 `src/routes/crawl.js` (`/markdown`, `/markdown-batch`, `/links`)
- [x] W1.4 `src/middleware/rate-limit.js`: add `crawl` group + `crawlLimiter`
- [x] W1.5 `src/index.js`: wire router, health capabilities/endpoints, startup log
- [ ] W1.6 `.env.example`: `CRAWL_TIMEOUT_MS`, `CRAWL_MAX_BYTES`, `CRAWL_MAX_URLS_BATCH`, `CRAWL_MIN_TEXT_CHARS`, `CRAWL_USER_AGENT`, `CRAWL_TASK_TTL_MS` (file is access-restricted in this worktree — env vars documented in `README.md` and route headers; add when unlocked)
- [x] W1.7 Tests: `crawl-extract.test.js`, `crawl.test.js`; `npm test` + CI green (93/93)
- [x] W1.8 `README.md`: structure tree + endpoint table + Crawling section
- [x] W1.9 Root `CREDITS.md`: add the two packages to the media-worker table

### Phase 2 — Crawl4AI facade

- [x] W2.1 `src/routes/crawl4ai.js` (`POST /crawl`, `GET /task/:task_id`)
- [x] W2.2 Queue wiring via `getQueue('crawl4ai', req.site)` + in-memory TTL-swept status store
- [x] W2.3 `JsonCssExtractionStrategy` basic selector support (cheerio)
- [x] W2.4 `LLMExtractionStrategy` → 501 unless OpenAI key present (basic JSON extraction when configured)
- [x] W2.5 `index.js`: health endpoints entry (`crawl4ai: [...]`) + `web_crawling` capability
- [x] W2.6 Tests: contract mapping, task lifecycle, per-URL partial errors
- [x] W2.7 `bin/test-endpoints.sh` + `bin/probe-wordpress.php` facade round-trip
- [x] W2.8 Docs: `README.md` facade section + plugin activation snippet

### Phase 3 — Optional sibling

- [ ] W3.1 Compose service definition + pinned image + resource limits
- [ ] W3.2 `POST /api/crawl/full` proxy with SSRF pre-validation + `503` gate
- [ ] W3.3 Decision-matrix doc section (when to use which tier)

### Housekeeping

- [x] H1 `docs/project/proposals/RELATED_PROPOSALS_INDEX.md` entry
- [ ] H2 `docs/project/proposals/PROPOSALS_COMPLETION_STATUS.md` (post-merge)
- [ ] H3 Skills touch-up: `design-media-workflow` (endpoint table row),
      `design-web-research` (mention worker facade as `run_crawl4ai_job`
      remote backend option)
- [ ] H4 `docs/operations/deployment/media-worker-docker-setup.md`: add the
      `WP_MCP_AI_CRAWL4AI_BASE_URL` constant to the compose example
- [x] H5 Repo-sync note: media-worker is mirrored one-way to the standalone
      repo — all changes commit in the monorepo (already the rule)

---

## 10. Effort & Timeline

| Phase | Effort | Risk |
|---|---|---|
| 1 — Native crawl endpoints | 2–3 days (1 dev) | Low — additive route + util, inherits hardened infra |
| 2 — Crawl4AI facade | 2–3 days (1 dev) | Low–medium — contract mapping must match plugin poller exactly |
| 3 — Sibling container + proxy | 1 day | Low (ops) |
| Housekeeping/docs | ~0.5 day | — |
| **Total** | **~1–1.5 weeks** | — |

Suggested sequencing: Phase 1 → merge → Phase 2 → merge → Phase 3 behind an
env flag.

---

## 11. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Facade contract drift vs. real Crawl4AI | Pin the contract to `WP_MCP_AI_Crawl4AI_Local_API` (in-repo authority); add a contract test fixture of a real Crawl4AI response |
| jsdom memory on large pages | `CRAWL_MAX_BYTES` cap + abort; browser tier already capped by `PUPPETEER_MAX_CONCURRENT` |
| Crawling etiquette/ToS exposure | Descriptive UA, optional `respect_robots_txt` flag, crawl-group rate limit; docs call out operator responsibility |
| SSRF via redirects | Every redirect hop re-validated with `resolvePublicUrl` (static tier) and request interception (browser tier) |
| Multi-tenant queue pollution | `getQueue('crawl4ai', req.site)` site-scoped keys (existing Phase 1 tenancy pattern) |
| Overlap confusion (three crawl paths) | §4 routing rules documented in `design-media-workflow` skill; single canonical backend per mode |

## 12. Open Questions (for reviewer)

1. Should `respect_robots_txt` default to `true` for the facade (safer) or
   `false` (Crawl4AI parity)? Leaning: `false` default + prominent docs.
2. Is `jsdom` acceptable in the dependency set, or should we implement a
   Cheerio-based extractor to avoid the extra ~4 MB? Leaning: jsdom
   (Readability is the industry-standard quality baseline).
3. Defer Pro `web_browser` service consolidation to a follow-up proposal? 
4. Phase 3 image pinning: official `unclecode/crawl4ai` tag vs. the
   `nvdigital/crawl4ai-service` mirror referenced in the Jan 2026 bundling doc?

## 13. Definition of Done

- [ ] `npm test` and CI (unit, syntax, audit) green
- [ ] Health endpoint reports `web_crawling` and lists the new endpoints
- [ ] A WordPress deployment with only the
      `WP_MCP_AI_CRAWL4AI_BASE_URL` constant changed successfully completes a
      `run_crawl4ai_job` via the worker facade (verified with
      `bin/probe-wordpress.php`)
- [ ] SSRF/rate-limit/auth tests pass for every new endpoint
- [ ] CREDITS, READMEs, `.env.example`, and the skill docs updated
