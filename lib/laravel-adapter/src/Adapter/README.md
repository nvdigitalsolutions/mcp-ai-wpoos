# Laravel Adapters

## Purpose

Concrete implementations of the 9 domain interfaces from `nvoos/core`, backed by Laravel framework APIs. Each adapter wraps one Laravel subsystem behind a framework-agnostic contract.

## Tier

| | |
|---|---|
| **Distribution** | `nvoos/laravel-adapter` Composer package |
| **PHP target** | 8.1+ |
| **Dependencies** | `nvoos/core`, `illuminate/*` |

## Public Surface

| Adapter | Implements | Laravel APIs Wrapped |
|---|---|---|
| `ErrorFactory` | `ErrorFactoryInterface` | Domain exceptions via `\RuntimeException` |
| `CacheStore` | `CacheStoreInterface` | `Cache` facade (Redis, memcached, file, database) |
| `SettingsStore` | `SettingsStoreInterface` | `config()` helper, DB-backed settings |
| `EventDispatcher` | `EventDispatcherInterface` | `Event` facade + custom FilterBus |
| `FileStore` | `FileStoreInterface` | `Storage` facade (Flysystem: local, S3, GCS) |
| `QueueClient` | `QueueClientInterface` | `Queue` facade (Redis, SQS, database, sync) |
| `AuthProvider` | `AuthProviderInterface` | `Auth` facade, Sanctum tokens, Gates |
| `ContentStore` | `ContentStoreInterface` | Eloquent models, query builder, pagination |

## Conventions

- One adapter per file, one WordPress subsystem per adapter.
- Adapter methods are thin wrappers — one framework call per method.
- All adapters use constructor injection; no static facade calls in constructors.
- Adapters do not contain business logic — they translate between domain types and framework types.
- `declare(strict_types=1)` in every file.

## Tests

```bash
vendor/bin/phpunit tests/
```

Uses Orchestra Testbench for a bootable Laravel test environment.

## Also Load

- [`lib/core/src/Domain/Contract/`](../../core/src/Domain/Contract/) — the interfaces these implement
- [`lib/core/src/Domain/Entity/`](../../core/src/Domain/Entity/) — value objects returned by these adapters
- [`lib/wordpress-adapter/src/Adapter/`](../../../wordpress-adapter/src/Adapter/) — sibling WordPress implementations for reference

> **Monorepo sync:** This directory is synced to `nvdigitalsolutions/nvoos-laravel-adapter` via `.github/workflows/sync-nvoos-laravel-adapter.yml` on push to `main` or `alpha-working`.
