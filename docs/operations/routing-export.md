# Routing export (OTLP)

The runtime records **routing deviations** — a model fallback, or a hop from one
provider to another — in `nvoos_routing_metrics`, and can push them to any
OTLP-compatible collector. This page is the operator contract: what is sent, how
delivery behaves, and what the collector must do to make it correct.

Design rationale lives in
[Proposal 149 §73–§78](../project/proposals/149-laravel-admin-parity-implementation-plan.md);
this page is what you need in front of you while running it.

## Quick start

```dotenv
NVOOS_ROUTING_EXPORT_ENDPOINT=https://collector.example.com/v1/logs
NVOOS_ROUTING_EXPORT_TOKEN=...            # becomes Authorization: Bearer …
NVOOS_ROUTING_EXPORT_SCHEDULE="*/15 * * * *"  # empty = configured, manual only
```

Until `NVOOS_ROUTING_EXPORT_SCHEDULE` is set, nothing is sent automatically; the
console action and `php artisan nvoos:push-routing-metrics` still work. Setting a
schedule changes nothing else, so it is safe to configure the endpoint first and
decide the cadence later.

| Variable | Default | Meaning |
|---|---|---|
| `NVOOS_ROUTING_EXPORT_ENDPOINT` | *(empty)* | Any `http(s)` URL. Empty disables the push **and** relaxes retention. |
| `NVOOS_ROUTING_EXPORT_TOKEN` | *(empty)* | Sent as `Authorization: Bearer …`. |
| `NVOOS_ROUTING_EXPORT_TIMEOUT` | `10` | Seconds per request. |
| `NVOOS_ROUTING_EXPORT_BATCH` | `500` | Rows per request. |
| `NVOOS_ROUTING_EXPORT_SCHEDULE` | *(empty)* | Cron expression; empty means manual only. |
| `NVOOS_ROUTING_EXPORT_BACKOFF_BASE` | `60` | First retry delay, doubling per failure. `0` disables backoff. |
| `NVOOS_ROUTING_EXPORT_BACKOFF_MAX` | `3600` | Cap on the retry window. |
| `NVOOS_ROUTING_EXPORT_BACKOFF_JITTER` | `0.2` | Additive jitter, as a fraction of the window. |
| `NVOOS_ROUTING_EXPORT_COMPRESSION` | `none` | `none` or `gzip`. See below. |
| `NVOOS_ROUTING_EXPORT_COMPRESSION_THRESHOLD` | `1024` | Bytes of JSON below which the body is sent plain. `0` compresses everything. |

Extra headers for collectors that expect vendor-specific keys go in
`oos.routing.export.headers` (a map in `config/oos.php`), not an environment
variable — the map is not a scalar and a malformed JSON env value would fail at
request time rather than at boot.

## Delivery is at-least-once — dedupe on the event id

**This is the part a collector must implement.**

The watermark only advances on a `2xx`. A crash between the collector's `2xx`
and the watermark write resends that batch on the next run. The runtime cannot
tell "the collector received it and we crashed" from "the collector never saw
it", so it chooses to resend: duplicated events are recoverable, dropped events
are not.

Every event therefore carries a stable identifier:

- OTLP: the attribute `nvoos.routing.event_id` (`intValue`, rendered as a
  string because OTLP timestamps/ids are strings on the wire);
- flat JSON: the field `id`.

The id is the `nvoos_routing_metrics` row id. It never changes, so a resent batch
carries exactly the ids the collector already saw.

**Dedupe on that field** — a unique constraint, a short-lived seen-set, or a
merge on `event_id` — and delivery becomes *effectively once*. A collector that
ignores the id will double-count events that were resent after a crash. That is
a deployment responsibility, stated here rather than implied by the plan.

There is no ordering guarantee beyond "oldest row first within one batch".

## What gets sent

One OTLP/JSON **log record** per row (not a span — a deviation is a fact about a
request that already finished). Attribute names follow the GenAI conventions
where they exist:

| Attribute | On |
|---|---|
| `gen_ai.provider.name` | every record |
| `gen_ai.request.model`, `gen_ai.response.model` | model fallbacks |
| `nvoos.routing.kind` | every record (`model_fallback`, `provider_hop`) |
| `nvoos.routing.event_id` | every record — the dedupe key |
| `nvoos.routing.succeeded` | every record |
| `nvoos.routing.from_provider` | provider hops |

Severity is `WARN` for a failed attempt and `INFO` for a successful one.

`php artisan nvoos:export-routing-metrics --format=otel` renders the same records
to stdout or a file, which is the quickest way to see what a collector receives.

## Retention, the watermark, and backoff

Retention deletes rows older than `NVOOS_ROUTING_RETENTION_DAYS` (default 90,
`0` disables), **but only rows at or below the export watermark while an endpoint
is configured**. A broken collector therefore grows the table instead of losing
data — the correct direction to be wrong in.

A failing push backs off exponentially (60s, 120s, 240s …, capped at an hour)
with up to 20% additive jitter. A run inside the backoff window exits `SUCCESS`:
nothing is wrong with the command, the collector is having a bad day. A scheduler
that pages on non-zero exit will not page every tick during an outage.

`--force` bypasses the window — for an operator who has fixed the collector and
does not want to wait.

## Is it exporting?

```bash
php artisan nvoos:push-routing-metrics --dry-run   # what is pending, and whether backing off
php artisan nvoos:prune-routing-metrics --dry-run  # what retention would delete
```

`GET /api/v1/diagnostics/overview` returns `data.routing_export`:

```json
{ "configured": true, "backing_off": true, "retry_after": "2026-11-19T11:03:00+00:00", "consecutive_failures": 2 }
```

`retry_after` is `null` whenever `backing_off` is false, so a scrape never has to
compare it against its own clock. `configured: false` distinguishes "export is
off" from "export is healthy".

## Compression

`NVOOS_ROUTING_EXPORT_COMPRESSION=gzip` sends `Content-Encoding: gzip` for bodies
over the threshold. OTLP requires every receiver to accept gzip, so a real
collector needs no configuration. It is **off by default** because it changes the
wire format on upgrade, and a self-hosted JSON sink that logs bodies is a
legitimate endpoint. The header is only sent when a body was actually compressed.

## Failure modes

| Symptom | Likely cause | Action |
|---|---|---|
| Table grows, nothing in the collector | Endpoint unreachable, or collector rejecting with non-2xx | Check `data.routing_export.consecutive_failures`; the last error is on `/ops/diagnostics` |
| `Push failed (HTTP 401/403)` | Token missing or wrong | `NVOOS_ROUTING_EXPORT_TOKEN`, then `--force` |
| `Push failed (HTTP 415/400)` | Collector rejects `Content-Encoding` or `application/json` | Set `NVOOS_ROUTING_EXPORT_COMPRESSION=none`; confirm the URL is the OTLP logs path |
| Duplicates in the collector | Crash-resend, as designed | Dedupe on `nvoos.routing.event_id` |
| Retention deletes nothing | Endpoint configured but the watermark never advanced | Fix the push first; retention will resume |
| Retries stop for an hour | Backoff after repeated failures | Fix the cause, then `--force` |
