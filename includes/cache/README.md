# cache/

## Purpose

Tag-aware caching primitive for NV oOS — wraps Symfony Cache with automatic Redis → APCu → filesystem adapter selection and exposes a uniform `get/set/get_or_set/invalidate_tags` API.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ (see [`CLAUDE.md`](../../CLAUDE.md)) |
| **Loaded by** | Symfony autoloader (`WP_MCP_AI\Cache` namespace) for `WP_MCP_AI_Cache_Service`; legacy `WP_MCP_AI_Cache_Adapter` is included by the service container when a caller pulls it. Both are also loaded directly by tests via `require_once`. |
| **Optional dependencies** | `ext-redis` + `WP_REDIS_HOST`/`WP_REDIS_ENABLED` constants → Redis adapter; `ext-apcu` + `apcu_enabled()` → APCu adapter; otherwise filesystem under `wp_upload_dir()/wp-mcp-ai-cache/`. All three paths are runtime-detected; no third-party plugin is required. |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI\Cache\WP_MCP_AI_Cache_Service` | `class-wp-mcp-ai-cache-service.php` | REST cache layer, provider clients, tool caches, anywhere needing tag invalidation |
| `::get_instance()`, `get()`, `set()`, `get_or_set()`, `delete()`, `clear()`, `invalidate_tags()`, `get_adapter_type()` | same file | tag-aware cache API (Symfony contracts `TagAwareCacheInterface` + PSR-6 under the hood) |
| `WP_MCP_AI_Cache_Adapter` | `class-wp-mcp-ai-cache-adapter.php` | low-level Redis/Memcached adapter used where Symfony's stampede protection is unnecessary |

## Inputs / Outputs / Neighbors

- **Reads from:** Caller keys + callbacks; environment (`WP_REDIS_HOST`, `WP_REDIS_PORT`, `WP_REDIS_ENABLED`, APCu state); `wp_upload_dir()` for the filesystem fallback location.
- **Writes to:** Redis (namespace `wp_mcp_ai`), APCu (prefix `wp_mcp_ai`), or `<uploads>/wp-mcp-ai-cache/` — exactly one is active per request. The legacy adapter writes Redis/Memcached entries under the prefix `mcp_ai_`.
- **Upstream callers:** `WP_MCP_AI_REST_Cache`, `WP_MCP_AI_Cache_Helper`, model-catalog migration, federation peer cache, transcript-mining, provider model lists, tool-recommendation cache.
- **Downstream collaborators:** Symfony Cache adapters (`RedisTagAwareAdapter`, `FilesystemTagAwareAdapter`, `TagAwareAdapter` wrapping `ApcuAdapter`), `\Redis`, `\Memcached`, `wp_mkdir_p()`.
- **Events fired:** None — caches are intentionally side-effect-free.
- **Events listened to:** None.

## Conventions

- **Cache-key namespaces** are owned by callers. Recommended convention: prefix keys with the subsystem name, e.g. `rest:tools:list`, `models:openai:catalog`, `federation:peer:<id>`. Do not collide with the legacy adapter's `mcp_ai_` prefix.
- **Tag-invalidation prefixes**: `assistants`, `tools`, `models`, `federation`, `transcripts` — invalidate by tag instead of constructing wildcard delete patterns. Tags are required for any data set whose lifetime depends on a CPT or option being mutated.
- Use `get_or_set()` for callback-generated values — it pulls in Symfony's stampede protection (early expiration recompute) for free.
- The filesystem fallback uses the uploads directory (not `WP_CONTENT_DIR`) so the path is portable across managed-hosting environments.
- The legacy `WP_MCP_AI_Cache_Adapter` is retained for hot-path callers that cannot afford the Symfony bootstrap cost; it is **not** tag-aware. Prefer the service for new code.

## Tests

```bash
vendor/bin/phpunit tests/test-cache-service.php
vendor/bin/phpunit tests/test-cache-helper.php
vendor/bin/phpunit tests/test-rest-cache.php
vendor/bin/phpunit tests/test-api-caching.php
vendor/bin/phpunit tests/test-token-tier-caching.php
vendor/bin/phpunit tests/test-orchestration-memory-stats-cache.php
```

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — never cache per-user authorised payloads under a global key (always)
- [`.context/rest-api.md`](../../.context/rest-api.md) — REST cache layering & header rules

## See Also

- Companion helpers in parent `includes/`: `class-wp-mcp-ai-cache-helper.php`, `class-wp-mcp-ai-rest-cache.php`
- Sibling primitives: [`includes/http/`](../http/), [`includes/filesystem/`](../filesystem/)
