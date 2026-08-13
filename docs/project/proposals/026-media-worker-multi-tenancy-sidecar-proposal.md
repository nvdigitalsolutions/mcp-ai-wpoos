# Proposal: Media Worker Multi-Tenancy — Shared Worker Mode with Site Isolation (v2.4.0)

**Based on:** `docs/project/proposals/media-worker-sidecar-proposal.md` (v2.1.0), `docs/project/proposals/025-media-worker-cloud-deployment-security-implementation-plan.md` (v2.2.0/v2.3.0)
**Date:** 2026-08-13
**Status:** Approved — Phase 1 implemented 2026-08-13 (branch `feat/media-worker-multi-tenant`, v2.4.0)
**Target release:** media-worker v2.4.0
**Standalone repo:** `nvdigitalsolutions/mcp-ai-wpoos-media-worker` (one-way subtree mirror of `addons/media-worker/`)
**Related PRs:** #5863 (Cloudways hardening, merged), #5864 (`WORKER_API_TOKEN_PREVIOUS` rotation, open)

---

## Executive Summary

The Media Worker is deployed as a **per-site sidecar**: one worker instance paired 1:1 with one WordPress site. That model stays the **default and recommended** deployment. However, several users run networks of WordPress sites (agencies, multisite families, low-traffic client sites) and want a **single shared worker** on Cloudways Velocity to cut cost and operations overhead.

Today a shared worker *works mechanically* — every site configures the same `WP_MEDIA_WORKER_URL` + `WP_MEDIA_WORKER_TOKEN` — but the worker is **single-tenant by design**. Sites sharing one instance currently share:

1. One authentication secret (no per-site identity),
2. One filesystem scratch space (`/tmp`, and PDF routes accept **arbitrary worker filesystem paths** — any authenticated client can read/write files belonging to another site),
3. Fixed Redis queue keys (jobs from all sites interleave; any site can poll any job status),
4. One set of provider API keys (pooled billing/quotas — one busy site exhausts shared credits),
5. Global rate-limit budgets (one noisy site can throttle the others).

This proposal adds an **opt-in multi-tenant mode** to the worker that preserves the 1:1 sidecar behavior (zero changes for existing deployments) while giving shared deployments real isolation: per-site tokens, site identity on every request, site-scoped filesystems and queues, per-site rate limits, and a hardened PDF file path surface. The WordPress plugin needs **no changes** — it already sends `X-Site-Token` and `X-Site-Url` on every sidecar request (`includes/traits/trait-wp-mcp-ai-media-worker-client.php` L87–91), and per-site tokens are already supported via the `wp_mcp_ai_media_worker_token` option / `WP_MEDIA_WORKER_TOKEN` constant.

---

## Background & Current State

### Deployment modes in use

| Mode | Topology | Token config | Status |
|---|---|---|---|
| Docker sidecar (default) | 1 worker container per WP site, container-network isolation | `WORKER_API_TOKEN` optional (lenient) | ✅ v2.1.0+ |
| Cloudways Velocity per site | 1 Velocity app per WP site | `WORKER_API_TOKEN` + `AUTH_MODE=strict` | ✅ v2.2.0+, see `media-worker-velocity-setup.md` |
| Shared worker (this proposal) | N WordPress sites → 1 worker | **Not supported safely today** | 🔧 v2.4.0 target |

### What the code does today (verified 2026-08-13)

