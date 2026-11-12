# Proposal 090 — Laravel Adapter Spike: Execution Report

**Status:** Spike complete (dev-verified) — **Go** for the Laravel track, with production hardening follow-ups
**Date:** 2026-11-12
**Companion plan:** [`090-laravel-adapter-spike-implementation-plan.md`](./090-laravel-adapter-spike-implementation-plan.md)
**Proposal:** [`../proposals/090-laravel-adapter-spike-implementation-plan.md`](../proposals/090-laravel-adapter-spike-implementation-plan.md)

---

## 1. Result summary

The spike ran `nvoos/core` + `nvoos/laravel-adapter` on **Laravel 12.72 / Octane 2.24 / FrankenPHP (PHP 8.3.36)** with MySQL 8 and Redis 7, without WordPress in the request path. All seven pass criteria (P1–P7) passed, plus the MCP dual-era conformance checks, a formal 30-minute 10-stream soak, and a 200-request latency benchmark.

**Recommendation: Go.** The adapter contracts hold under Octane's persistent workers, the engine executes a real tool end-to-end, SSE streams survive client disconnects, Redis queue retries are observable, persistence round-trips, and MCP serves both protocol eras. The remaining work is production hardening and a production-scale load profile.

---

## 2. Environment

| Component | Version / image |
|---|---|
| Host | Windows, Docker 29.9.3 |
| App runtime | `dunglas/frankenphp:1-php8.3`, PHP 8.3.36 |
| Laravel | 12.72.0 |
| Octane | 2.24.0 |
| Laravel MCP | 1.2.0 |
| Sanctum | 4.4.0 |
| Database | MySQL 8.0 |
| Queue/cache | Redis 7 |
| Adapter packages | `nvoos/core` + `nvoos/laravel-adapter` via Composer path repositories |

---

## 3. Pass criteria evidence

| Criterion | Result | Evidence |
|---|---|---|
| **P1 — Boot** | ✅ | `GET /health` → `{"ok":true,"php":"8.3.36","laravel":"12.72.0","runtime":"frankenphp-worker","abspath":false,...}`; all 9 contracts + `SseHandler` resolved; `nvoos:doctor` table all OK |
| **P2 — One real tool** | ✅ | `POST /dev/tools/color_convert` → `{"success":true,"message":"rgb(255,0,0)","data":{...}}` through `ToolRegistry::execute()` |
| **P3 — One SSE stream** | ✅ | `/chat/stream` emitted `status*`, `message*`, `[DONE]`; a mid-stream `--max-time 1` abort did not kill the worker (`/health` 200 immediately after) |
| **P4 — One queue job + retry** | ✅ | `nvoos:queue-demo` → `Final: completed`; `nvoos_jobs` row: `status=completed attempts=2` |
| **P5 — Persistence round-trip** | ✅ | `ContentStoreRoundTripTest` creates and re-reads a `nvoos_posts` row through `ContentStoreInterface` |
| **P6 — MCP smoke** | ✅ | Modern `server/discover`, `tools/list`, `tools/call` (2026-07-28 + SEP-2243 headers) and legacy `initialize`/`tools/list`/`tools/call` (2025-06-18) all pass; Origin and header-ladder rejections verified |
| **P7 — Adapter regression** | ✅ | Adapter: 29 tests / 88 assertions; core SSE: 3 / 6; app: 9 / 23 — all green |

### P6 conformance details

| Case | Result |
|---|---|
| Modern `server/discover` (`MCP-Protocol-Version: 2026-07-28`, `Mcp-Method`) | `200`, `resultType: complete`, `supportedVersions: ["2026-07-28"]` |
| Modern `tools/list` | `200`, includes `color_convert` |
| Modern `tools/call` (`Mcp-Name: color_convert`) | `200`, structured content `rgb(0,255,0)` |
| Legacy `initialize` (`2025-06-18`) | `200`, negotiated version + server info |
| Legacy `tools/list` / `tools/call` | `200` |
| Bad `Origin` | `403` / `-32600` |
| Missing `Mcp-Name` on modern call | `400` / `-32020` |
| Legacy `initialize` with modern version header | `400` / `-32022` (supported legacy versions listed) |
| No bearer token | `401` fail-closed |

`laravel/mcp` v1.2 supports the modern 2026-07-28 dialect out of the box, so the documented `mcp/sdk` fallback was **not activated**.

---

## 4. Non-functional evidence

