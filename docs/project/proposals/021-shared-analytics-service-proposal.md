# Proposal 021: Shared Analytics Service for All Pro-Toolkits

**Status:** Proposed
**Date:** 2026-08-11
**Author:** NV oOS Development Team
**Affected Toolkits:** Social Media, Ecommerce, Advanced Analytics, Cloudways, SEO, Google Workspace, ECA Management, Quiz Management, Law Firm

---

## Problem Statement

The NV oOS Pro plugin currently has **analytics siloed across individual pro-toolkits**. Each toolkit independently implements data fetching, metric normalization, caching, rate limiting, and chart generation — with significant code duplication and inconsistent schemas between toolkits.

### Current Pain Points

1. **4+ separate analytics implementations** in Social Media toolkit alone (`get_cross_platform_analytics`, `track_hashtag_performance`, `competitor_analysis`, `influencer_identification`) — all with mock data, no real API plumbing
2. **No shared data normalization layer** — each tool re-implements metric name mapping between platform APIs
3. **No caching strategy** — duplicate API calls across tools for the same account data
4. **No rate-limit coordination** — each tool independently hits platform APIs without awareness of others
5. **Fragmented response schemas** — ecommerce analytics returns different shapes than social analytics, making cross-toolkit dashboards impossible
6. **`get_social_analytics` referenced but not implemented** — referenced in `Telegram_Mini_App_Controller` as a tool slug but no unified implementation exists

### Scope

This proposal defines a **single shared `Analytics_Service`** that all pro-toolkits consume, built on industry-standard Adapter + Facade + Aggregator patterns. It covers:

- **Social Media Analytics** (Meta, Twitter/X, LinkedIn, TikTok, Google Business)
- **Ecommerce Analytics** (WooCommerce orders, products, customers)
- **SEO Analytics** (Rank Math, search console)
- **Cloudways Analytics** (server/app metrics)
- **Custom Analytics** (extensible for any toolkit)

---

## Proposed Architecture

### Pattern: Adapter-Facade-Aggregator

```
┌──────────────────────────────────────────────────────────┐
│               PRO-TOOLKIT TOOLS (Consumers)              │
│  Social Media │ Ecommerce │ SEO │ Cloudways │ Analytics  │
└──────────────────────┬───────────────────────────────────┘
                       │  inject / call
┌──────────────────────▼───────────────────────────────────┐
│          Analytics_Service (Shared Facade)                │
│  ┌─────────────┬──────────────┬──────────────────────┐   │
│  │ get_social_ │ get_ecomm_   │ get_seo_analytics()  │   │
│  │ analytics() │ analytics()  │ get_cloudways_...()  │   │
│  └─────────────┴──────────────┴──────────────────────┘   │
│  ┌──────────────────────────────────────────────────┐    │
│  │ Cache Layer │ Rate Limiter │ Metric Normalizer   │    │
│  └──────────────────────────────────────────────────┘    │
└──────────────────────┬───────────────────────────────────┘
                       │  delegates to
┌──────────────────────▼───────────────────────────────────┐
│     Platform Adapters (one per platform)                  │
│  Meta │ Twitter │ LinkedIn │ TikTok │ WooCommerce │ GA4  │
└──────────────────────┬───────────────────────────────────┘
                       │  normalizes into
┌──────────────────────▼───────────────────────────────────┐
│        Unified Analytics Data Model (Immutable DTOs)      │
│  AccountDTO │ PostDTO │ MetricDTO │ TimeSeriesDTO │ Rpt  │
└──────────────────────────────────────────────────────────┘
```

### Directory Layout

```
addons/pro/includes/analytics/
├── README.md
├── init.php                                          # Bootstrap
├── class-wp-mcp-ai-analytics-service.php             # Core facade (singleton)
├── class-wp-mcp-ai-analytics-cache.php               # Transient-based caching
├── class-wp-mcp-ai-analytics-rate-limiter.php        # Token-bucket rate limiter
├── class-wp-mcp-ai-analytics-metric-normalizer.php   # Cross-platform normalization
├── dto/
│   ├── class-wp-mcp-ai-analytics-account-dto.php
│   ├── class-wp-mcp-ai-analytics-post-dto.php
│   ├── class-wp-mcp-ai-analytics-metric-dto.php
│   ├── class-wp-mcp-ai-analytics-timeseries-dto.php
│   └── class-wp-mcp-ai-analytics-report-dto.php
└── adapters/
    ├── interface-wp-mcp-ai-analytics-adapter.php
    ├── class-wp-mcp-ai-analytics-meta-adapter.php
    ├── class-wp-mcp-ai-analytics-twitter-adapter.php
    ├── class-wp-mcp-ai-analytics-linkedin-adapter.php
    ├── class-wp-mcp-ai-analytics-tiktok-adapter.php
    ├── class-wp-mcp-ai-analytics-woocommerce-adapter.php
    ├── class-wp-mcp-ai-analytics-google-analytics-adapter.php
    └── class-wp-mcp-ai-analytics-cloudways-adapter.php
```

---

## Key Design Decisions

### 1. Singleton Service (not DI Container)

