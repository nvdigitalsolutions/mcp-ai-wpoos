# WordPress Adapter for oOS Core

## Purpose

Concrete implementations of the 9 domain interfaces from `nvoos/core`, backed by WordPress APIs. Each adapter wraps one WordPress subsystem behind a framework-agnostic contract — same pattern as `nvoos/laravel-adapter` and `nvoos/craft-adapter`, using WordPress's procedural API layer.

## Tier

| | |
|---|---|
| **Distribution** | `nvoos/wordpress-adapter` Composer package (`type: wordpress-plugin`) |
| **PHP target** | 7.4+ |
| **License** | GPL-3.0-or-later |
| **Dependencies** | `nvoos/core ^1.0`, WordPress core |

## Public Surface

| Adapter | Implements | WordPress APIs Wrapped |
|---|---|---|
| `ErrorFactory` | `ErrorFactoryInterface` | `WP_Error` |
| `CacheStore` | `CacheStoreInterface` (extends PSR-6) | `get_transient` / `set_transient`, `wp_cache_*` |
| `SettingsStore` | `SettingsStoreInterface` | `get_option` / `update_option` |
| `EventDispatcher` | `EventDispatcherInterface` (extends PSR-14) | `do_action` / `apply_filters` |
| `FileStore` | `FileStoreInterface` | `wp_insert_attachment`, `get_attached_file`, `wp_generate_attachment_metadata` |
| `QueueClient` | `QueueClientInterface` | Action Scheduler (primary), WP-Cron (fallback) |
| `AuthProvider` | `AuthProviderInterface` | `get_current_user_id`, `user_can`, `wp_verify_nonce` |
| `ContentStore` | `ContentStoreInterface` | `get_post`, `wp_insert_post`, `WP_Query`, `get_post_meta`, `wp_get_post_terms` |

## Conventions

- One adapter per file, one WordPress subsystem per adapter.
- Adapter methods are thin wrappers — one WP function call per method.
- All WordPress function calls are prefixed with `\` (global namespace).
- Adapters do not contain business logic — they translate between domain types and WordPress types.
- PHP 7.4 compatible — no typed properties, no named arguments, no enums.

## Installation

```bash
composer require nvoos/wordpress-adapter
```

The package autoloads via PSR-4 from `src/` (namespace `Nvoos\WordPress\`). No service provider needed — WordPress plugins wire adapters manually in their bootstrap.

## Configuration

Adapters are instantiated directly by the consuming plugin's bootstrap file. See [`includes/bootstrap/oos-bridge.php`](../../includes/bootstrap/oos-bridge.php) for the canonical wiring example used in this monorepo.

## Testing

```bash
vendor/bin/phpunit tests/test-wp-options-store.php
vendor/bin/phpunit tests/test-wp-http-client.php
vendor/bin/phpunit tests/test-wp-content-store.php
```

Tests require a WordPress test environment (WP-Core test suite + PHPUnit Polyfills).

## Also Load

- [`lib/core/src/Domain/Contract/`](../core/src/Domain/Contract/) — the interfaces these implement
- [`lib/core/src/Domain/Entity/`](../core/src/Domain/Entity/) — value objects returned by these adapters
- [`lib/core/src/Domain/Error/`](../core/src/Domain/Error/) — typed domain exceptions these throw
- [`lib/wordpress-adapter/src/Adapter/README.md`](src/Adapter/README.md) — per-adapter details and neighbor map
- [`includes/bootstrap/oos-bridge.php`](../../includes/bootstrap/oos-bridge.php) — DI wiring that instantiates these adapters

> **Monorepo sync:** This directory is synced to `nvdigitalsolutions/nvoos-wordpress-adapter` via `.github/workflows/sync-nvoos-wordpress-adapter.yml` on push to `main` or `alpha-working`.