### Soak

10 concurrent SSE streams for **300 seconds** (interim run):

```
started=3470 completed=3460 errors=0 bytes=4020520 done=3460
```

10 concurrent SSE streams for the **formal 1,800 seconds** (`OCTANE_MAX_REQUESTS=500`):

```
started=75899 completed=75889 errors=0 bytes=88183758 done=75889
```

Both runs have 10 in-flight streams at cutoff, which is expected. The formal 30-minute gate **passed** with zero errors. The reproducible command is:

```bash
SPIKE_TOKEN=... php bin/laravel-spike-soak.php --streams=10 --seconds=1800
```

### Latency

`/health`, 200 requests, `OCTANE_MAX_REQUESTS=500`:

| Metric | Value |
|---|---|
| min | 10.79 ms |
| p50 | 13.78 ms |
| p95 | 17.20 ms |
| p99 | 18.93 ms |
| max | 20.93 ms |
| avg | 14.07 ms |

With `OCTANE_MAX_REQUESTS=1` (dev reload mode), p50 rises to ~73.8 ms because every request restarts a worker — expected, and the reason dev and benchmark modes are separated.

---

## 5. Defects found and fixed during execution

| # | Defect | Fix |
|---|---|---|
| 1 | `ChatOrchestrator` binding omitted `CostCalculator` and `SseHandler` | Provider now constructs the full six-argument orchestrator as a `scoped` binding |
| 2 | No `PlatformFlushInterface` implementation | Added `Nvoos\Laravel\Adapter\PlatformFlush` |
| 3 | Empty `ToolRegistry` / no provider clients / no `HttpClientInterface` | Added `HttpClient`, `ProviderRegistrar` (12 providers), and `color_convert` registration + `notifyRegistered()` |
| 4 | `SseHandler::sendHeaders()` wrote global headers, incompatible with `StreamedResponse` | Added `SseHandler::markHeadersSent()` and a Laravel SSE controller that sets response headers itself |
| 5 | `QueueClient` used `env()`, assumed the DB `jobs` table, and returned fake IDs on Redis | Config-driven connection, UUID job IDs, durable `nvoos_jobs` status store, driver-aware status/cancel/list |
| 6 | `NvoosToolJob` had no retry contract | Added `$tries=3`, `$backoff=[5,15,30]`, `$timeout=300`, `failed()`, status transitions |
| 7 | `AuthProvider` used `Auth::getProvider()` and returned null under Sanctum's request guard | Resolve the configured web guard's provider with a Sanctum-safe fallback |
| 8 | `nvoos_file_metadata` indexed a `TEXT` column; MySQL rejected it | Index `meta_key` only (SQLite tests could not catch this) |
| 9 | Adapter required Laravel 10/11 only | Widened to `illuminate/* ^12.41.1 || ^13.0`, Testbench `^10.8 || ^11.0`, PHP `^8.2` |
| 10 | Config typo `NVNVOOS_CONTENT_MODEL`; queue env names mismatched compose | Fixed the typo and added `NVOOS_QUEUE_CONNECTION` → `OOS_QUEUE_CONNECTION` → `QUEUE_CONNECTION` fallback |
| 11 | Web routes required CSRF, breaking token-authenticated SSE/tools | Split `/dev/tools` and `/chat/stream` into `routes/api.php` with `apiPrefix: ''` |
| 12 | Docker env leaked into PHPUnit; `RefreshDatabase` wiped the live MySQL database | Added `tests/bootstrap.php` forcing `putenv`/`$_ENV`/`$_SERVER` to SQLite/sync; added `force="true"` to phpunit.xml |
| 13 | Dev chat limiter (60/min) throttled the soak | Local/testing limit raised to 60,000/min; production remains 60/min |
| 14 | Setup swallowed migration failures | Entrypoint now fails fast on `migrate` errors |

---

## 6. Files added/changed

### Core
- `lib/core/src/Infrastructure/Streaming/SseHandler.php` — `markHeadersSent()`
- `lib/core/tests/Unit/Infrastructure/Streaming/SseHandlerTest.php`

