# Media Worker — Load Testing Kit (Phase 2e)

k6-based load tests and scaling guidance for shared (multi-tenant) workers.
The k6 binary is required on the machine running the tests (not an npm
dependency of the worker).

## Quick start

```bash
# Single site (token from env)
k6 run -e WORKER_URL=https://worker.nvoos.cloud -e WORKER_TOKEN=<token> \
  bin/load-test/worker-load.js

# Two sites — proves per-site rate-limit isolation (site A 429s must not
# affect site B)
k6 run -e WORKER_URL=https://worker.nvoos.cloud \
  -e SITE_A_TOKEN=<tokenA> -e SITE_B_TOKEN=<tokenB> \
  bin/load-test/worker-load.js
```

## What the default script does

| Request | Route | Why |
|---|---|---|
| phone-format | `/api/data/phone-format` | fast pure-JS pipeline health |
| qrcode | `/api/data/qrcode` | CPU-bound native work |
| workflow status | `/api/workflow/status` | scoped state (no cross-site leaks) |
| auth negative | `/api/health/full` + bad token | must 401, never 5xx |

Assertions: `http_req_failed < 1%`, `p(95) < 2000ms`.

## Recommended ramp (per Cloudways Velocity plan)

| Plan tier | Warm-up | Ramp | Soak | Notes |
|---|---|---|---|---|
| Small (1 vCPU / 2 GB) | 5 RPS / 1m | 10 RPS / 2m | 10 RPS / 5m | watch RSS ≤ 1.2 GB |
| Medium (2 vCPU / 4 GB) | 10 RPS / 1m | 25 RPS / 2m | 20 RPS / 10m | add a browser/pdf run |
| Large (4 vCPU / 8 GB) | 25 RPS / 1m | 50 RPS / 2m | 40 RPS / 10m | add video/process uploads |

Heavier scenarios (run manually, not in the default script):

- Browser/PDF: `POST /api/pdf/generate` with small HTML — watch
  `PUPPETEER_MAX_CONCURRENT` and memory; Chromium must be sandboxed.
- Video: `POST /api/video/process` with a small multipart file — 500 MB
  upload limit is the disk-sizing driver.

While running: watch worker logs (`pm2 logs`), `/api/health/full` for
`tenants.usage` / `tenants.temp`, and Velocity's CPU/RAM graphs.

## When to split into per-site workers (decision table)

| Signal | Action |
|---|---|
| One site's `tenants.usage` dominates totals repeatedly | Give that site its own worker (or its own provider keys) |
| RSS pinned near the plan RAM ceiling during normal load | Split, or move video/browser work off this instance |
| Per-site rate-limit 429s for the quiet sites while one site runs hot | Raise `RATE_LIMIT_*_<SITE>` for the quiet sites or split |
| Provider key contention (one site exhausts shared quotas) | Phase 2a per-site keys (`SITE_PROVIDER_KEYS`) |
| Cluster mode considered for scale | Stop — in-memory rate limits and queue are single-process; keep `instances: 1` until a Redis rate-limit store lands (Phase 2d) |

## Disk sizing

`peak ≈ N_sites × (max_concurrent_video × 500 MB + 100 MB scratch)` —
see proposal 027 §4 for the worked examples. Monitor `tenants.temp`
totals/oldest_ms to tune `TEMP_TTL_*`.
