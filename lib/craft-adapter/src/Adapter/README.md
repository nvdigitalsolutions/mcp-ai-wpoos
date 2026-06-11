# Craft CMS Adapters

## Purpose

Concrete implementations of the 9 domain interfaces from `nvoos/core`, backed by Craft CMS services. Each adapter wraps one Craft subsystem behind a framework-agnostic contract.

## Tier

| | |
|---|---|
| **Distribution** | `nvoos/craft-adapter` Composer package |
| **PHP target** | 8.1+ |
| **Dependencies** | `nvoos/core`, `craftcms/cms ^4.0 \|\| ^5.0` |

## Public Surface

| Adapter | Implements | Craft APIs Wrapped |
|---|---|---|
| `ErrorFactory` | `ErrorFactoryInterface` | Yii exceptions via `\RuntimeException` |
| `CacheStore` | `CacheStoreInterface` | `Craft::$app->cache` (Yii Cache) |
| `SettingsStore` | `SettingsStoreInterface` | `Craft::$app->config` + `Craft::parseEnv()` |
| `EventDispatcher` | `EventDispatcherInterface` | Yii `Event::on()` / `trigger()` |
| `FileStore` | `FileStoreInterface` | `Craft::$app->assets` (Volumes) |
| `QueueClient` | `QueueClientInterface` | `Craft::$app->queue` (Yii Queue) |
| `AuthProvider` | `AuthProviderInterface` | `Craft::$app->users` + `getUser()` |
| `ContentStore` | `ContentStoreInterface` | `Craft::$app->elements` (Entries) |

## Conventions

- One adapter per file, one Craft subsystem per adapter.
- Adapter methods are thin wrappers — one Craft service call per method.
- Config via `config/nvoos.php` with `Craft::parseEnv()` for secrets.
- Adapters do not contain business logic.
- `declare(strict_types=1)` in every file.

## Tests

```bash
vendor/bin/phpunit tests/
```

## Also Load

- [`lib/core/src/Domain/Contract/`](../../core/src/Domain/Contract/) — the interfaces these implement
- [`lib/core/src/Domain/Entity/`](../../core/src/Domain/Entity/) — value objects returned by these adapters
- [`lib/wordpress-adapter/src/Adapter/`](../../../wordpress-adapter/src/Adapter/) — sibling WordPress implementations
- [`lib/laravel-adapter/src/Adapter/`](../../../laravel-adapter/src/Adapter/) — sibling Laravel implementations

> **Monorepo sync:** This directory is synced to `nvdigitalsolutions/nvoos-craft-adapter` via `.github/workflows/sync-nvoos-craft-adapter.yml` on push to `main` or `alpha-working`.

