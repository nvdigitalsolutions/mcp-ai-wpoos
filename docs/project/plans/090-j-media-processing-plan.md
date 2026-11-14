# Proposal 090-j — Media Processing on the Laravel Runtime

**Status:** Slice 1 shipped 2026-11-14 (`GET /api/v1/pro/media-cc/health`, config-gated).
**Parent:** [`090-i-remaining-surfaces-strategy.md`](../proposals/090-i-remaining-surfaces-strategy.md) §9.6.
**Related:** 090-b (admin surfaces), 090-f (storage bridge), `addons/media-worker`.

---

## 1. Decision

The Laravel runtime **reuses the existing media worker** (`addons/media-worker`) instead of growing a PHP image pipeline. The sidecar already does Sharp-based optimisation, enhancement and format conversion, and the WordPress plugin already speaks to it. A second implementation would drift and would re-solve a solved problem.

The worker is configured with:

| Env | Purpose |
|---|---|
| `NVOOS_MEDIA_WORKER_URL` | Base URL. Empty = the surface is unavailable. |
| `NVOOS_MEDIA_WORKER_TOKEN` | Sent as `X-Site-Token`; single-tenant (`WORKER_API_TOKEN`) or multi-tenant (`SITE_TOKENS`). |
| `NVOOS_MEDIA_WORKER_TIMEOUT` | Health-probe timeout, default 3s. |

Contract used (identical to `WP_MCP_AI_Media_Worker_Client`):

| Endpoint | Shape |
|---|---|
| `GET /api/health` | `{ status: 'ok', service, version, uptime, memory }` — public, no auth |
| `POST /api/image/optimize` | multipart `file` + `format`, `quality`, `width` → `{ savings_bytes, savings_percent, optimized_size, b64, … }` |

## 2. Shipped — slice 1 (read-only snapshot)

`GET /api/v1/pro/media-cc/health` (admin only), in the shape `addons/pro/assets/spa-v2/src/api/media-ops.ts` parses.

| Section | Source | Honesty rule |
|---|---|---|
| `attachments.total`, `image_count` | `nvoos_files` | real |
| `storage` | `sum(size_bytes)` | real |
| `nextgen_pct` | webp/avif ÷ images | real |
| `attachments.with_alt`, `descriptive_filename_count`, `alt_pct` | — | **`null`** (no alt column) |
| `compression` | — | **`null`** (no original-size data) |
| `queue` | `nvoos_jobs` where `handler LIKE 'media.%'` | real; absent status = true zero |
| `schedule` | — | zeros; no media scheduler yet |
| `capabilities.sweep` | — | `false`; the console hides the action |
| `worker` | `GET /api/health` | `{configured, available, version, uptime}`; down = reported, not thrown |

**Availability is config-gated**, not route-gated: `EndpointCapabilities::CONFIG_GATED` marks `mediaCc` unavailable until `oos.media_worker.url` is set. The reason stays `connected-service`, so an unconfigured worker is not a manifest gap.

**Console:** `formatBytes` / `formatPercent` / `formatCount` render `—` for `null`; the nav hides items whose endpoint is blank (`NavGate.endpoint`).

## 3. Slice 2 — alt text (S)

1. Migration: `alt_text` (string 500, nullable) on `nvoos_files`; index nothing (not queried alone).
2. `POST /api/v1/media` accepts `alt_text`; add `PATCH /api/v1/media/{id}` for edits (same `upload_files` gate).
3. Snapshot: `attachments.with_alt`, `alt_pct` become real; `descriptive_filename_count` becomes a documented heuristic (not `IMG_\d+`, not a hash, ≥ 3 words or ≥ 12 chars).
4. Acceptance: uploading with `alt_text` moves `alt_pct`; a library of 2 images with 1 alt reports `50`.

## 4. Slice 3 — compression sweep (M)

This is the slice the user actually needs: processing, not reporting.

1. Migration: `original_size_bytes` and `optimized_size_bytes` (nullable) on `nvoos_files`, or a `nvoos_media_derivatives` row per (file, format). Prefer the derivative table: it makes `nextgen_pct` and per-format savings answerable.
2. Job `App\Jobs\OptimizeMediaFile` on the queue:
   - reads the file from its disk, posts multipart to `POST /api/image/optimize` (`format=webp`, `quality=80`),
   - writes the derivative, records `savings_bytes`,
   - writes `JobStatusStore` rows under handler `media.optimize` so the snapshot's `queue` is real.
3. `POST /api/v1/pro/media-cc/compression-sweep`:
   - **dedupe**: refuse a second sweep while one is `queued`/`running` (mirrors WordPress's `as_has_scheduled_action`),
   - queues one job per unrestored image, chunked (default 50 per dispatch) to keep the queue visible.
4. Snapshot: `compression` = `sum(savings_bytes)`; `capabilities.sweep` becomes `true`; the console action appears automatically.
5. Acceptance: a sweep of 3 images queues 3 `media.optimize` jobs, the page shows `pending: 3`, and `compression` increases by the reported savings.

## 5. Slice 4 — schedule summary (S, optional)

`active` / `ran_today` / `overdue` / `next_run` from the code-defined schedule plus `nvoos_jobs` timestamps. Only worth doing once a recurring media job exists (e.g. a nightly sweep). Until then zeros are truthful.

## 6. Security and operations

- **The worker URL is operator configuration**, not user input — no SSRF surface beyond what the operator sets. Never accept a URL from a request.
- **Token handling**: reuse the worker's timing-safe comparison; the token is a secret, never logged, and rotation is supported by `WORKER_API_TOKEN_PREVIOUS` / `SITE_TOKENS_PREVIOUS`.
- **Timeouts**: health 3s; per-file optimisation 60s with a queue-level retry (3 attempts, backoff), because a Sharp crash must not poison the sweep.
- **Payload size**: optimisation posts the file as multipart; the worker caps uploads at 50 MB. A file above the cap is skipped with a logged reason, not retried forever.
- **Idempotency**: a derivative is keyed by `(file_id, format)`; re-running a sweep replaces rather than duplicates.
- **Observability**: every sweep writes one `nvoos_activity_events` row (`media.sweep.queued` / `media.sweep.completed`), so the command centre feed shows it.

## 7. Out of scope

- Video/PDF/OCR routes — available on the worker, not needed for Media Ops.
- A wp-admin-style media editor on Laravel.
- Reimplementing Sharp in PHP. Ever.