- **Auth** (`src/middleware/auth.js`): single `WORKER_API_TOKEN` (+ `WORKER_API_TOKEN_PREVIOUS` after #5864), timing-safe SHA-256 comparison. No concept of *which site* a request belongs to.
- **Rate limiting** (`src/middleware/rate-limit.js`): global + per-route-group limiters via `express-rate-limit`, keyed by client IP (behind `TRUST_PROXY=1` NGINX this is the WP server's IP). Env-tunable (`RATE_LIMIT_*`). No per-site dimension.
- **Filesystem**: multer uploads land in shared locations (`/tmp` for video, memory for image); `browser.js`/`data.js`/`document.js` write scratch files to `os.tmpdir()`; `pdf.js` `/extract` and `/render` accept a `source` path and (for `/render`) an `outputDir` — **arbitrary worker filesystem paths** with only an `fs.existsSync()` check.
- **Job queue** (`src/queue.js`): Redis keys are fixed per queue name (`queue:image-generation`, `queue:delayed`, …) — shared across all callers; no tenant dimension.
- **Workflow** (`src/routes/workflow.js`): status lookups are by job ID only — any authenticated client can query any job.
- **Providers**: all provider/social keys are process-global env vars — one shared pool.
- **WordPress plugin** (`includes/traits/trait-wp-mcp-ai-media-worker-client.php`): already sends `X-Site-Token` and `X-Site-Url` (`home_url()`) headers on every request; per-site token option exists; Settings → Media Worker has connection test. **No plugin changes required for this feature.**

### Gap discovered during tenancy analysis

The PDF routes (`/api/pdf/extract`, `/api/pdf/render`) expect the source file to **already exist on the worker's filesystem**. In the Docker sidecar model this is satisfied via a shared volume, but on Cloudways Velocity (and in any shared-worker deployment) the WordPress server has **no way to place files on the worker's disk** — there is no PDF upload route. This gap exists independent of multi-tenancy and is addressed as part of this proposal (Phase 1 adds multipart upload support to the PDF routes).

---

## Goals

1. Keep **sidecar-per-site as the recommended default**; existing 1:1 deployments must see zero behavioral changes.
2. Add an opt-in **multi-tenant mode** (`SITE_TOKENS` env var) where each site has:
   - its own token (site identity derived at the auth gate),
   - a scoped filesystem namespace (uploads + scratch),
   - scoped queue keys and workflow status,
   - its own rate-limit budget,
   - audit logs tagged with the site slug.
3. Remove the cross-tenant filesystem surface: PDF `source`/`outputDir` become site-scoped, and PDF routes gain multipart upload so files can actually arrive from WordPress on managed hosts.
4. Make the isolation **fail-closed**: a request that cannot be attributed to a known site in multi-tenant mode is rejected.

## Non-Goals (v2.4.0)

- Per-site provider API keys (pooled billing) — documented as a known boundary; deferred to a later phase (§7, Phase 2).
- Per-site system binaries or CPU/memory quotas (process-level isolation is what sidecar-per-site is for).
- A management UI; configuration stays env-var based (Velocity dashboard-friendly).
- mTLS or per-site HMAC signing; shared-secret tokens over HTTPS remain the model, with rotation support from #5864.
- Multi-tenancy for the standalone browser (Puppeteer) concurrency cap (remains global).

---

## Threat Model — Shared Worker (N sites, 1 instance)

| # | Threat | Today | After v2.4.0 |
|---|---|---|---|
| T1 | Site B guesses/reuses Site A's token | No site concept; token = full access | Per-site tokens; site identity pinned per request |
| T2 | Any site reads/writes files of another site via PDF `source`/`outputDir` | **Open** — arbitrary worker FS paths | Paths resolved and prefix-checked inside the site's namespace |
| T3 | Site A's jobs processed/stolen via shared queue keys | Open — shared `queue:*` keys | Keys namespaced per site; status endpoints scoped |
| T4 | One noisy site exhausts shared rate-limit budget | Open — shared budgets | Per-site budgets (keyed `site:<slug>`) |
| T5 | Token leak on one site = full cross-tenant access | Full worker access | Blast radius limited to that site's namespace + shared providers |
| T6 | Shared provider keys — pooled billing/quota abuse | Inherent | Documented; per-site key map deferred (Phase 2) |
| T7 | Site spoofing via `X-Site-Url` header | Header ignored today | Header never trusted for auth; used for audit logging only |
| T8 | Temp-file collision / leftover cleanup | Shared `os.tmpdir()` | Per-site dirs with TTL cleanup |

---

## Design Overview

```
                        ┌──────────────────────────────────────────┐
 WordPress site A ─────▶│  Media Worker (shared, multi-tenant)     │
   X-Site-Token: tok-A │                                          │
   X-Site-Url: a.com   │  auth.js ── token → site slug (req.site) │
 WordPress site B ─────▶│     │                                    │
   X-Site-Token: tok-B │     ▼                                    │
   X-Site-Url: b.com   │  per-site: temp dirs, queues, rate limit │
                       │  PDF: upload + site-scoped paths         │
                       │  logs: site=<slug> on every line         │
                       └──────────────────────────────────────────┘
```

### Modes

- **Single-tenant (default, unchanged):** `WORKER_API_TOKEN` set, `SITE_TOKENS` unset. Exactly today's behavior; `req.site = 'default'` internally, no namespacing (avoids breaking Docker shared-volume path expectations).
- **Multi-tenant (opt-in):** `SITE_TOKENS` set (and `AUTH_MODE=strict`). Every request must carry a token mapping to a known site; namespacing active.

### Configuration

```
# Multi-tenant mode — JSON map: site slug → token
SITE_TOKENS={"site-a":"<tokenA>","site-b":"<tokenB>"}

# Per-site rotation overlap (optional): slug → previous token
SITE_TOKENS_PREVIOUS={"site-a":"<oldTokenA>"}

# Existing knobs remain valid
WORKER_API_TOKEN / WORKER_API_TOKEN_PREVIOUS   # single-tenant mode only
AUTH_MODE=strict                               # required in multi-tenant mode
TEMP_ROOT=/tmp/mw                              # default /tmp/mw
```

Velocity constraint: env var length limits may cap the number of sites per app (JSON string). If a deployment outgrows that, it's the signal to split into per-site apps. Estimate 6–12 sites per Velocity app depending on plan env limits — to be validated during implementation.

### Site slug rules

- `[a-z0-9-]{1,32}`, derived by the operator from the site's domain (e.g. `client-a`).
- Used only for namespacing/logging; never parsed from headers.
- `X-Site-Url` is recorded in audit logs for observability and **mismatch warnings** (site sends a different `home_url()` than last seen), but is never an auth input.

---

## Detailed Design

### 1. Auth middleware — site identity (`src/middleware/auth.js`)

```js
// resolveSite(token) → { slug } | null
//  - SITE_TOKENS set:     lookup token in the JSON map (timing-safe compare against
//                         each entry's digest; entry count is small by design)
//  - single-tenant mode:  token === WORKER_API_TOKEN → slug 'default'
//  - SITE_TOKENS_PREVIOUS checked during rotation windows, mirrors #5864 semantics
// req.site = slug; req.siteUrl = req.get('X-Site-Url')  // audit only
```

- Fail-closed: in multi-tenant mode with no match → 401 (no lenient fallback; `AUTH_MODE=strict` enforced).
- Log one line per auth with `site=<slug>`; warn once per slug when `X-Site-Url` changes (detects a stolen token pointed at a different domain).
- `/api/health` stays public and minimal (no site list). `/api/health/full` adds `tenants: { count, slugs }` (slugs only — never tokens) and per-slug capability summary.

### 2. Filesystem namespacing (`src/utils/site-paths.js`, new)

```js
siteTempDir(slug)   → path.resolve(TEMP_ROOT, 'sites', sanitize(slug))   // created lazily
siteUploadDir(slug) → same namespace under 'uploads'
```

- `browser.js`, `data.js`, `document.js`, `video.js`, `image.js` scratch files move from `os.tmpdir()` to `siteTempDir(req.site)`.
- In single-tenant mode, `TEMP_ROOT` defaults to `os.tmpdir()` so existing Docker volume behavior is byte-for-byte unchanged.
- TTL cleanup: on boot and every 15 min, delete files older than `TEMP_TTL` (default 24h) inside the temp tree (idempotent, log-only on failure).

### 3. PDF routes — upload support + path sandbox (`src/routes/pdf.js`)

Phase 1 changes (also fixes the standalone Velocity gap):

- Add multipart upload to `/api/pdf/extract` and `/api/pdf/render` (`upload.single('file')`, 50 MB limit): the uploaded file is written into the site's namespace and used as `source`. This makes PDF features work on managed hosts where WP cannot reach the worker's disk.
- When a `source` **path** is supplied (legacy Docker flow), resolve it and require it to be inside `siteUploadDir(req.site)` (or the site temp dir). Reject with `403 path_not_allowed` otherwise.
- `outputDir` likewise confined to the site namespace; result paths returned to callers become **site-relative** (worker-side callers join them with their namespace root).
- Legacy single-tenant mode keeps the current permissive behavior (documented as such) to avoid breaking existing Docker deployments; a `STRICT_PDF_PATHS=1` env can force sandboxing there too.

### 4. Queue namespacing (`src/queue.js`)

- `queueKey = queue:<site>:<name>` and delayed set `queue:delayed:<site>` when multi-tenant; unchanged keys in single-tenant mode.
- `getQueue(name, site)` / `getQueue(req)` — the workflow router passes `req.site`.
- Workflow status endpoint (`/api/workflow/status`) verifies the job ID belongs to the requesting site (site-scoped job registry or key prefix check) — returns 404 for foreign jobs.

### 5. Per-site rate limits (`src/middleware/rate-limit.js`)

- `keyGenerator`: multi-tenant → `site:<slug>:<ip>`; single-tenant → IP (current behavior).
- Optional per-site overrides, e.g. `RATE_LIMIT_IMAGE_site-a=60` (slug uppercased, hyphens→underscores); falls back to the global env budget.

### 6. Logging & audit (`src/middleware/log.js`)

- Every structured log line gains `site=<slug>` and `site_url` (redacted to hostname).
- Never log tokens (already enforced); add a CI test asserting token values are absent from log output.

### 7. Provider keys (Phase 2 — future, explicitly out of scope)

- Today: one env-var pool shared by all sites (pooled billing). Documented on the Velocity guide.
- Future: `OPENAI_API_KEY_<SLUG>` style lookup in provider routes; sites without a key get `503 capability_unavailable` for that provider. Deferred because it multiplies secret management without changing the security model.

---

## Plugin-Side Changes

**None required.** Verification checklist for the implementation phase:

- [ ] `WP_MEDIA_WORKER_TOKEN` / `wp_mcp_ai_media_worker_token` already per-site — confirm Settings → Media Worker surfaces it per site in multisite.
- [ ] `X-Site-Url` already sent — confirm it is the canonical `home_url()` (it is, L89 of the trait).
- [ ] Connection test still passes against a multi-tenant worker (it calls `/api/health/full` with the site token — must return 200 for a valid tenant).
- [ ] Docs update: `media-worker-velocity-setup.md` gains a "Shared worker (multi-tenant)" section with `SITE_TOKENS` config + boundary warnings (pooled keys, per-site limits, when to split).

---

## Compatibility & Migration

| Scenario | Impact |
|---|---|
| Existing single-tenant Docker/Velocity deploys | None — `SITE_TOKENS` unset ⇒ all namespacing and strict path checks disabled by default |
| New shared worker | Set `SITE_TOKENS` + `AUTH_MODE=strict`; each site keeps its own token (already supported per-site on the WP side) |
| Rotation | Per-site via `SITE_TOKENS` + `SITE_TOKENS_PREVIOUS` (extends #5864) |
| Version skew | Worker v2.4.0 with older plugin: fully compatible (headers unchanged) |

---

## Testing Plan

Unit (Node built-in test runner, no external services — matches existing CI):

- Auth: token→slug resolution, unknown token 401, lenient disabled in multi-tenant mode, rotation overlap, `X-Site-Url` change warning (once).
- Site paths: sanitization rejects `..` traversal and non-`[a-z0-9-]` slugs; single-tenant falls back to `os.tmpdir()`.
- PDF sandbox: in-namespace `source` allowed; `../../etc/passwd`, absolute paths outside namespace, and symlink escapes rejected; upload path writes into site namespace; `STRICT_PDF_PATHS=1` honored in single-tenant mode.
- Queue: keys differ per site; status endpoint returns 404 for foreign job IDs.
- Rate limit: separate counters per site (two sites, one exhausts, other still passes).
- Logs: `site=` present; token strings absent.

Integration (manual, documented in runbook):

- Two WP test sites → one worker: parallel image optimize + video process + PDF upload; verify no file/queue crossover; verify `/api/health/full` tenant summary.

---

## Rollout Plan

| Phase | Scope | Release |
|---|---|---|
| 0 | This proposal review | — |
| 1 | Auth identity, site-paths, queue namespacing, per-site rate limits, logging, PDF upload + sandbox, unit tests, docs | v2.4.0 |
| 2 | Per-site provider key map, temp TTL tuning, load-testing guide — spec'd in `027-media-worker-multi-tenancy-phase2-spec.md` | v2.5.0 |

Ship path: monorepo PR → `alpha-working` → subtree sync → standalone repo CI → Velocity redeploy.

---

## Open Questions

1. **Velocity env var size limit** — how many sites fit in one `SITE_TOKENS` JSON string? (Validate during Phase 1; fallback: per-site env names like `SITE_TOKEN_site-a` if JSON proves limiting.)
2. **Multisite vs. separate installs** — should `wp_mcp_ai_media_worker_token` support per-blog values in WordPress multisite natively, or is per-install config sufficient? (Plugin team input.)
3. **PDF legacy path behavior** — is any existing production flow relying on cross-directory `source` paths that the sandbox would break? (Audit WP service classes before enforcing `STRICT_PDF_PATHS` by default in v2.5.0.)
4. **Quota fairness for providers** — acceptable to keep pooled keys with per-site rate limits only, or is per-site key mapping needed before any shared deployment goes live?

---

## References

- `media-worker-sidecar-proposal.md` — sidecar architecture & service cascade
- `025-media-worker-cloud-deployment-security-implementation-plan.md` — v2.2.0 hardening, v2.3.0 Velocity
- `docs/operations/deployment/media-worker-velocity-setup.md` — deployment & rotation runbook
- PR #5863 — Cloudways hardening (merged); PR #5864 — token rotation (open)
- Worker source: `addons/media-worker/src/{index,middleware/auth,middleware/rate-limit,queue}.js`, `src/routes/{pdf,video,image,browser,data,document,workflow}.js`
- Plugin client: `includes/traits/trait-wp-mcp-ai-media-worker-client.php`
