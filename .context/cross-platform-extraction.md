# Cross-Platform Extraction

## Purpose

Context file for AI agents working on or near the framework-agnostic extraction of the oOS AI orchestration engine. The extraction uses **Hexagonal Architecture (Ports & Adapters)** with a Strangler Fig migration pattern — the new engine coexists with the legacy path behind a feature flag.

## Key Files

| File | Purpose |
|---|---|
| `lib/core/` | `nvoos/core` Composer package — framework-agnostic domain, application, infrastructure, and tool layers (PHP 8.1+, MIT) |
| `lib/wordpress-adapter/` | `nvoos/wordpress-adapter` — WordPress implementations of all 8 domain contracts (PHP 7.4+, GPL-3.0) |
| `lib/laravel-adapter/` | `nvoos/laravel-adapter` — Laravel implementations of all 8 domain contracts (PHP 8.1+, MIT) |
| `lib/craft-adapter/` | `nvoos/craft-adapter` — Craft CMS implementations of all 8 domain contracts (PHP 8.1+, MIT) |
| `includes/bootstrap/oos-bridge.php` | WordPress DI wiring — factory function `wp_mcp_ai_oos_orchestrator()`, PSR-4 autoloader, feature flag detection |
| `includes/class-wp-mcp-ai-rest.php` | Chat REST controller — checks `wp_mcp_ai_oos_engine_enabled()` and delegates to `handle_chat_request_oos()` |
| `.github/workflows/sync-nvoos-*.yml` | Monorepo sync workflows — `git subtree split` each `lib/*` package to its standalone GitHub repo on push |
| `docs/project/proposals/laravel-scale-deployment-architecture.md` | Laravel Octane orchestrator deployment plan — Redis Queue, Horizon, Reverb, pgvector, federation (2026-07-01) |
| `docs/project/proposals/nvoos-base-restructuring-roadmap.md` | Graphify ecosystem architecture — `nvoos-graphify` core + `nvoos-graphify-ai` + `nvoos-graphify-ai-platform` |

## Architecture (Hexagonal)

```
lib/core/src/
├── Domain/
│   ├── Contract/          # 9 ports (interfaces) — the "what"
│   │   ├── ContentStoreInterface.php
│   │   ├── AuthProviderInterface.php
│   │   ├── SettingsStoreInterface.php
│   │   ├── FileStoreInterface.php
│   │   ├── CacheStoreInterface.php
│   │   ├── QueueClientInterface.php
│   │   ├── EventDispatcherInterface.php
│   │   ├── ErrorFactoryInterface.php
│   │   └── ToolInterface.php (+ sub-interfaces)
│   ├── Entity/            # 10 immutable value objects
│   ├── Error/             # 5 typed exceptions
│   └── Event/             # 8 PSR-14 domain events
├── Application/
│   ├── Chat/ChatOrchestrator.php    # Agentic loop
│   ├── Provider/ProviderRouter.php  # 15-provider routing
│   ├── Tool/ToolRegistry.php        # Tool lifecycle
│   └── Skill/SkillRegistry.php      # SKILL.md discovery
├── Infrastructure/
│   ├── Provider/          # 15 AI provider clients
│   ├── Streaming/SseHandler.php     # RFC 6202 SSE
│   ├── Cost/CostCalculator.php      # Per-model pricing
│   └── Token/TokenBudgetManager.php # Context-window validation
└── Tool/                  # 82 framework-agnostic tools (42% of base)

lib/wordpress-adapter/src/Adapter/   # 8 WordPress adapters
├── ContentStore.php       # Wraps get_post / WP_Query / wp_insert_post
├── AuthProvider.php       # Wraps current_user_can / credentials
├── SettingsStore.php      # Wraps get_option / update_option
├── FileStore.php          # Wraps wp_upload_dir / wp_insert_attachment
├── CacheStore.php         # Wraps get_transient / wp_cache_*
├── QueueClient.php        # Wraps Action Scheduler / WP-Cron
├── EventDispatcher.php    # Wraps do_action / apply_filters
└── ErrorFactory.php       # Wraps WP_Error ↔ domain exceptions

lib/laravel-adapter/src/Adapter/     # 8 Laravel adapters
├── ContentStore.php       # Wraps Eloquent models + query scopes
├── AuthProvider.php       # Wraps Auth facade + Sanctum + Gates
├── SettingsStore.php      # Wraps config() + DB-backed settings
├── FileStore.php          # Wraps Storage facade (Flysystem)
├── CacheStore.php         # Wraps Cache facade (Redis/memcached/DB)
├── QueueClient.php        # Wraps Queue facade + Bus
├── EventDispatcher.php    # Wraps Event facade + FilterBus
└── ErrorFactory.php       # Wraps abort() + domain exceptions

lib/craft-adapter/src/Adapter/       # 8 Craft CMS adapters
├── ContentStore.php       # Wraps Craft::$app->elements
├── AuthProvider.php       # Wraps Craft::$app->users
├── SettingsStore.php      # Wraps Craft::$app->config + parseEnv
├── FileStore.php          # Wraps Craft::$app->assets (Volumes)
├── CacheStore.php         # Wraps Craft::$app->cache (Yii Cache)
├── QueueClient.php        # Wraps Craft::$app->queue (Yii Queue)
├── EventDispatcher.php    # Wraps Yii Event::on / trigger
└── ErrorFactory.php       # Wraps Yii exceptions
```

## Feature Flag

The OOS engine is activated via any of these (checked in order):

1. **Admin setting:** `wp_mcp_ai_settings['enable_oos_engine']` (Chat Client → Behavior → OOS Engine)
2. **Constant:** `define('WP_MCP_AI_OOS_ENGINE', true)`
3. **Header:** `X-WP-MCP-AI-Engine: oos`
4. **Query param:** `?engine=oos`

