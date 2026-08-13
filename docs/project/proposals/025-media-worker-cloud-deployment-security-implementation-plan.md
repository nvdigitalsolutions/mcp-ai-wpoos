# Implementation Plan: Media Worker Cloud Deployment & Security Hardening (Cloudways Velocity)

**Based on:** `docs/project/proposals/media-worker-sidecar-proposal.md` (v2.1.0) + repo-sync work from PR #5856 (2026-08-12)
**Date:** 2026-08-12
**Status:** Draft
**Target releases:** media-worker v2.2.0 (security hardening), v2.3.0 (cloud deployment support)
**Standalone repo:** `nvdigitalsolutions/mcp-ai-wpoos-media-worker` (one-way subtree mirror of `addons/media-worker/`)

---

## Executive Summary

The Media Worker was designed as a Docker sidecar protected by container-network isolation: no authentication, no rate limiting, open CORS, Puppeteer launched with `--no-sandbox`, and arbitrary user-supplied URLs fetched in several routes. None of that is safe on a public URL.

Cloudways Velocity (managed Node.js hosting: Git-based deploys, NGINX + PM2, env-var handling, framework presets) can host the worker, but **the security hardening wave (v2.2.0) must be merged and synced to the standalone repo before the worker is exposed publicly**.

Plan in two waves:

- **Wave 1 — v2.2.0 Security hardening** (12 tasks, ~10 files, ~600 LOC): shared-secret auth (the WP plugin already sends `X-Site-Token`; the worker must verify it), SSRF protection, Puppeteer sandbox hardening, rate limiting, Helmet headers, CORS restriction, per-route request limits, structured logging, split health endpoints, dependency audit, graceful shutdown.
- **Wave 2 — v2.3.0 Cloudways Velocity deployment** (5 tasks, ~6 files): provisioning checklist, capability degradation for missing system binaries (ffmpeg/Chromium), WordPress plugin integration (token + HTTPS URL + connection test), ops runbook (secrets rotation, monitoring, WAF), standalone-repo CI.

**Key insight:** the WordPress side already does half the auth work — `trait-wp-mcp-ai-media-worker-client.php` sends `X-Site-Token` on every request (default: `wp_hash( home_url() )`, overridable via the `wp_mcp_ai_media_worker_token` option). The worker currently **ignores** the header. Completing the loop is low-risk, high-value.

---

## Background & Current State

### What exists today (v2.1.0)

- Node.js 22 Express sidecar, 11 route groups (image, video, social, workflow, pdf, document, ocr, email, code, data, browser), health endpoint at `/api/health`.
- Docker-first deployment (Dockerfile installs ffmpeg, Chromium, cairo/pango, vips).
- **One-way repo sync** to `mcp-ai-wpoos-media-worker` via `sync-media-worker.yml` (force-push on every `main`/`alpha-working` push touching `addons/media-worker/**`, ~20 min/run). Any change must land in the **monorepo** to survive.
- Plugin-side integration: `WP_MCP_AI_Media_Worker_Client` trait sends `X-Site-Token` + `X-Site-Url` headers; admin settings page (Settings → Media Worker) has URL/token config and connection test.

### Security gaps confirmed by code review (2026-08-12)

| # | Gap | Evidence | Severity |
|---|---|---|---|
| G1 | No auth — token header ignored | `src/index.js` mounts all routes with no middleware | **Critical** (public deployment) |
| G2 | Puppeteer `--no-sandbox` + arbitrary URL/HTML | `src/routes/browser.js` L23–27, L51–54 | **Critical** |
| G3 | SSRF surface on URL-accepting routes | `browser.js` `page.goto(url)`, `image.js` uses axios for URL-based fetch | **High** |
| G4 | Open CORS | `app.use(cors())` in `index.js` L22 | Medium (browser-only control, but defense-in-depth) |
| G5 | No rate limiting | none present | **High** (API-credit + resource abuse) |
| G6 | 50 MB global JSON body limit | `express.json({ limit: '50mb' })` L23 | Medium (memory DoS) |
| G7 | Error handler leaks `err.message`; no request logging | `index.js` L133–138 | Low/Medium |
| G8 | Health endpoint leaks full provider/config matrix publicly | `index.js` L40–130 | Low (intel disclosure) |
| G9 | No system-binary detection; capabilities hardcoded `true` | health check L76–106 | Medium (misleading on managed hosts) |
| G10 | No graceful shutdown, no request timeouts | `index.js` | Low/Medium |
| G11 | No `.env.example`, no `engines` field, no CI/audit | repo | Low |

