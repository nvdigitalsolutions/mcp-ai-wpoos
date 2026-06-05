# Laravel Adapters for oOS Core

## Purpose

Concrete implementations of the 9 domain interfaces from `nvoos/core`, backed by Laravel framework services. Each adapter wraps one Laravel subsystem behind the framework-agnostic contract — same pattern as `nvoos/wordpress-adapter`, different backing APIs.

## Tier

| | |
|---|---|
| **Distribution** | `nvoos/laravel-adapter` Composer package |
| **PHP target** | 8.1+ |
| **Dependencies** | `nvoos/core`, `illuminate/*` |

## Public Surface

| Adapter | Implements | Laravel APIs Wrapped |
|---|---|---|
| `ErrorFactory` | `ErrorFactoryInterface` | Custom exceptions via `abort()` / domain exceptions |
| `CacheStore` | `CacheStoreInterface` (extends PSR-6) | `Cache` facade (Redis/memcached/file/database) |
| `SettingsStore` | `SettingsStoreInterface` | `config()` helper + DB-backed settings table |
| `EventDispatcher` | `EventDispatcherInterface` (extends PSR-14) | `Event` facade + custom FilterBus |
| `FileStore` | `FileStoreInterface` | `Storage` facade (Flysystem — local/S3/GCS) |
| `QueueClient` | `QueueClientInterface` | `Queue` facade + `Bus` (Redis/SQS/database/sync) |
| `AuthProvider` | `AuthProviderInterface` | `Auth` facade + Sanctum tokens + Gates |
| `ContentStore` | `ContentStoreInterface` | Eloquent models + query scopes |

## Conventions

- One adapter per file, one Laravel subsystem per adapter.
- Adapter methods are thin wrappers — one framework call per method.
- All adapters use constructor injection; no static facade calls in constructors.
- Adapters do not contain business logic — they translate between domain types and framework types.
- `declare(strict_types=1)` in every file; PHP 8.1+ features used throughout.

## Installation

```bash
composer require nvoos/laravel-adapter
```

The package auto-discovers its service provider via Laravel's package discovery.

## Configuration

Publish the config file for customization:

```bash
php artisan vendor:publish --tag=oos-config
```

```php
// config/nvoos.php
return [
    'content_model'     => \App\Models\Post::class,
    'cache_store'       => env('NVOOS_CACHE_STORE', 'redis'),
    'queue_connection'  => env('NVOOS_QUEUE_CONNECTION', 'redis'),
    'storage_disk'      => env('NVOOS_STORAGE_DISK', 'public'),
    'settings_table'    => 'nvoos_settings',
];
```

## Testing

```bash
vendor/bin/phpunit tests/
```

Tests use Orchestra Testbench to boot a minimal Laravel application with the adapter bindings.

## Also Load

- [`lib/core/src/Domain/Contract/`](../../core/src/Domain/Contract/) — the interfaces these implement
- [`lib/core/src/Domain/Entity/`](../../core/src/Domain/Entity/) — value objects returned by these adapters
- [`lib/wordpress-adapter/src/Adapter/`](../../wordpress-adapter/src/Adapter/) — sibling WordPress implementations for reference
