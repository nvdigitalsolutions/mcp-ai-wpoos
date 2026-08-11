# Shared Analytics Service

Shared analytics subsystem consumed by all NV oOS Pro toolkits.

## Purpose

Provides a single, consistent analytics service with:

- **Unified data model** — 5 immutable DTOs (Account, Post, Metric, TimeSeries, Report)
- **Cross-platform normalization** — Platform-specific metric names mapped to unified schema
- **Smart caching** — Transient-based with per-data-type TTLs and cache stampede prevention
- **Rate limit coordination** — Token-bucket per platform prevents API exhaustion
- **Extensible adapter pattern** — One interface, many platforms (Meta ✓, Twitter, LinkedIn, TikTok, WooCommerce, GA4, Cloudways)

## Neighbors

- `../tools/social-media/` — Social Media toolkit tools (will consume this service in Phase 4)
- `../tools/ecommerce/` — Ecommerce toolkit (will consume this service)
- `../tools/analytics/` — Advanced Analytics toolkit (complementary)
- `../mcp-servers/` — MCP server definitions (will register tools)

## Context Files

Load alongside this directory:
- `.context/security-checklist.md`
- `.context/tool-registry.md`
- `.context/pro-addon-architecture.md`

## Entry Point

`init.php` — Bootstrap on `plugins_loaded` priority 20.

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

## Testing

```bash
# Run analytics-specific tests
vendor/bin/phpunit tests/analytics/

# Lint the analytics directory
composer run lint:errors-only
```