---

## Research Findings — Industry Standards

### 1. API authentication for internal services (source: API key vs OAuth vs JWT comparisons, APIsec microservices security)

- For **service-to-service** calls with no end user, the industry-standard lightweight control is a **shared-secret API key** in a header, over HTTPS only.
- Comparison **must be timing-safe** (`crypto.timingSafeEqual` over hashed, equal-length values) to prevent timing side-channel leaks.
- HMAC-signed requests are the next step up; mTLS is the strongest but operationally heavy (CA + rotation automation). For one WordPress site ↔ one worker, shared secret + rotation runbook is the right size.
- **Never log keys**, keep them in env vars/secrets managers, and rotate on a schedule. The plugin already supports stable tokens via the `wp_mcp_ai_media_worker_token` option — reuse it.

### 2. SSRF prevention (source: OWASP "SSRF Prevention in Node.js")

OWASP's canonical controls, in order of strength:

1. **Allowlist** of outbound destinations (strongest; not practical here because the worker legitimately fetches arbitrary public URLs for screenshots/image-to-image).
2. **Blocklist of private/reserved ranges**: `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, IPv6 `::1/128` + `fc00::/7`.
3. **Resolve-then-verify + pin**: resolve the hostname, validate the IP, then connect to the **pinned** IP (mitigates DNS rebinding, where a hostname resolves safe at check time and internal at connect time).
4. **Re-validate on redirects** — redirect chains can point at internal targets after initial validation.
5. **Normalize obfuscation**: reject decimal (`2130706433`), hex, and octal IP representations of loopback/private addresses.

Applicable Node libraries found: `ssrf-req-filter`, `request-filtering-agent`, `dssrf`. For this worker, a small in-house guard (protocol allowlist + range check + redirect re-validation) keeps the dependency surface small and covers axios + Puppeteer consistently.

### 3. Puppeteer hardening (sources: puppeteer.guide sandbox article, security.stackexchange, Puppeteer troubleshooting docs)

- **"The single most dangerous mistake in a Puppeteer deployment"** is shipping `--no-sandbox` for a service that renders untrusted pages — which is exactly what `browser.js` does today.
- Correct pattern: run Chromium with the **sandbox enabled**, as a **non-root user** (Puppeteer's own docs: "Add user so we don't need --no-sandbox"). In Docker this requires a non-root user + `unprivileged_userns_clone` kernel support; on Ubuntu hosts (Velocity) a dedicated non-root app user satisfies it.
- Keep `--disable-dev-shm-usage` (shared-memory constraint is orthogonal to the sandbox).
- Because the worker renders **attacker-influenced HTML** (`html` body param) and arbitrary URLs, add page-level defense-in-depth: `page.setRequestInterception(true)` and block requests resolving to private ranges (browser-level SSRF), block downloads, disable permissions/plugins.

### 4. Express hardening (source: OWASP Node.js Security Cheat Sheet, RisingStack checklist)

- **Helmet** for secure headers (CSP, X-Content-Type-Options, etc.).
- **Rate limiting** on every endpoint (`express-rate-limit`), with stricter limits on expensive routes (image generation, video, browser).
- **Per-content-type request size limits** — "input with a JSON type is more dangerous than multipart since parsing JSON is blocking": set a smaller global JSON limit and keep 50 MB only on upload routes.
- Validate/sanitize all input at the boundary; set `app.set('trust proxy', ...)` correctly behind NGINX so `X-Forwarded-For` is trustworthy for rate limiting.
- Restrict CORS to explicit origins; keep dependencies updated (`npm audit` in CI).

### 5. Managed platform specifics (sources: Cloudways Velocity launch guide, Velocity overview guide)

- Velocity is **Git-based**: import a GitHub repo, pick Node version, package manager, **entry file**, root directory; NGINX + PM2 preconfigured; env-var handling built in; managed backups + monitoring.
- The standalone repo (`main` branch) plugs straight in: root directory = repo root, entry file = `src/index.js` (or `npm start`).
- **Open question:** whether Velocity allows installing system packages (ffmpeg, Chromium) via SSH/apt. Cloudways servers are Ubuntu with SSH access historically, but Velocity's managed model needs verification with support. Design accordingly (Wave 2, D2): detect binaries at boot, report in health, degrade routes to 503 instead of crashing.

---

## Target Architecture (Post-Implementation)

```
WordPress (PHP)                     Cloudways Velocity (DigitalOcean)
────────────────                    ──────────────────────────────────────
WP_MCP_AI_Media_Worker_Client ──HTTPS──▶ NGINX (terminates TLS,
  X-Site-Token: <shared secret>             rate-limit friendly headers)
  X-Site-Url: home_url()                    │
                                            ▼
                                     PM2 → src/index.js (Node 22)
                                            │ helmet / CORS / auth / rate-limit
                                            ├─ /api/* (11 route groups)
                                            │    ├─ SSRF guard (axios + puppeteer)
                                            │    └─ capability-degraded routes (503)
                                            ├─ /api/health      (public, minimal)
                                            ├─ /api/health/full (auth'd, full matrix)
                                            └─ Redis (optional, REDIS_URL) for queue
```

**Auth contract:** `WORKER_API_TOKEN` env on the worker == `wp_mcp_ai_media_worker_token` option (or `wp_hash(home_url())` default) in WordPress. Rejected requests: `401` with no detail. `/api/health` stays unauthenticated for uptime monitors and only reports `status`, `version`, `uptime`.

---

## Wave 1 — v2.2.0 Security Hardening

> **Gate:** merge + sync to standalone repo **before** exposing the worker on Velocity.

All code changes land in `addons/media-worker/` (monorepo) and flow to the standalone repo via the existing sync workflow. Tasks S1–S6 are the critical path.

### Task S1 — Auth middleware (`src/middleware/auth.js`, `src/index.js`)

**Risk:** Low (additive; existing Docker compose sets no token → lenient default keeps local dev working).
**Test:** 401 without token in strict mode; 200 with valid token; timing-safe compare unit test.

- New `authMiddleware`:
  - Reads `X-Site-Token` (matches what the plugin already sends) and compares against `process.env.WORKER_API_TOKEN` using `crypto.timingSafeEqual` on `sha256` hashes (equal-length requirement).
  - `AUTH_MODE` env: `strict` (default when `WORKER_API_TOKEN` set) → reject missing/mismatched; `lenient` → allow unauthenticated when token not configured (current local-dev behavior), with a startup warning.
- Applied to all `/api/*` routes **except** `GET /api/health`.
- Startup log states auth mode explicitly; never log token values.

```js
// sketch — timing-safe shared-secret check
import { timingSafeEqual, createHash } from 'crypto';
const hash = (v) => createHash('sha256').update(String(v)).digest();
export function authMiddleware(req, res, next) {
  const expected = process.env.WORKER_API_TOKEN;
  if (!expected) return next(); // lenient — token not configured
  const provided = req.get('X-Site-Token') || '';
  const ok = timingSafeEqual(hash(provided), hash(expected));
  if (!ok) return res.status(401).json({ error: 'Unauthorized' });
  next();
}
```

### Task S2 — Security headers + trust proxy (`src/index.js`, `package.json`)

**Risk:** Low. **Deps:** +`helmet`.
**Test:** response includes `Content-Security-Policy`, `X-Content-Type-Options: nosniff`.

- `app.use(helmet())` (drop `cors()` default-open behavior in S4).
- `app.set('trust proxy', 1)` (NGINX in front on Velocity) — required for correct `X-Forwarded-For` handling in rate limiting. Configurable via `TRUST_PROXY` env (default `0` for local Docker to stay safe).
- Add `app.disable('x-powered-by')`.

### Task S3 — Rate limiting (`src/middleware/rate-limit.js`, `src/index.js`)

**Risk:** Low-Medium (tune limits conservatively; the only caller is WordPress).
**Deps:** +`express-rate-limit`.
**Test:** >N requests in window → 429.

- Global limiter: e.g. 300 req / 5 min per IP (WordPress-driven traffic is low).
- Strict per-route limiters on expensive groups: image/generate (e.g. 30 / 10 min), video (20 / 10 min), browser (30 / 10 min), workflow (30 / 10 min) — env-tunable (`RATE_LIMIT_*`).
- 429 responses include `Retry-After`.

### Task S4 — CORS restriction (`src/index.js`)

**Risk:** Low. **Test:** cross-origin browser request without allowlist is blocked by preflight.

- Replace `app.use(cors())` with explicit allowlist from `ALLOWED_ORIGINS` (comma-separated; default: unset = same-origin/no CORS, since the consumer is server-to-server WP, not browsers).

### Task S5 — SSRF guard (`src/utils/safe-url.js`, applied in `image.js`, `video.js`, `browser.js`, `data.js`)

**Risk:** Medium (behavior change: internal URLs now rejected — verify no local-dev flows depend on them; loopback must stay blocked on managed hosts).

**Test:** unit tests for `127.0.0.1`, `localhost`, `10.x`, `172.16–31.x`, `192.168.x`, `169.254.x`, `[::1]`, `fc00::`, decimal `2130706433`, redirect-to-internal.

Implement per OWASP:

- Protocol allowlist: `http:`/`https:` only.
- Reject IP-literal hosts inside private/reserved ranges (v4 + v6) after normalizing obfuscated forms (decimal/hex/octal).
- Resolve hostname → validate every resolved address → **pin** connection to the validated IP where the HTTP client allows (axios: resolve `baseURL` via a custom lookup or use the `lookup` option; Puppeteer: intercept requests and re-validate resolved host).
- Re-validate on redirects (axios `beforeRedirect` hook re-runs the guard; cap redirects at 3).
- Allow env override `SSRF_ALLOW_PRIVATE=1` for local Docker only (never on Velocity).

### Task S6 — Puppeteer hardening (`src/routes/browser.js`)

**Risk:** Medium-High (the biggest behavioral change).
**Test:** screenshot of public URL works; `html` param with inline `<script>` still renders; private-URL navigation is blocked at interception layer; browser closes on timeout.

- Remove `--no-sandbox`/`--disable-setuid-sandbox`; keep `--disable-dev-shm-usage`. Run via the platform's non-root app user (Dockerfile: create non-root user + `unprivileged_userns_clone` note; Velocity: PM2 already runs as app user — verify).
- Keep `PUPPETEER_EXECUTABLE_PATH` support; add `PUPPETEER_ARGS` env override for constrained environments with an explicit warning that `--no-sandbox` must never be set on public deployments.
- Per-page defense: `page.setRequestInterception(true)`; block requests whose resolved host is private (reuse S5 resolver); `page.setDownloadBehavior('deny')`; deny permissions (`page.setPermission` reject-all).
- Concurrency guard: a small in-process semaphore (e.g. max 2 concurrent browsers) + per-request timeout already present (30 s goto) — add hard `page` operation timeout.
- Keep HTML rendering (JS enabled — PDF rendering requires it) but document that arbitrary HTML is executed with the sandbox as the containment boundary.

### Task S7 — Request size + input validation (`src/index.js`, route files)

**Risk:** Medium. **Test:** oversized JSON → 413; malformed body → 400 with field name.

- Global JSON limit lowered to `10mb`; keep 50 MB only on multer upload routes (image, video, pdf).
- Minimal boundary validation per route: required fields, string types, prompt/image length caps; reject unknown top-level keys on generate routes (protects against prototype-pollution-style junk and typos).

### Task S8 — Structured logging + safe errors (`src/middleware/log.js`, `src/index.js`)

**Risk:** Low. **Test:** log line contains request id; error responses contain no stack in `NODE_ENV=production`.

- Request-id middleware (`X-Request-Id` passthrough or generated), method, path, status, duration.
- Redact: never log `authorization`/`x-site-token`/env values.
- Error handler: `err.message` in non-prod, generic `{ error: 'Internal server error' }` + logged stack in prod.

### Task S9 — Health endpoint split + capability detection (`src/index.js`, new `src/utils/capabilities.js`)

**Risk:** Low. **Test:** `/api/health` small; `/api/health/full` requires token.

- `GET /api/health` → `{ status, service, version, uptime }` only (no provider matrix).
- `GET /api/health/full` (auth'd) → existing full matrix + **real** binary detection: `which ffmpeg`, `which chromium`/`chromium-browser`, presence checks for Redis. Replace hardcoded `video_processing: true` / `browser_automation: true` with detected values.

### Task S10 — Dependency hygiene + config artifacts

**Risk:** Low. **Files:** `package.json`, new `.env.example`.

- Add `"engines": { "node": ">=18" }`, `"npm": ">=9"`.
- Add `.env.example` documenting every env var (PORT, WORKER_API_TOKEN, AUTH_MODE, ALLOWED_ORIGINS, REDIS_URL, all provider keys, PUPPETEER_*, SSRF_ALLOW_PRIVATE, NODE_ENV, TRUST_PROXY).
- `npm audit` wired into CI (D5).

### Task S11 — Graceful shutdown + timeouts (`src/index.js`)

**Risk:** Low. **Test:** SIGTERM closes server within 2 s.

- `server.requestTimeout` / `headersTimeout` (e.g. 60 s / 30 s).
- SIGTERM/SIGINT handler: stop accepting connections, close server, exit — PM2-compatible.

### Task S12 — Update sidecar docs (`README.md`, `bin/README.md`)

- Security model section (auth contract, health split, rate limits), env var table pointing to `.env.example`, and a note that hardening applies to both Docker and managed deployments.

### Wave 1 files summary

| File | Change |
|---|---|
| `src/middleware/auth.js` | **New** — timing-safe X-Site-Token verification |
| `src/middleware/rate-limit.js` | **New** — global + per-group limiters |
| `src/middleware/log.js` | **New** — request-id structured logging |
| `src/utils/safe-url.js` | **New** — SSRF guard (validate, pin, redirects) |
| `src/utils/capabilities.js` | **New** — binary detection for health |
| `src/index.js` | Wire middleware, helmet, CORS, trust proxy, limits, timeouts, shutdown, health split |
| `src/routes/browser.js` | Sandbox launch, request interception, semaphore |
| `src/routes/image.js`, `video.js`, `data.js` | SSRF guard on URL inputs |
| `package.json` | +helmet, +express-rate-limit, engines |
| `.env.example` | **New** |
| `Dockerfile` | Non-root user (sandbox-compatible) |
| `README.md`, `bin/README.md` | Security model docs |

---

## Wave 2 — v2.3.0 Cloudways Velocity Deployment

### Task D1 — Provisioning checklist (docs, new `docs/operations/deployment/media-worker-velocity-setup.md`)

1. Connect Git: `nvdigitalsolutions/mcp-ai-wpoos-media-worker`, branch `main`.
2. Settings: Node 22, package manager npm, entry file `src/index.js` (or start command `npm start`), root directory = repo root.
3. Env vars: `NODE_ENV=production`, `PORT` (platform-injected), `WORKER_API_TOKEN` (cryptographically random, ≥32 bytes — generate once, also set in WP), `AUTH_MODE=strict`, `ALLOWED_ORIGINS=<wp site origin>`, provider keys, optional `REDIS_URL`.
4. Verify: `GET /api/health` over the public URL; `/api/health/full` with token; WordPress "Test Connection" button in Settings → Media Worker.
5. Set `WP_MEDIA_WORKER_URL` constant (or admin option) to the **HTTPS** Velocity URL; set `wp_mcp_ai_media_worker_token` option to the same token.

### Task D2 — Capability degradation for managed hosts (`src/utils/capabilities.js`, routes)

**Risk:** Low. **Test:** routes return 503 (not 500/crash) when binaries missing.

- Boot-time detection cached: ffmpeg, chromium. Routes dependent on missing binaries return `503 { error: 'capability_unavailable', capability: 'video' }`.
- Plugin side already cascades: sidecar 503 → local fallback → `WP_Error(501)` (existing service cascade), so WordPress degrades gracefully.
- **Action item:** confirm with Cloudways support whether SSH/apt installs are possible for ffmpeg/Chromium. If yes → document install steps + pin versions. If no → video/browser groups run degraded; image/document/ocr/email/data groups unaffected.

### Task D3 — WordPress plugin integration touch-ups

**Risk:** Low. **Files:** `includes/traits/trait-wp-mcp-ai-media-worker-client.php`, `addons/pro/includes/admin/class-wp-mcp-ai-media-worker-settings.php`.

- Verify `X-Site-Token` + `X-Site-Url` headers are sent on all paths (confirmed present today) — no code change expected; add a constant `WP_MEDIA_WORKER_TOKEN` as an alternative to the option (consistent with `WP_MEDIA_WORKER_URL`).
- Settings page: warn when URL is `http://` and site is HTTPS (mixed content / token over cleartext), and surface `/api/health` capability status including `video`/`browser` availability.

### Task D4 — Ops runbook (docs)

- **Secrets:** rotation procedure (rotate `WORKER_API_TOKEN` on Velocity → update WP option → verify; the two-step window is safe because old/new both valid only briefly if done in order).
- **Monitoring:** uptime check on `/api/health`; alert on 5xx spikes; watch `429` counts for misconfiguration.
- **Optional hardening:** Cloudflare/WAF in front of the app URL with an IP allowlist for the WordPress server's egress IP; Velocity firewall rules.
- **Backups/restore:** rely on Velocity managed backups; the worker is stateless except `REDIS_URL` queue — document queue flush on restore.
- **Sync awareness:** never commit to the standalone repo; changes arrive via monorepo PR → sync (force-push) → Velocity auto-deploy. CI checks in the standalone repo (D5) run on each sync push.

### Task D5 — Standalone-repo CI via synced folder (`.github/workflows/ci.yml` inside `addons/media-worker/`)

**Risk:** Low. **Note:** nested workflows are ignored by GitHub in the monorepo and become repo-root workflows **after the subtree split** — exactly the intent.

- `ci.yml`: `node --test src/**/*.test.js`, `npm audit --audit-level=high`, `node --check` on all sources, runs on push/PR to `main`.
- **Dependency updates:** Dependabot/renovate must target the **monorepo** (`addons/media-worker/package.json`), because any commit merged in the standalone repo is overwritten by the next force-push sync.

### Wave 2 files summary

| File | Change |
|---|---|
| `docs/operations/deployment/media-worker-velocity-setup.md` | **New** — provisioning + runbook |
| `.github/workflows/ci.yml` (inside addon folder) | **New** — post-split CI |
| `src/utils/capabilities.js`, route files | 503 capability degradation |
| `includes/traits/...media-worker-client.php`, pro admin settings | Token constant + HTTPS/token warnings + capability surfacing |
| `README.md` | Velocity section + runbook link |

---

## Rollout Checklist (Ordered)

1. ✅ Standalone repo + sync workflow live (PR #5856 — done).
2. ⬜ Wave 1 merge to monorepo → sync → verify `/api/health` still works in local Docker (lenient mode).
3. ⬜ Provision Velocity app **without** public URL (or keep URL secret during smoke test).
4. ⬜ Configure env, run `bin/test-endpoints.sh` against the app URL with token → all green or documented-degraded.
5. ⬜ Point WordPress at Velocity URL; verify service cascade end-to-end.
6. ⬜ Publish URL; enable monitoring + alerts; rotate token after first 24 h.
7. ⬜ Wave 2 doc/runbook refinements from real-world observations.

## Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Token brute-force if weak/absent | Low (mitigated) | 32-byte random tokens; strict mode default; rate limiting behind it |
| SSRF bypass (DNS rebinding, obfuscated IPs) | Medium | Resolve-then-pin, redirect re-validation, normalization (OWASP controls) |
| Puppeteer sandbox unavailable on Velocity user model | Medium | Non-root app user (Puppeteer's documented pattern); if impossible, **keep the browser group disabled** rather than `--no-sandbox` on a public host |
| ffmpeg/Chromium not installable on Velocity | Medium | Capability degradation + WP-side cascade (503 → local fallback) |
| Rate limits break legitimate WP usage | Medium | Conservative defaults, env-tunable, 429 observability |
| Sync force-push wipes standalone-repo commits | Certain (by design) | All changes via monorepo; documented in both repos |

## Open Questions

1. Does Cloudways Velocity permit SSH/apt installs (ffmpeg, chromium)? → Cloudways support; determines full vs degraded video/browser capability.
2. Does Velocity expose PM2 cluster mode? If yes, set `REDIS_URL` (in-memory queue is per-process).
3. Memory sizing for Puppeteer + sharp + ffmpeg — benchmark on smallest plan, scale up if OOM.

## References

- OWASP Node.js Security Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/Nodejs_Security_Cheat_Sheet.html
- OWASP SSRF Prevention in Node.js — https://owasp.org/www-community/pages/controls/SSRF_Prevention_in_Nodejs
- Puppeteer sandbox guide — https://puppeteer.guide/posts/sandbox/
- Puppeteer troubleshooting (non-root, sandbox, AppArmor) — https://github.com/puppeteer/puppeteer/blob/main/docs/troubleshooting.md
- Securely running Puppeteer in Docker — https://security.stackexchange.com/questions/219577/
- API auth comparison (keys vs JWT vs mTLS for internal services) — https://apiscout.dev/guides/api-authentication-methods-compared
- API security in microservices (APIsec) — https://www.apisec.ai/blog/api-security-in-microservices
- RisingStack Node.js Security Checklist — https://blog.risingstack.com/node-js-security-checklist/
- Cloudways Velocity product — https://www.cloudways.com/en/velocity.php
- Cloudways Velocity launch guide — https://support.cloudways.com/en/articles/15550368
- Cloudways Velocity app overview (Node version, entry file, root dir) — https://support.cloudways.com/en/articles/15550860

## Estimated Effort

| Wave | Tasks | Est. LOC | Est. effort |
|---|---|---|---|
| Wave 1 (v2.2.0) | S1–S12 | ~600 + ~300 test | 2–3 focused days |
| Wave 2 (v2.3.0) | D1–D5 | ~400 (mostly docs) | 1 day + Cloudways support round-trip |
