# Shared Analytics Service

Shared analytics subsystem consumed by all NV oOS Pro toolkits.

## Purpose

Provides a single, consistent analytics service with a unified data model (5 immutable DTOs), cross-platform normalization, smart caching, rate-limit coordination, and an extensible adapter pattern — so every Pro toolkit reports analytics the same way.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ (Pro addon minimum) |
| **Loaded by** | `addons/pro/includes/analytics/init.php` on `plugins_loaded` priority 20 (after toolkit inits at priority 10, so platform adapters can be registered) |
| **Optional dependencies** | none (platform connectivity is the adapter's concern) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Analytics_Service` (singleton facade) | `class-wp-mcp-ai-analytics-service.php` | Social media tools (`get_social_analytics`, `get_cross_platform_analytics`), admin settings page |
| `WP_MCP_AI_Analytics_Adapter` (interface) | `interface-wp-mcp-ai-analytics-adapter.php` | All platform adapters |
| `WP_MCP_AI_Analytics_Account_DTO` | `dto/class-wp-mcp-ai-analytics-account-dto.php` | Service + adapters (immutable data carrier) |
| `WP_MCP_AI_Analytics_Post_DTO` | `dto/class-wp-mcp-ai-analytics-post-dto.php` | Service + adapters |
| `WP_MCP_AI_Analytics_Metric_DTO` | `dto/class-wp-mcp-ai-analytics-metric-dto.php` | Service + adapters |
| `WP_MCP_AI_Analytics_TimeSeries_DTO` | `dto/class-wp-mcp-ai-analytics-timeseries-dto.php` | Service + adapters |
| `WP_MCP_AI_Analytics_Report_DTO` | `dto/class-wp-mcp-ai-analytics-report-dto.php` | Service + tools (final response shape) |
| `WP_MCP_AI_Analytics_Cache` | `class-wp-mcp-ai-analytics-cache.php` | Service (transient cache + stampede prevention) |
| `WP_MCP_AI_Analytics_Rate_Limiter` | `class-wp-mcp-ai-analytics-rate-limiter.php` | Service (per-platform token bucket) |
| `WP_MCP_AI_Analytics_Metric_Normalizer` | `class-wp-mcp-ai-analytics-metric-normalizer.php` | Service (platform → unified schema) |
| `WP_MCP_AI_Analytics_Site_Health` | `class-wp-mcp-ai-analytics-site-health.php` | Site Health integration |
| `WP_MCP_AI_Analytics_{Meta,Twitter,LinkedIn,TikTok,WooCommerce,GA4,Cloudways}_Adapter` | `adapters/*.php` | Registered by `init.php` / toolkits |

## Inputs / Outputs / Neighbors

- **Reads from:** platform APIs through adapters; transient cache; provider credentials via the Pro settings registry.
- **Writes to:** transients (per-data-type TTLs); Site Health info.
- **Upstream callers:** `addons/pro/includes/tools/social-media/` (get_social_analytics, get_cross_platform_analytics), `addons/pro/includes/tools/ecommerce/` and `../tools/analytics/` (Phase 4+ consumers), `addons/pro/includes/admin/class-wp-mcp-ai-analytics-service-page.php`.
- **Downstream collaborators:** platform adapters (same folder); `addons/pro/includes/mcp-servers/` (registered MCP servers).
- **Events fired:** none public.
- **Events listened to:** none public.

## Conventions

- DTOs are immutable data carriers — hydrate once via `from_array()`, never mutate after construction; presenters/tools map DTOs to responses, they do not extend them.
- Every adapter implements `WP_MCP_AI_Analytics_Adapter` and returns normalized DTOs only — never raw platform payloads.
- All platform calls go through the rate limiter and the cache layer; adapters must not call external APIs directly.
- New platforms are added as new adapter classes + one registration line in `init.php` — no changes to the service singleton.

## Architecture

```
DTOs (data)  →  Cache + RateLimiter + Normalizer (services)
                      ↓
              Adapter Interface
                      ↓
              Meta Adapter (first implementation)
                      ↓
              Analytics_Service (singleton facade)
                      ↓
              Pro-toolkit tools (consumers, Phase 4+)
```

## Quick Start

```php
// Get the service singleton.
$service = WP_MCP_AI_Analytics_Service::instance();

// Fetch social analytics across all connected platforms.
$report = $service->get_social_analytics( array(
    'platforms'       => array( 'instagram', 'facebook' ),
    'date_from'       => '2026-08-01',
    'date_to'         => '2026-08-11',
    'include_sections' => array( 'summary', 'engagement', 'top_posts' ),
    'top_posts_count'  => 5,
) );

// Register a new platform adapter.
$service->register_adapter( 'twitter', new WP_MCP_AI_Analytics_Twitter_Adapter() );

// Invalidate cache for a platform.
$service->invalidate_cache( 'instagram' );
```

## Implementation Status

| Phase | Component | Status |
|-------|-----------|--------|
| 1 | DTOs (5) | ✅ Complete |
| 1 | Analytics_Cache | ✅ Complete |
| 1 | Analytics_Rate_Limiter | ✅ Complete |
| 1 | Metric_Normalizer | ✅ Complete |
| 1 | Adapter Interface | ✅ Complete |
| 2 | Meta_Adapter (FB+IG) | ✅ Complete |
| 2 | Twitter_Adapter (X) | ✅ Complete |
| 2 | LinkedIn_Adapter | ✅ Complete |
| 2 | TikTok_Adapter | ✅ Complete |
| 2 | WooCommerce_Adapter | ✅ Complete |
| 2 | GA4_Adapter | ✅ Complete |
| 2 | Cloudways_Adapter | ✅ Complete |
| 3 | Analytics_Service | ✅ Complete |
| 4 | get_social_analytics tool | ✅ Complete |
| 5 | Migrated cross_platform_analytics | ✅ Complete |
| 6 | Admin settings UI | ✅ Complete |
| 7 | MCP server registration | ✅ Complete |
| 7 | Site Health integration | ✅ Complete |

## Tests

```bash
composer run lint:errors-only
```

There is no dedicated PHPUnit suite for this folder yet; the consumer tools (social media analytics tools) and the service page are covered by the Pro toolkit tool tests. When adding adapters, add `tests/` coverage alongside.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (always; API credentials flow through here)
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — tool registration patterns
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro-only subsystem boundaries

## See Also

- Upstream parent: [`addons/pro/includes/`](../)
- Siblings worth knowing about: [`../tools/social-media/`](../tools/social-media/) (primary consumer), [`../mcp-servers/`](../mcp-servers/)
- Proposal: [`docs/project/proposals/021-shared-analytics-service-proposal.md`](../../../../docs/project/proposals/021-shared-analytics-service-proposal.md)
