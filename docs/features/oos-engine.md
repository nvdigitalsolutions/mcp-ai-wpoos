# OOS Engine — Feature Flag Guide

**The framework-agnostic extraction of the oOS AI orchestration engine is operational behind a feature flag.** This guide explains how to enable it, what changes, and how to migrate from the legacy engine.

## Quick Start

Add `?engine=oos` to any chat request or set the constant:

```php
define( 'WP_MCP_AI_OOS_ENGINE', true );
```

Or enable globally in **NV oOS → Settings → Chat Client → Behavior → OOS Engine**.

## Activation Methods

The engine is activated via any of these, checked in order:

| Method | How | Best For |
|---|---|---|
| **Admin setting** | NV oOS → Settings → Chat Client → Behavior → Enable OOS Engine | Production rollout |
| **Constant** | `define( 'WP_MCP_AI_OOS_ENGINE', true );` in `wp-config.php` | Site-wide activation |
| **HTTP header** | `X-WP-MCP-AI-Engine: oos` | API clients, testing tools |
| **Query parameter** | `?engine=oos` on any REST request | Development, debugging |

When none of these are active, the legacy `handle_chat_request()` path runs unchanged.

## What Changes

### Architecture

The OOS engine runs the same chat/tool logic through a **Hexagonal Architecture** with framework-agnostic contracts:

```
Legacy path:                          OOS engine path:
REST Controller                       REST Controller
  → handle_chat_request()               → handle_chat_request_oos()
  → WP_Query, get_post(), ...           → ContentStoreInterface::find()
  → do_action('wp_mcp_ai_*')            → EventDispatcherInterface::dispatch()
  → new WP_Error(...)                   → ErrorFactoryInterface::create()
```

### Tools

43 of ~195 base tools run through the OOS engine. The remaining tools fall back to the legacy execution path. See [`docs/project/proposals/cross-platform-extraction-gap-analysis.md`](../project/proposals/cross-platform-extraction-gap-analysis.md) for the full list.

### Providers

All 12 AI providers work identically — the provider clients in `lib/core/src/Infrastructure/Provider/` are used by both paths.

### Events

WordPress hooks continue to fire. The `EventDispatcher` adapter bridges domain events to `wp_mcp_ai_*` hooks via `mapEventToHook()`. The domain event system no longer extends PSR-14 — it uses fully domain-owned contracts.

## Performance

The OOS engine adds negligible overhead — adapters are thin wrappers (one-liner WordPress function calls). The PHP 8.1+ core benefits from JIT and readonly class optimizations.

## Requirements

| Component | Requirement |
|---|---|
| PHP | 8.1+ (core uses `readonly`, enums, fibers) |
| WordPress | 6.0+ |
| Files | `lib/core/src/` and `lib/wordpress-adapter/src/` must be present |
| Build | Full/complete build only — `lib/` is excluded from base WordPress.org builds |

## Debugging

When the OOS engine fails to boot, an error is logged to the PHP error log (when `WP_DEBUG` is enabled). The `?engine=oos` query parameter overrides all other settings for quick testing.

```bash
# Test the OOS engine on a single request:
curl -X POST https://yoursite.com/wp-json/mcp-ai/v1/chat?engine=oos \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{"messages":[{"role":"user","content":"Hello"}],"assistant_id":1}'
```

## Migration Path

1. **Test:** Enable `?engine=oos` on individual requests during development
2. **Staging:** Set `WP_MCP_AI_OOS_ENGINE` constant on a staging environment
3. **Production:** Enable the admin setting once all critical tools are validated
4. **Default:** When all ~195 tools are migrated, the flag will be removed and the OOS engine becomes the only path

## Related

- [`docs/project/proposals/cross-platform-extraction-architecture.md`](../project/proposals/cross-platform-extraction-architecture.md) — full architecture proposal
- [`docs/project/proposals/cross-platform-extraction-gap-analysis.md`](../project/proposals/cross-platform-extraction-gap-analysis.md) — current status and tool inventory
- [`.context/cross-platform-extraction.md`](../../.context/cross-platform-extraction.md) — agent context (architecture map + rules)
- [`lib/README.md`](../../lib/README.md) — extraction library overview
- [`includes/bootstrap/oos-bridge.php`](../../includes/bootstrap/oos-bridge.php) — WordPress DI wiring
