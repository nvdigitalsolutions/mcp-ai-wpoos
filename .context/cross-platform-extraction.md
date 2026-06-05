# Cross-Platform Extraction

## Purpose

Context file for AI agents working on or near the framework-agnostic extraction of the oOS AI orchestration engine. The extraction uses **Hexagonal Architecture (Ports & Adapters)** with a Strangler Fig migration pattern — the new engine coexists with the legacy path behind a feature flag.

## Key Files

| File | Purpose |
|---|---|
| `lib/core/` | `nvoos/core` Composer package — framework-agnostic domain, application, infrastructure, and tool layers (PHP 8.1+, MIT) |
| `lib/wordpress-adapter/` | `nvoos/wordpress-adapter` — WordPress implementations of all 8 domain contracts (PHP 7.4+, GPL-3.0) |
| `includes/bootstrap/oos-bridge.php` | WordPress DI wiring — factory function `wp_mcp_ai_oos_orchestrator()`, PSR-4 autoloader, feature flag detection |
| `includes/class-wp-mcp-ai-rest.php` | Chat REST controller — checks `wp_mcp_ai_oos_engine_enabled()` and delegates to `handle_chat_request_oos()` |

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
│   ├── Provider/ProviderRouter.php  # 12-provider routing
│   ├── Tool/ToolRegistry.php        # Tool lifecycle
│   └── Skill/SkillRegistry.php      # SKILL.md discovery
├── Infrastructure/
│   ├── Provider/          # 12 AI provider clients
│   ├── Streaming/SseHandler.php     # RFC 6202 SSE
│   └── Cost/CostCalculator.php      # Per-model pricing
└── Tool/                  # 43 framework-agnostic tools

lib/wordpress-adapter/src/Adapter/   # 8 WordPress adapters
├── ContentStore.php       # Wraps get_post / WP_Query / wp_insert_post
├── AuthProvider.php       # Wraps current_user_can / credentials
├── SettingsStore.php      # Wraps get_option / update_option
├── FileStore.php          # Wraps wp_upload_dir / wp_insert_attachment
├── CacheStore.php         # Wraps get_transient / wp_cache_*
├── QueueClient.php        # Wraps Action Scheduler / WP-Cron
├── EventDispatcher.php    # Wraps do_action / apply_filters
└── ErrorFactory.php       # Wraps WP_Error ↔ domain exceptions
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

## Current State (2026-06-03)

- **9/9 contracts** — complete
- **10/10 entities** — complete
- **5/5 error classes** — complete
- **8/8 domain events** — complete
- **4/4 application services** — complete
- **12/12 provider clients** — complete
- **8/8 WordPress adapters** — complete
- **43/~195 tools migrated** — 22% (Tier 1 + select Tier 2)
- **Tests for lib/core** — 0% (not yet started)
- **Laravel adapter** — 0%
- **Craft adapter** — 0%

## Rules for Agents

1. **When adding a new tool to `lib/core/src/Tool/`:** Inject domain contracts via constructor — never call WordPress functions directly. Extend `Nvoos\Core\Tool\AbstractTool`. Register in `wp_mcp_ai_oos_orchestrator()` in `oos-bridge.php`.

2. **When adding a new WordPress adapter:** Implement the corresponding interface from `lib/core/src/Domain/Contract/`. Place in `lib/wordpress-adapter/src/Adapter/`. Wire in `oos-bridge.php`.

3. **When touching the legacy `includes/tools/`:** Check if the equivalent tool already exists in `lib/core/src/Tool/`. If so, prefer migrating callers to the OOS path rather than extending the legacy implementation.

4. **Error handling:** Use `ErrorFactoryInterface` in core code. Domain exceptions (`AccessDeniedException`, `NotFoundException`, etc.) should be thrown by adapters rather than returning `WP_Error`. The WordPress `ErrorFactory` adapter translates domain exceptions to `WP_Error` at the boundary.

5. **Events:** Use `EventDispatcherInterface` (PSR-14 + filter) instead of `do_action`/`apply_filters` in core code. Existing WordPress hooks are bridged via `EventDispatcher::mapEventToHook()`.

6. **Testing:** When adding to `lib/core`, prefer unit tests with mocked adapters over WordPress integration tests. The core package must be testable without WordPress bootstrapping.

## Also Load

- [`docs/project/proposals/cross-platform-extraction-architecture.md`](../docs/project/proposals/cross-platform-extraction-architecture.md) — full proposal
- [`docs/project/proposals/cross-platform-extraction-gap-analysis.md`](../docs/project/proposals/cross-platform-extraction-gap-analysis.md) — current-state assessment
- [`.context/conventions.md`](conventions.md) — naming, style
- [`.context/security-checklist.md`](security-checklist.md) — security rules
- [`.context/tool-registry.md`](tool-registry.md) — tool authoring rules
