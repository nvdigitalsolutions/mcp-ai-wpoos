# Craft CMS Adapters for oOS Core

## Purpose

Concrete implementations of the 9 domain interfaces from `oos/core`, backed by Craft CMS services. Each adapter wraps one Craft subsystem behind the framework-agnostic contract — same pattern as `oos/wordpress-adapter` and `oos/laravel-adapter`, using Craft's Yii-based service layer.

## Tier

| | |
|---|---|
| **Distribution** | `oos/craft-adapter` Composer package |
| **PHP target** | 8.1+ |
| **Dependencies** | `oos/core`, `craftcms/cms ^4.0 || ^5.0` |

## Public Surface

| Adapter | Implements | Craft APIs Wrapped |
|---|---|---|
| `ErrorFactory` | `ErrorFactoryInterface` | Yii exceptions via `\RuntimeException` |
| `CacheStore` | `CacheStoreInterface` (extends PSR-6) | `Craft::$app->cache` (Yii Cache — Redis/DB/file) |
| `SettingsStore` | `SettingsStoreInterface` | `Craft::$app->config` + `Craft::parseEnv()` |
| `EventDispatcher` | `EventDispatcherInterface` (extends PSR-14) | Yii `Event::on()` / `trigger()` |
| `FileStore` | `FileStoreInterface` | `Craft::$app->assets` (Volumes — local/S3/GCS) |
| `QueueClient` | `QueueClientInterface` | `Craft::$app->queue` (Yii Queue — Redis/DB/Beanstalk) |
| `AuthProvider` | `AuthProviderInterface` | `Craft::$app->users` + `getUser()` identity |
| `ContentStore` | `ContentStoreInterface` | `Craft::$app->elements` (Entries, custom elements) |

## Conventions

- One adapter per file, one Craft subsystem per adapter.
- Adapter methods are thin wrappers — one Craft service call per method.
- All adapters use constructor injection where possible; `Craft::$app` accessed via the service locator.
- Adapters do not contain business logic — they translate between domain types and Craft types.
- `declare(strict_types=1)` in every file; PHP 8.1+ features used throughout.

## Installation

```bash
composer require oos/craft-adapter
```

### Bootstrap the module

Add to `config/app.php`:

```php
return [
    'modules' => [
        'oos-core' => \Oos\Craft\Module\OosModule::class,
    ],
    'bootstrap' => [
        'oos-core',
    ],
];
```

## Configuration

Create `config/oos.php`:

```php
return [
    'default_provider'     => Craft::parseEnv('$OOS_DEFAULT_PROVIDER') ?: 'openai',
    'default_model'        => Craft::parseEnv('$OOS_DEFAULT_MODEL') ?: 'gpt-4o-mini',
    'content_section'      => 'posts',
    'cache_duration'       => 3600,
    'queue_ttr'            => 300,
    'storage_volume'       => 'uploads',
    'mesh_api_key'         => Craft::parseEnv('$OOS_MESH_API_KEY') ?: '',
];
```

## Testing

```bash
vendor/bin/phpunit tests/
```

## Also Load

- [`lib/core/src/Domain/Contract/`](../../core/src/Domain/Contract/) — the interfaces these implement
- [`lib/core/src/Domain/Entity/`](../../core/src/Domain/Entity/) — value objects returned by these adapters
- [`lib/wordpress-adapter/src/Adapter/`](../../wordpress-adapter/src/Adapter/) — sibling WordPress implementations
- [`lib/laravel-adapter/src/Adapter/`](../../laravel-adapter/src/Adapter/) — sibling Laravel implementations
