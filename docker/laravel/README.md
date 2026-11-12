# NV oOS Laravel Spike (FrankenPHP / Octane)

Dev-only environment proving `nvoos/core` + `nvoos/laravel-adapter` on
Laravel Octane with FrankenPHP, MySQL, and Redis — no WordPress in the
request path.

Evidence and pass criteria: [`docs/project/plans/090-laravel-adapter-spike-report.md`](../../docs/project/plans/090-laravel-adapter-spike-report.md).

## Start

```bash
docker compose -f docker/docker-compose.laravel.yml up -d --build
```

Services:

| Service | Role |
|---|---|
| `setup` | One-shot: copy `app-skeleton`, Composer install, migrate, exit |
| `app` | Octane/FrankenPHP on <http://localhost:8001> |
| `worker` | `queue:work redis --queue=oos` |
| `db` | MySQL 8 |
| `redis` | Redis 7 (cache + queue) |

The runtime app is written to `docker/laravel/app/` (gitignored). The
committed source is `docker/laravel/app-skeleton/`.

## Verify

```bash
bash bin/laravel-spike-smoke.sh --no-build
```

Endpoints:

| Endpoint | Auth | Purpose |
|---|---|---|
| `GET /health` | none | Bindings, tools, providers, worker runtime, `ABSPATH` guard |
| `POST /dev/tools/{slug}` | Sanctum | Execute a core tool (dev/testing only) |
| `POST /chat/stream` | Sanctum | SSE chat stream |
| `POST /mcp` | Sanctum | MCP Streamable HTTP (modern + legacy eras) |

Get a token:

```bash
docker compose -f docker/docker-compose.laravel.yml exec -T app php artisan nvoos:spike-token
```

## Soak / benchmark

```bash
TOKEN=$(docker compose -f docker/docker-compose.laravel.yml exec -T app php artisan nvoos:spike-token | tail -n1 | tr -d '\r')
SPIKE_TOKEN="$TOKEN" php bin/laravel-spike-soak.php --streams=10 --seconds=1800

OCTANE_MAX_REQUESTS=500 docker compose -f docker/docker-compose.laravel.yml up -d --force-recreate app
php bin/laravel-spike-bench.php --url=http://localhost:8001/health --requests=200
OCTANE_MAX_REQUESTS=1 docker compose -f docker/docker-compose.laravel.yml up -d --force-recreate app
```

`OCTANE_MAX_REQUESTS=1` is the dev reload mode; use `500` for benchmarks.

## Notes

- `config/octane.php` sets `max_execution_time=0` for long SSE streams.
  Production must use a bounded value.
- The adapter's `SettingsStore` has no in-memory cache; `ProviderRouter` is
  a singleton, while `ChatOrchestrator` and `SseHandler` are `scoped`.
- Tests force SQLite/sync in `tests/bootstrap.php` so Docker's MySQL/Redis
  environment cannot leak into `RefreshDatabase`.
- The `mcp/sdk` fallback is not needed: `laravel/mcp` v1.2 serves both
  `2026-07-28` (`server/discover`) and `2025-06-18` (`initialize`).