WordPress ecosystem convention. The codebase already uses `::instance()` pattern for shared services. The `Analytics_Service` singleton is created on `plugins_loaded` at priority 20 (after toolkit inits).

### 2. Immutable DTOs (not Raw Arrays)

Established pattern in this codebase. Prevents accidental key typos, provides IDE autocompletion, and enables strict validation at hydration time.

### 3. Adapter Pattern per Platform

Each platform API has different auth methods, endpoint URLs, rate limits, and metric names. The `Analytics_Adapter` interface provides a unified contract while allowing platform-specific implementations.

### 4. Computed `engagement_rate` as Unified KPI

Industry standard across Sprinklr, Improvado, and Rivery. All platforms support the component metrics (impressions + engagement actions). The normalizer computes `engagement_rate = (likes + comments + shares + saves) / impressions × 100`.

### 5. Transient Cache over WP Object Cache

Works without persistent object cache. Has built-in TTL support. Uses salted keys for multisite safety (`wp_cache_get_salted` pattern from WP 6.9+ query cache groups).

### 6. `include_sections` Parameter

Instead of separate tools for each analytics view, the unified `get_social_analytics` tool accepts an `include_sections` array. This matches the industry aggregator API pattern (Ayrshare, Outstand) and reduces tool count for AI agents.

---

## Unified Data Model

### Metric Normalization Table

| Unified Metric    | Meta (Facebook/IG)        | Twitter/X           | LinkedIn              | TikTok              |
|-------------------|--------------------------|---------------------|----------------------|---------------------|
| `impressions`     | `page_impressions`       | `impression_count`  | `impressionCount`    | `video_views`       |
| `reach`           | `page_impressions_unique`| `reach`             | `uniqueImpressions`  | —                   |
| `engagement`      | `page_engaged_users`     | `engagements`       | `engagement`         | `total_engagement`  |
| `likes`           | `page_fans`              | `likes`             | `likeCount`          | `digg_count`        |
| `comments`        | —                        | `replies`           | `commentCount`       | `comment_count`     |
| `shares`          | `page_sharedposts`       | `retweets`          | `shareCount`         | `share_count`       |
| `followers`       | `page_fans`              | `followers_count`   | `followerCount`      | `follower_count`    |
| `engagement_rate` | Computed                 | Computed            | Computed             | Computed            |

### Cache TTL Strategy

| Data Type            | TTL       | Storage           |
|---------------------|-----------|-------------------|
| Account profile      | 1 hour    | `wp_cache_set_salted` |
| Post metrics         | 30 min    | Transient         |
| Aggregate summaries  | 15 min    | Transient         |
| Time-series (hist.)  | 6 hours   | Transient         |
| Rate limit counters  | Real-time | WP option (no autoload) |
| Chart data           | 30 min    | Transient         |

---

## Implementation Phases

| Phase | Deliverable | Breaking? | Timeline |
|-------|------------|-----------|----------|
| **1: Foundation** | DTOs, Cache, Rate Limiter, Metric Normalizer, `init.php` | No | Current |
| **2: MVP Adapter** | `Analytics_Adapter` interface + Meta adapter | No | Next |
| **3: Core Service** | `Analytics_Service` singleton with `get_social_analytics()` | No | Next |
| **4: Unified Tool** | `get_social_analytics` tool registered | No | Next |
| **5: Migration** | Existing tools delegate to shared service | Minor (deprecation notices) | After |
| **6: Full Adapters** | All 7 platform adapters | No | After |
| **7: Admin + Obs.** | Settings UI, Site Health, MCP server registration | No | Final |

---

## Success Metrics

- **Tool count reduction:** 4 analytics tools → 1 unified `get_social_analytics`
- **API call reduction:** Parallel `Requests::request_multiple()` → 60% wall-clock reduction
- **Cache hit rate:** >70% within first month
- **Code deduplication:** ~2,000 lines removed across tool implementations
- **Adoption:** All pro-toolkits using shared service within 2 release cycles

---

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| Platform API deprecation | Adapter isolation — only adapter needs updating |
| Rate limit exhaustion | Per-platform token bucket with hard block at 90% usage |
| Cache staleness | Configurable per-platform TTL; manual invalidation endpoints |
| Memory overhead of DTOs | Lazy hydration; singletons only hold references |
| Backward compatibility | Deprecation path, not removal; `use_shared_service` feature flag |

---

## References

- [Outstand Unified Social Media API](https://www.outstand.so/blog/best-unified-social-media-apis-for-devs)
- [Rivery Social Media Analytics Kit](https://rivery.io/kits/social-media-analytics/)
- [arXiv 2604.27710 — Social Media Data Standardization](https://arxiv.org/html/2604.27710v1)
- [Improvado Social Media Data Normalization Guide](https://improvado.io/blog/social-media-data)
- [Mallary Cross-Platform Analytics Guide](https://mallary.ai/blog/cross-platform-analytics)
- NV oOS Pro `ANALYTICS_TOOLS_SUMMARY.md` (internal)
- NV oOS Pro Social Media toolkit README (internal)
