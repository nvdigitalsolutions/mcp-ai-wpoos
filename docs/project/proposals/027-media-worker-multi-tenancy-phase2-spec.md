# Spec: Media Worker Multi-Tenancy Phase 2 — Per-Site Provider Keys, Temp Tuning & Scale Guide (v2.5.0)

**Based on:** `docs/project/proposals/026-media-worker-multi-tenancy-sidecar-proposal.md` (Phase 1, v2.4.0 — implemented in PR #5866)
**Date:** 2026-08-13
**Status:** Draft — for review
**Target release:** media-worker v2.5.0 (per-site provider keys, temp TTL tuning); v2.4.1 optional for the load-testing guide
**Standalone repo:** `nvdigitalsolutions/mcp-ai-wpoos-media-worker` (one-way subtree mirror of `addons/media-worker/`)

---

## Executive Summary

Phase 1 (v2.4.0) delivered per-site **isolation of compute** — auth identity, filesystems, queues, rate limits, logs. Phase 2 closes the remaining shared boundary: **provider credentials**. Today all sites on a shared worker use one pool of env-var API keys (`OPENAI_API_KEY`, `GEMINI_API_KEY`, social tokens, …), which means pooled billing, shared quotas, and one noisy site exhausting everyone's credits.

This spec adds **per-site provider credential resolution** with a documented fallback chain (site key → shared pool → `503 capability_unavailable`), per-site **usage observability**, **tiered temp-file TTLs** with disk-sizing guidance, and a **load-testing & scaling runbook** for N sites on Cloudways Velocity. It also corrects one latent scaling defect found while spec'ing: in PM2 cluster mode, the in-memory rate-limit counters multiply by instance count, silently raising every site's budget.

Sidecar-per-site remains the recommended deployment; this spec makes the shared-worker mode financially and operationally safe for multi-client use.

---

## Scope

### Goals

1. Per-site AI-provider keys (OpenAI, Gemini, Stability, Replicate, Midjourney, Leonardo, Ideogram, GetImg, DeepAI, Firefly, Anthropic) and per-site social credentials (Twitter, Facebook, Instagram, LinkedIn).
2. Deterministic fallback: per-site key → shared pool key → per-provider `503 capability_unavailable` (never a 500 or a silent cross-site charge).
3. Visibility: per-site provider availability matrix in `/api/health/full`; in-memory per-site/provider usage counters; startup warnings for misconfiguration.
4. Temp storage tuning: per-route-group TTLs, upload-vs-scratch retention split, cleanup stats, disk-sizing formula.
5. A repeatable load-test procedure (k6 script + budgets per Velocity plan size) and a cluster-mode decision guide.
6. Zero changes for single-tenant deployments (no `SITE_PROVIDER_KEYS` set ⇒ byte-for-byte current behavior).

### Non-Goals

- Per-site cost metering/billing (the WordPress plugin's cost tracker remains the cost system; the worker only exposes counters).
- Dynamic credential rotation without restart (env changes + PM2 restart are the rotation mechanism; hot reload is out of scope).
- Provider-side quota enforcement (we relay the provider's 429s with `site` tagging — we don't police quotas ourselves).
- Per-site system binaries or CPU quotas (that's sidecar-per-site territory, per 026).

---

## Current State (verified 2026-08-13)

Credential touchpoints in the worker today — all read `process.env` directly, all global:

| Provider | Env var(s) | Used in |
|---|---|---|
| OpenAI (DALL·E + content) | `OPENAI_API_KEY` | `image.js` `generateWithProvider` case 'openai'; content generation |
| Gemini | `GEMINI_API_KEY` | `image.js` (Imagen REST + SDK) |
| Stability | `STABILITY_API_KEY` | `image.js` (stability + clipdrop cases) |
| Replicate | `REPLICATE_API_KEY` | `image.js` (generate + poll) |
| Midjourney | `MIDJOURNEY_API_KEY` | `image.js` (proxy API) |
| Leonardo | `LEONARDO_API_KEY` | `image.js` (generate + poll) |
| Ideogram | `IDEOGRAM_API_KEY` | `image.js` |
| GetImg | `GETIMG_API_KEY` | `image.js` |
| DeepAI | `DEEPAI_API_KEY` | `image.js` |
| Firefly | `FIREFLY_CLIENT_ID`, `FIREFLY_CLIENT_SECRET` | `image.js` (OAuth client-credentials) |
| Anthropic | `ANTHROPIC_API_KEY` | content generation |
| Twitter | `TWITTER_API_KEY`, `TWITTER_API_SECRET`, `TWITTER_ACCESS_TOKEN`, `TWITTER_ACCESS_TOKEN_SECRET` | `social.js` |
| Facebook | `FACEBOOK_PAGE_TOKEN` | `social.js` |
| Instagram | `INSTAGRAM_ACCESS_TOKEN` | `social.js` |
| LinkedIn | `LINKEDIN_TOKEN` | `social.js` |

The only per-site error behavior today is Midjourney's `503` when its key is unset — everything else fails with opaque 500s when unset, and no provider distinguishes sites.

---

## Design

### 1. Credential resolution model

One new env var carries the per-site map (single variable keeps Velocity env limits and the Sensitive toggle manageable — see §3):

```
SITE_PROVIDER_KEYS={"site-a":{"openai":"sk-...","gemini":"AIza...","twitter_access_token":"..."},"site-b":{...}}
```

**Naming (flat, lowercase):** the existing env name lowercased — `OPENAI_API_KEY` → `openai`, `GEMINI_API_KEY` → `gemini`, `FIREFLY_CLIENT_ID` → `firefly_client_id`, `TWITTER_ACCESS_TOKEN_SECRET` → `twitter_access_token_secret`, etc. No nesting; multi-part providers (Firefly, Twitter) use two/four flat entries.

**Resolution order** (`getCredential(site, name)`, new `src/utils/provider-keys.js`):

1. `SITE_PROVIDER_KEYS[site][name]` — per-site value (string, non-empty).
2. Shared pool: `process.env[name.toUpperCase()]` — **documented fallback** so a mixed deployment (some sites with own keys, one on the pool) works during migration.
3. `null` — callers return `503 capability_unavailable` with `{ error, capability: '<name>', site }` and log `provider_key_missing site=<slug> provider=<name>` (no values, ever).

**Boot validation** (`parseSiteProviderKeys()`): malformed JSON or non-string values → console.error once + treat the map as empty (fail closed per provider, never crash boot). Unknown provider names → warn (typo guard). Log the per-site configured-provider counts at startup.

**Pure-function design:** resolver + parser are pure/env-injected (no I/O), fully unit-testable without npm dependencies, matching the Phase 1 test style.

### 2. Route integration

`image.js` `generateWithProvider`: at case entry, resolve the provider's credential(s) via `getCredential(req.site, name)`; when null → 503 as above. Every `process.env.X` reference in the table above becomes a resolver call with `req.site`. Same for `social.js` (per-platform credential sets) and content generation (OpenAI/Anthropic).

`email.js` (Nodemailer) and other non-credentialed routes are unaffected.

**Site-scoped health:** `/api/health/full` gains per-site provider availability:

```
tenants.sites: {
  "site-a": { providers: { openai: true, gemini: false, ... }, social: { twitter: true, ... } },
  ...
}
```

Booleans only — never key values, never partial keys.

### 3. Usage observability

In-memory counters (new `src/utils/usage.js`, or fold into provider-keys.js): `incr(site, provider, outcome)` tracked per (site, provider, success|provider_error|missing_key), exposed as `tenants.usage` in `/api/health/full`. Counters reset on restart (documented). Zero external deps; no PII. This gives operators the signal to split a heavy site into its own worker or its own keys.

### 4. Temp TTL tuning

Phase 1 has one global `TEMP_TTL` (24h). Phase 2 adds:

| Env var | Default | Scope |
|---|---|---|
| `TEMP_TTL` | 86400000 (24h) | default for scratch |
| `TEMP_TTL_UPLOAD` | 604800000 (7d) | user uploads (video/PDF inputs) — often needed for retries |
| `TEMP_TTL_VIDEO` | 3600000 (1h) | processed video outputs (already self-clean in ~60s) |
| `TEMP_TTL_BROWSER` | 3600000 (1h) | screenshots/PDFs |
| `TEMP_TTL_DOC` | 86400000 (24h) | excel/word/data outputs |

Implementation: subdirectories per group under `TEMP_ROOT/sites/<slug>/<group>/`, cleanup walks groups with their own TTLs; single-tenant mode untouched (OS owns `os.tmpdir()`). Health gains `tenants.temp: { files, bytes, oldest_ms, per_site: {...} }` and cleanup logs include group breakdown.

**Disk sizing formula** (Velocity block storage): `peak ≈ N_sites × (max_concurrent_video × 500 MB + scratch ≈ 100 MB)`. Example: 5 sites, 2 concurrent video uploads each ⇒ ~5.2 GB comfortable; 10 sites ⇒ ~10.5 GB. Include headroom +1 GB for tesseract/Chromium caches.

### 5. Cluster-mode corrections (scaling defect)

- **Rate limits:** `express-rate-limit` default store is per-process memory. PM2 cluster mode (N instances) multiplies every site's effective budget by N. Fix: document `REDIS_URL`-backed `rate-limit-redis` store when clustering (or keep cluster mode out of the supported matrix and require `instances: 1`; recommendation: single instance until queues need cluster, then add the Redis store in the same change).
- **Queue:** in-memory fallback is single-process by design (Phase 1); cluster mode **requires** `REDIS_URL` — make this a boot-time hard warning, not a doc footnote.
- **SSE/streaming:** none today; if added later, sticky sessions via Velocity would be required — noted as a constraint, not in scope.

### 6. Load-testing guide (k6)

Ship `bin/load-test/` with a k6 script + README (not a runtime dependency):

- Scenarios per Velocity plan tier (S/M/L by vCPU) with target RPS: image optimize (CPU), qrcode + markdown (fast), pdf extract 2MB (memory), workflow status (queue), and an auth-negative case (expect 401).
- Per-site tokens read from env (`K6_SITE_A_TOKEN`), so per-site rate-limit isolation is exercised for real (site A should 429 while site B passes).
- Assertions: p95 < 2s on fast routes; no 5xx; error rate < 1%; memory RSS ceiling per plan (watch PM2 logs).
- Concurrency caps honored: `PUPPETEER_MAX_CONCURRENT` and browserLimiter sized per plan (document the mapping).
- Runbook: warm-up, ramp, soak, and a "when to split into per-site workers" decision table (CPU sat, memory pressure, provider-key contention, per-site quota disputes).

---

## Secret Management & Ops

- `SITE_PROVIDER_KEYS` is a **single** Velocity env var (Sensitive toggle) — avoids per-key env sprawl and fits platform limits; estimate ~1 KB for 5 sites × 5 providers.
- Rotation: update the JSON (or individual provider) → PM2 restart. No downtime-free hot swap in v2.5.0; note that per-site keys rotate independently of site tokens.
- WordPress vault integration (the plugin's encrypted vault) can store the site's provider keys for the plugin's own use; the worker only ever sees its own env.
- Never log key values, prefixes, or lengths (`sk-…` included) — the logger already omits headers; add a unit test asserting provider values never appear in `console` output during a failed-auth flow.

---

## Compatibility & Migration

| Scenario | Behavior |
|---|---|
| No `SITE_PROVIDER_KEYS` | current pooled behavior, unchanged |
| Mixed (some sites have keys) | per-site keys win; pool fallback only where a site has no entry |
| Malformed JSON | warn at boot, empty map, per-provider 503s |
| Provider key revoked | provider 4xx surfaces with `site=<slug>` log tag; counter records `provider_error` |
| Single-tenant + `SITE_PROVIDER_KEYS` set | ignored (single-tenant mode), boot warning |

---

## Testing Plan

Unit (built-in runner, no external services):

- `provider-keys`: parse (valid/malformed/non-string), fallback chain, flat naming (incl. `firefly_client_id`, `twitter_access_token_secret`), unknown-name warnings, null resolution.
- Route-level: image generate per provider returns `503 capability_unavailable` with `site` when unresolved; pooled fallback still works; social post per-platform.
- `usage`: counter increments + health JSON shape.
- Temp groups: TTL selection per group, upload group longer than scratch.
- Logger regression: no key material in output.

Integration (manual): two sites, site A with OpenAI key, site B pooled → both generate; revoke pool → B gets 503, A unaffected.

---

## Rollout

| Phase | Scope | Release |
|---|---|---|
| 2a | `provider-keys.js` + route integration (image/social/content) + health matrix + boot warnings + unit tests | v2.5.0 |
| 2b | Usage counters + logging tags | v2.5.0 |
| 2c | Grouped temp TTLs + temp stats + disk-sizing docs | v2.5.0 |
| 2d | Cluster-mode corrections (Redis rate-limit store option, boot warnings) | v2.5.1 |
| 2e | k6 load-test kit + scaling decision table + Velocity docs update | v2.5.1 |

Each phase is an independent monorepo PR; the subtree sync ships them to the standalone repo and Velocity.

---

## Open Questions

1. **Velocity env size limit** — confirm the ceiling for a single env var; if < ~4 KB, split `SITE_PROVIDER_KEYS` per site (`SITE_PROVIDER_KEYS_SITE-A=...`).
2. **Social keys day one?** Twitter's 4-part credential per site is the largest entry; acceptable to ship AI providers first and social in 2b?
3. **Pooled fallback or fail-closed by default?** Spec says fallback for migration; some operators will want `PROVIDER_KEYS_STRICT=1` to forbid pooling entirely. Include the flag?
4. **Cluster support officially?** If Velocity plans allow multi-instance PM2, 2d becomes a must-have; if not, mark cluster unsupported and skip the Redis rate-limit store.

---

## References

- `026-media-worker-multi-tenancy-sidecar-proposal.md` — Phase 1 design & threat model
- `media-worker-sidecar-proposal.md` — service cascade & WP integration
- `docs/operations/deployment/media-worker-velocity-setup.md` — deployment & shared-worker ops
- Source: `addons/media-worker/src/routes/{image,social}.js` (credential touchpoints), `src/middleware/rate-limit.js`, `src/queue.js`, `src/utils/site-paths.js`