### Adapter
- `composer.json` — Laravel 12.41+/13, Testbench, scripts
- `src/ServiceProvider/NvoosServiceProvider.php` — Octane-safe lifetimes, tool/provider registration, migrations
- `src/Adapter/PlatformFlush.php`, `HttpClient.php`, `JobStatusStore.php`
- `src/Provider/ProviderRegistrar.php`
- `src/Adapter/AuthProvider.php` — guard-safe user resolution
- `src/Adapter/QueueClient.php`, `src/Jobs/NvoosToolJob.php`
- `src/Adapter/SettingsStore.php`, `FileStore.php` — config-bound, no stale in-memory cache
- `config/nvoos.php` — typo/queue fallback
- `database/migrations/2026_01_01_000007_create_nvoos_file_metadata_table.php` — MySQL-safe index
- `database/migrations/2026_01_01_000008_create_nvoos_jobs_table.php`
- Tests: `TestCase.php`, `ServiceProviderTest.php`, `ContentStoreRoundTripTest.php`, `QueueClientTest.php`, `PlatformFlushTest.php`, updated bootstrap

### App skeleton (`docker/laravel/app-skeleton`)
- Laravel 12.72 skeleton committed (vendor/storage ignored), with `laravel/mcp`, Octane, Sanctum, path repositories
- `app/Http/Controllers/{HealthController,ToolController,ChatStreamController}.php`
- `app/Mcp/Servers/NvoosServer.php`, `app/Mcp/Tools/ColorConvertMcpTool.php`
- `app/Spike/{SpikeStreamingProvider,SpikeFlakyHandler}.php`
- `app/Console/Commands/{NvoosDoctor,NvoosTool,NvoosQueueDemo,NvoosSpikeToken}.php`
- `app/Http/Middleware/EnsureSpikeEnvironment.php`
- `app/Providers/AppServiceProvider.php` — Gates, rate limiters, fake provider
- `bootstrap/app.php` — API routes, commands, middleware alias
- `routes/{web,api,ai}.php`
- `config/octane.php` — `frankenphp`, `max_execution_time=0`
- `tests/` — health, tool, SSE, content round-trip, MCP, config isolation; `tests/bootstrap.php`

### Infrastructure
- `docker/laravel/Dockerfile` — FrankenPHP PHP 8.3 + pdo_mysql/pdo_sqlite/intl/zip/opcache/redis/pcntl/fileinfo
- `docker/laravel/entrypoint.sh` — setup/app/worker roles, skeleton copy, Composer path repos, migrations, Octane/queue
- `docker/docker-compose.laravel.yml` — setup/app/worker/db/redis, healthchecks, volumes
- `bin/laravel-spike-smoke.sh`, `bin/laravel-spike-soak.php`, `bin/laravel-spike-bench.php`

---

## 7. Residual risks and follow-ups

| # | Item | Status / action |
|---|---|---|
| 1 | Formal 30-minute soak | ✅ Passed 2026-11-12: 75,899 started / 75,889 completed / 0 errors. Repeat before production commitment |
| 2 | Production Octane hardening | `max_execution_time=0` is spike-only; use bounded values + Supervisor/systemd + `octane:reload` on deploy |
| 3 | Tool roster | Only `color_convert` is registered; add a config-driven registrar for the remaining key-free tools |
| 4 | MCP resumability | `Last-Event-ID` replay is MAY in the spec and not implemented |
| 5 | OAuth | Sanctum bearer tokens only; Passport/OAuth 2.1 not tested |
| 6 | Multi-tenant auth | Spike Gates are permissive; replace with policies before any real deployment |
| 7 | App skeleton coupling | The committed skeleton pins Laravel 12.72; keep the adapter constraint range and re-run when Laravel 13 is adopted |
| 8 | CI | Local smoke/soak scripts are ready; wire them into a workflow if the spike becomes part of release gating |

---

## 8. Reproduce

```bash
docker compose -f docker/docker-compose.laravel.yml up -d --build
bash bin/laravel-spike-smoke.sh --no-build

# Soak
TOKEN=$(docker compose -f docker/docker-compose.laravel.yml exec -T app php artisan nvoos:spike-token | tail -n1 | tr -d '\r')
SPIKE_TOKEN="$TOKEN" php bin/laravel-spike-soak.php --streams=10 --seconds=1800

# Benchmark (benchmark mode)
OCTANE_MAX_REQUESTS=500 docker compose -f docker/docker-compose.laravel.yml up -d --force-recreate app
php bin/laravel-spike-bench.php --url=http://localhost:8001/health --requests=200
OCTANE_MAX_REQUESTS=1 docker compose -f docker/docker-compose.laravel.yml up -d --force-recreate app
```