When the flag is off, the legacy `handle_chat_request()` path is completely unchanged.

## PHP Version Split

- **`lib/core/`** requires **PHP 8.1+** (uses `readonly` classes, enums, fibers, named arguments)
- **`lib/wordpress-adapter/`** targets **PHP 7.4+** (uses traditional getters/setters, no readonly)
- **`includes/bootstrap/oos-bridge.php`** returns early on PHP < 8.1

## Current State (2026-07-13)

- **9/9 contracts** — complete (fully domain-owned, zero PSR/Symfony inheritance)
- **10/10 entities** — complete
- **5/5 error classes** — complete
- **8/8 domain events** — complete
- **4/4 application services** — complete
- **15/15 provider clients** — complete (12 concrete + OpenAiCompatibleClient + AbstractProviderClient + Baseten)
- **8/8 WordPress adapters** — complete
- **8/8 Laravel adapters** — complete
- **8/8 Craft CMS adapters** — complete
- **82/~195 tools migrated** — 42% (verified via `grep -c` on bridge registrations)
- **Tests: 29 files, 227 tests, 772 assertions** — entities (10/10), errors (5/5), providers (5/15 with tests), tools (11 tool test files), integration (12 tests via ToolRegistryIntegrationTest)
- **PHPStan: level 5** (target 8, blocked by ~251 bare array type errors)
- **4 new domain contracts** proposed for Laravel orchestrator: VectorStoreInterface, FederationClientInterface, MeshRouterInterface, StreamingInterface
- **Autoloader: regenerated** — namespace corrected from Oos\Core → Nvoos\Core after rename

## Monorepo Sync

Each `lib/` package is synced to its own standalone GitHub repo via `git subtree split` on push to `main` or `alpha-working`. Workflows live in `.github/workflows/sync-nvoos-*.yml`.

| Source | Workflow | Target repo | Secret |
|---|---|---|---|
| `lib/core/` | `sync-nvoos-core.yml` | `nvdigitalsolutions/nvoos-core` | `NVOOS_CORE_REPO_TOKEN` |
| `lib/wordpress-adapter/` | `sync-nvoos-wordpress-adapter.yml` | `nvdigitalsolutions/nvoos-wordpress-adapter` | `NVOOS_WORDPRESS_ADAPTER_REPO_TOKEN` |
| `lib/laravel-adapter/` | `sync-nvoos-laravel-adapter.yml` | `nvdigitalsolutions/nvoos-laravel-adapter` | `NVOOS_LARAVEL_ADAPTER_REPO_TOKEN` |
| `lib/craft-adapter/` | `sync-nvoos-craft-adapter.yml` | `nvdigitalsolutions/nvoos-craft-adapter` | `NVOOS_CRAFT_ADAPTER_REPO_TOKEN` |

## Rules for Agents

1. **When adding a new tool to `lib/core/src/Tool/`:** Inject domain contracts via constructor — never call WordPress functions directly. Extend `Nvoos\Core\Tool\AbstractTool`. Register in `wp_mcp_ai_oos_orchestrator()` in `oos-bridge.php`.

2. **When adding a new WordPress/Laravel/Craft adapter:** Implement the corresponding interface from `lib/core/src/Domain/Contract/`. Place in `lib/<platform>-adapter/src/Adapter/`. For WordPress, wire in `oos-bridge.php`. For Laravel/Craft, the adapter's own `ServiceProvider`/`Module` handles DI wiring.

3. **When touching the legacy `includes/tools/`:** Check if the equivalent tool already exists in `lib/core/src/Tool/`. If so, prefer migrating callers to the OOS path rather than extending the legacy implementation.

4. **Error handling:** Use `ErrorFactoryInterface` in core code. Domain exceptions (`AccessDeniedException`, `NotFoundException`, etc.) should be thrown by adapters rather than returning `WP_Error`. The WordPress `ErrorFactory` adapter translates domain exceptions to `WP_Error` at the boundary.

5. **Events:** Use `EventDispatcherInterface` (PSR-14 + filter) instead of `do_action`/`apply_filters` in core code. Existing WordPress hooks are bridged via `EventDispatcher::mapEventToHook()`.

6. **Testing:** When adding to `lib/core`, prefer unit tests with mocked adapters over WordPress integration tests. The core package must be testable without WordPress bootstrapping.

7. **Monorepo sync:** When adding files to any `lib/` package, be aware that the corresponding `sync-nvoos-*.yml` workflow will push the entire subtree to a standalone repo. Do not add monorepo-only dependencies (e.g., `repositories` path entries pointing to sibling packages) that would break in the standalone context. The `lib/laravel-adapter/composer.json` `repositories.path` entry pointing to `../core` is a known exception — harmless because Composer falls back to Packagist.

## Also Load

- [`docs/project/proposals/cross-platform-extraction-architecture.md`](../docs/project/proposals/cross-platform-extraction-architecture.md) — full proposal
- [`docs/project/proposals/cross-platform-extraction-gap-analysis.md`](../docs/project/proposals/cross-platform-extraction-gap-analysis.md) — current-state assessment
- [`.context/conventions.md`](conventions.md) — naming, style
- [`.context/security-checklist.md`](security-checklist.md) — security rules
- [`.context/tool-registry.md`](tool-registry.md) — tool authoring rules
- [`docs/project/proposals/laravel-scale-deployment-architecture.md`](../docs/project/proposals/laravel-scale-deployment-architecture.md) — Laravel Octane orchestrator deployment plan
- [`docs/project/proposals/RELATED_PROPOSALS_INDEX.md`](../docs/project/proposals/RELATED_PROPOSALS_INDEX.md) — unified index of 20+ connected proposals
