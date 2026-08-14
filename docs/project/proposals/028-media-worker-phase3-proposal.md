# Proposal: Media Worker Phase 3 — Operate at Scale Without Breaking Anything (v2.5.1 → v3.0.0)

**Based on:** `026-media-worker-multi-tenancy-sidecar-proposal.md` (Phase 1, merged) and `027-media-worker-multi-tenancy-phase2-spec.md` (Phase 2, PR #5868)
**Date:** 2026-08-13
**Status:** Draft — for review
**Target releases:** v2.5.1 (W1–W3), v2.6.0 (W4–W5), v3.0.0 (W6 default flip)
**Standalone repo:** `nvdigitalsolutions/mcp-ai-wpoos-media-worker` (one-way subtree mirror of `addons/media-worker/`)

---

## Executive Summary

Phases 1–2 made the shared worker safe (per-site auth, filesystems, queues, rate limits, provider credentials) and observable (usage counters, temp stats, health matrix). Phase 3 is the **operational maturity layer**: it turns the explicitly deferred items from proposals 026/027 into a prioritized roadmap for running 5–20 sites on one worker — while **never breaking a running deployment**.

The charter is a single hard rule:

> **Every Phase 3 feature is opt-in. Defaults preserve current behavior byte-for-byte. Single-tenant mode never changes. Any default flip ships with a deprecation window and an escape hatch.**

---

## Workstreams

### W1 — WordPress multisite: per-blog worker tokens (v2.5.1, plugin-side)

**Problem:** a multisite network currently shares one `wp_mcp_ai_media_worker_token` per install, so every blog in the network maps to the same worker tenant (or requires `WP_MEDIA_WORKER_TOKEN` constants per blog — awkward).

**Design (additive only):** extend the existing option chain in `trait-wp-mcp-ai-media-worker-client.php`:

```
1. WP_MEDIA_WORKER_TOKEN constant            (unchanged, highest priority)
2. wp_mcp_ai_media_worker_token_<blog_id>    (NEW per-blog option)
3. wp_mcp_ai_media_worker_token              (unchanged network/site option)
```

- Nothing is read unless set — existing installs resolve exactly as today.
- A "default token" admin note explains the fallback chain; no UI changes required for v2.5.1.
- Worker side: zero changes (each blog is just another `SITE_TOKENS` entry with the same slug or its own).

**Tests:** unit tests for the resolution chain with constants/options set and unset.

### W2 — Cost metering integration with the WP cost tracker (v2.5.1)

**Problem:** the worker counts usage (`tenants.usage`), but the WordPress plugin's cost tracker has no visibility into sidecar-provider spend.

**Design (read-only, additive):**
- New WP service: `WP_MCP_AI_Media_Worker_Usage_Reporter` — on a daily cron, `GET /api/health/full` with the site token and feed `tenants.usage[site]` deltas into the existing cost tracker as line items (`provider`, `success`, `provider_error`, `missing_key`).
- Disabled unless `wp_mcp_ai_media_worker_usage_tracking` option is on — zero change for existing installs.
- Worker side: zero changes (counters already exist; the PR just consumes them).

**Tests:** reporter unit test with a mocked sidecar response; option off → no-op test.

### W3 — Env-size resilience: per-site token/key fallback (v2.5.1)

**Problem:** Velocity env-var size limits may cap `SITE_TOKENS`/`SITE_PROVIDER_KEYS` JSON blobs (026 open Q1, unverified estimate 6–12 sites).

**Design (additive):**
- Worker accepts `SITE_TOKEN_<SLUG>` / `SITE_PROVIDER_KEYS_<SLUG>` individual env vars that **merge over** the JSON maps (explicit env wins; JSON still supported).
- Slug normalization identical to Phase 1 (`[a-z0-9-]`, uppercased + hyphens→underscores for the env name).
- Missing both → current behavior (fail-closed per site). No parser changes to existing vars.

**Tests:** merge precedence, slug normalization, JSON+env mixed configuration.

### W4 — Official cluster-mode support: Redis rate-limit store (v2.6.0)

**Problem:** Phase 2d only *warns* about PM2 cluster mode; scale-ups are blocked until rate limits survive multiple instances.

**Design (opt-in, additive):**
- Add `rate-limit-redis` as an **optional dependency** (same pattern as canvas) — install failure never fails the deploy.
- `RATE_LIMIT_REDIS=1` + `REDIS_URL` set → limiters switch to the shared Redis store; otherwise the in-memory store is used exactly as today.
- Boot log states the active store; cluster warning (2d) becomes a confirmation message when the Redis store is active, and an **error-level** warning otherwise (unchanged behavior when not clustered).
- Queue already requires `REDIS_URL` in cluster mode (Phase 1 design) — no change.

**Tests:** store selection logic (pure function, no Redis in unit tests); boot-log branches; `npm ci --dry-run` with the new optional dep.

### W5 — Credential hot-reload via file watch (v2.6.0)

**Problem:** rotating `SITE_PROVIDER_KEYS` on Velocity requires a redeploy (restart).

**Design (opt-in, additive):**
- `PROVIDER_KEYS_FILE=/path/keys.json` — when set, `provider-keys.js` loads credentials from the file instead of `SITE_PROVIDER_KEYS`, watches it (`fs.watch`, 1s debounce), and atomically swaps the parsed map. Malformed updates are rejected with a log error and the previous map stays active (never a broken window).
- No new HTTP surface (no "reload" endpoint — auth surface stays unchanged).
- Rotation runbook: update the file (e.g. via SSH or a deploy hook), verify `/api/health/full`, done. Velocity env var untouched.

**Tests:** file load, atomic swap on change, malformed-update rejection, fallback to env when unset.

### W6 — `STRICT_PDF_PATHS` becomes the default (v3.0.0, the only default flip)

**Problem:** legacy permissive PDF path handling (single-tenant) is the last open cross-tenant surface (026 open Q3).

**Design (gated flip, escape hatch):**
1. **v2.6.0:** boot-time notice when running without strict paths ("default flips in v3.0.0"); document the audit checklist (which WP flows pass `source` paths today).
2. **v3.0.0:** strict paths on by default in **all** modes; `LEGACY_PDF_PATHS=1` restores the old behavior for audited legacy flows, with a loud boot warning.
3. Multi-tenant mode has been strict since Phase 1 — unaffected either way.

**Tests:** flag matrix (default/strict/legacy) across single- and multi-tenant modes.

### W7 — CI/deploy automation & docs (continuous, no behavior change)

- Optional GitHub Action `deploy-media-worker.yml` (manual `workflow_dispatch`) that pushes the subtree-synced `main` to Velocity's deploy remote — off by default until the user adds the deploy URL secret.
- Velocity guide: multi-tenant capacity table (sites per plan tier), W1–W6 runbooks, and a "Phase 3 checklist" mirroring the Phase 1 rollout checklist.

---

## Compatibility Matrix (the non-breaking contract)

| Change | Existing deploys | Single-tenant | Escape hatch |
|---|---|---|---|
| W1 per-blog options | unchanged (options unset) | unchanged | constants still win |
| W2 usage reporting | off by default | unchanged | option off |
| W3 env fallback vars | unchanged (vars unset) | unchanged | n/a (additive) |
| W4 Redis rate-limit store | in-memory store (default) | unchanged | `RATE_LIMIT_REDIS` unset |
| W5 provider key file | env-var path (default) | unchanged | `PROVIDER_KEYS_FILE` unset |
| W6 strict PDF default | v2.6 notice only; v3.0 flip | v3.0 flip | `LEGACY_PDF_PATHS=1` |
| W7 deploy action | not triggered | n/a | `workflow_dispatch` only |

---

## Rollout

| Phase | Scope | Release |
|---|---|---|
| 3a | W1 + W2 + W3 | v2.5.1 |
| 3b | W4 + W5 (+ v3.0 flip notice in W6 step 1) | v2.6.0 |
| 3c | W6 default flip + legacy escape hatch | v3.0.0 |
| 3d | W7 docs/runbooks + capacity table (continuous with the above) | all releases |

Each release is one monorepo PR per workstream (or bundled per the 3a/3b/3c slices above); the subtree sync ships to the standalone repo and Velocity as today.

---

## Open Questions

1. **Cluster officially?** W4 ships the store — confirm whether Velocity plans allow PM2 cluster mode before we recommend it in docs (same question as 027 Q4, now actionable).
2. **Env size ceiling** — validate with Cloudways support before W3 ships, so the docs state a real per-app site ceiling.
3. **Cost tracker schema** — does the existing tracker accept provider-scoped line items, or does W2 need a small schema extension on the WP side?
4. **Who watches the file?** For W5, confirm SSH/deploy-hook access to a writable path on Velocity (the design assumes yes).

---

## References

- `026-media-worker-multi-tenancy-sidecar-proposal.md` — Phase 1 + open questions 1–4
- `027-media-worker-multi-tenancy-phase2-spec.md` — Phase 2 + open question 4 (cluster)
- `docs/operations/deployment/media-worker-velocity-setup.md` — live ops guide
- Source: `addons/media-worker/src/{index.js, middleware/rate-limit.js, utils/provider-keys.js, utils/site-paths.js}`, `includes/traits/trait-wp-mcp-ai-media-worker-client.php`
