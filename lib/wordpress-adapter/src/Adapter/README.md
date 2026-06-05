# WordPress Adapters

## Purpose

Concrete implementations of the 9 domain interfaces from `nvoos/core`, backed by WordPress APIs. Each adapter wraps one WordPress subsystem behind a framework-agnostic contract.

## Tier

| | |
|---|---|
| **Distribution** | `nvoos/wordpress-adapter` Composer package |
| **PHP target** | 7.4+ |
| **Dependencies** | `nvoos/core`, WordPress core |

## Public Surface

| Adapter | Implements | WordPress APIs Wrapped |
|---|---|---|
| `ErrorFactory` | `ErrorFactoryInterface` | `WP_Error` |
| `ContentStore` | `ContentStoreInterface` | `get_post`, `wp_insert_post`, `WP_Query`, `get_post_meta`, `wp_get_post_terms` |
| `AuthProvider` | `AuthProviderInterface` | `get_current_user_id`, `user_can`, `wp_verify_nonce`, `wp_check_password` |
| `SettingsStore` | `SettingsStoreInterface` | `get_option`/`update_option` with 13-provider key maps |
| `FileStore` | `FileStoreInterface` | `wp_insert_attachment`, `get_attached_file`, `wp_generate_attachment_metadata` |
| `CacheStore` | `CacheStoreInterface` | `get_transient`/`set_transient`, `wp_cache_*` (full PSR-6) |
| `QueueClient` | `QueueClientInterface` | Action Scheduler (primary), WP-Cron (fallback) |
| `EventDispatcher` | `EventDispatcherInterface` | `do_action`/`apply_filters` with PSR-14 bridge |

## Conventions

- One adapter per file, one WordPress subsystem per adapter.
- Adapter methods are thin wrappers — one WP function call per method.
- All WordPress function calls are prefixed with `\` (global namespace).
- Adapters do not contain business logic — they translate between domain types and WordPress types.

## Tests

```bash
vendor/bin/phpunit tests/test-wp-options-store.php
vendor/bin/phpunit tests/test-wp-http-client.php
vendor/bin/phpunit tests/test-wp-content-store.php
```

## Also Load

- [`lib/core/src/Domain/Contract/`](../../core/src/Domain/Contract/) — the interfaces these implement
- [`lib/core/src/Domain/Entity/`](../../core/src/Domain/Entity/) — value objects returned by these adapters
- [`includes/bootstrap/oos-bridge.php`](../../../includes/bootstrap/oos-bridge.php) — DI wiring that instantiates these adapters
