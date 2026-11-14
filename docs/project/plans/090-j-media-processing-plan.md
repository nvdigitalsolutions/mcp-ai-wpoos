# Proposal 090-j — Media Processing on the Laravel Runtime

**Status:** Slices 1–3 shipped 2026-11-14 — the read-only snapshot, alt-text storage and the worker-backed compression sweep.
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

## 3. Slice 2 — alt text (shipped)

1. Migration `2026_11_15_000000_add_alt_text_to_nvoos_files_table.php`: `alt_text` (string 500, nullable) on `nvoos_files`.
2. `POST /api/v1/media` accepts `alt_text`; `PATCH /api/v1/media/{id}` updates it. Both sit in the `upload_files` group, matching WordPress's `upload_files` check on the attachment editor. An empty string (or `null`, which Laravel's `ConvertEmptyStringsToNull` produces) clears it.
3. Snapshot: `with_alt`, `alt_pct` and `descriptive_filename_count` are real. The image mime list and the ten generic filename prefixes are copied from WordPress's gatherers, so the same library reports the same percentages. `alt_pct` is **100** when there are no images, exactly as WordPress reports it.
4. Acceptance: uploading with `alt_text` moves `alt_pct`; 1 of 2 images with alt reports `50`.

## 4. Slice 3 — compression sweep (shipped)

1. Migration `2026_11_15_010000_create_nvoos_media_derivatives_table.php`: one row per `(file_id, format)` with `path`, `size_bytes` and a **signed** `savings_bytes` (a miss must not offset a win). The unique key makes a re-run idempotent.
2. `App\Jobs\OptimizeMediaFile` — reads the source from its disk, posts multipart to `POST /api/image/optimize` (`webp`, quality 80), stores the returned bytes at `derived/{id}.{format}`, writes the derivative row, and records `media.optimize` lifecycle rows in `nvoos_jobs`. `tries = 3`, backoff `[30, 120, 300]`, 120s timeout. Non-images are cancelled, not failed; sources above the worker's 50 MB cap are skipped with an activity event.
3. `POST /api/v1/pro/media-cc/compression-sweep` — deduped: refused while any `media.optimize` job is queued or running (mirrors WordPress's `as_has_scheduled_action`), 50 files per dispatch, skips files that already have a `webp` derivative. Writes one `media.sweep.queued` activity event.
4. Snapshot: `compression` = `sum(savings_bytes)` over positive rows; `capabilities.sweep` is `true`, so the console action appears without a client change.
5. Acceptance: a sweep of 2 images queues 2 `media.optimize` jobs and the page shows `pending: 2`; running a job writes a derivative and `compression` increases by the reported saving.

## 5. Slice 4 — schedule summary (S, optional, not started)

`active` / `ran_today` / `overdue` / `next_run` from the code-defined schedule plus `nvoos_jobs` timestamps. Only worth doing once a recurring media job exists (e.g. a nightly sweep). Until then zeros are truthful, and the snapshot says so.

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
