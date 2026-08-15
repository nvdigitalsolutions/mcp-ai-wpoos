# Comprehensive Implementation Plan: Shared Analytics Service for All Pro-Toolkits

**Proposal:** [021-shared-analytics-service-proposal.md](./021-shared-analytics-service-proposal.md)
**Status:** Ready for Implementation
**Date:** 2026-08-11
**Phase:** 1 of 7 (Foundation)
**Files to create:** 13 (+ 2 docs)

---

## Table of Contents

1. [Research Summary](#1-research-summary)
2. [Architecture](#2-architecture)
3. [File-by-File Specifications](#3-file-by-file-specifications)
4. [Implementation Checklist](#4-implementation-checklist)
5. [Validation & Testing](#5-validation--testing)
6. [Code Quality Gate](#6-code-quality-gate)

---

## 1. Research Summary

### 1.1 Industry Best Practices

| Source | Key Finding | Applied To |
|--------|------------|------------|
| **Meta Graph API docs** (v22.0) | Instagram provides `impressions`, `reach`, `engagement`, `saves`, `shares`, `video_views`, `likes`, `comments` via `/{ig-media-id}/insights`; account-level via `/{ig-user-id}/insights`. Facebook Pages use `page_impressions`, `page_engaged_users`, `page_fans` | `Meta_Adapter` field mapping |
| **Twitter/X API v2 docs** | `public_metrics` returns `impression_count`, `like_count`, `retweet_count`, `reply_count`, `quote_count`, `bookmark_count`. `non_public_metrics` (impressions, url link clicks, user profile clicks) require user auth | `Twitter_Adapter` field mapping; public metrics available without user token |
| **LinkedIn Marketing API** (Microsoft Learn) | `organizationalEntityShareStatistics` returns `totalShareStatistics` (impressions, clicks, likes, comments, shares). `organizationFollowerStatistics` provides organic/paid follower gains | `LinkedIn_Adapter` endpoints |
| **Rivery Social Analytics Kit** | Industry standard data model: 5 core tables (Accounts, Posts, Actions, Entities) + 2 enrichment tables. Metric normalization per platform | Our DTO design: AccountDTO, PostDTO, MetricDTO |
| **Improvado Data Normalization Guide** | "Without normalization, different platforms create misleading insights. Data normalization standardizes metrics, aligns naming, restructures fields into one analytical logic" | `Metric_Normalizer` bidirectional mapping |
| **WordPress Transients API** (WP.org) | "Transients should be used to store data expected to expire. Keep keys under 172 chars; use `hash()` for long inputs. Race conditions solved via `wp_cache_add()` with lock pattern" | `Analytics_Cache` using transients with salted keys; lock pattern for warm-up |
| **WordPress Rate Limiting** (Signocore) | "Transients as sliding counter; persistent object cache reduces DB write overhead; Nginx for general, PHP transients for sensitive endpoints" | `Analytics_Rate_Limiter` token bucket via WP options (no autoload) |

### 1.2 Current Codebase Patterns (Internal)

| Pattern | Location | Convention |
|---------|----------|------------|
| Tool registration | `addons/pro/includes/tools/*/init.php` | Check `wp_mcp_ai_settings` for toolkit enable flag |
| Singleton service | `WP_MCP_AI_Memory_Capture_Service` | `::instance()` with `plugins_loaded` hook |
| DTO with `from_array()` | `WP_MCP_AI_Tool_Capability_Flags_Interface` usage | Private constructor + static factory |
| MCP server | `class-wp-mcp-ai-social-media-mcp-server.php` | Extends `WP_MCP_AI_Toolkit_Server_Base` |
| Pro constants | `mcp-ai-wpoos-pro.php:L32-62` | `WP_MCP_AI_PRO_PATH`, `WP_MCP_AI_PRO_URL`, `WP_MCP_AI_PRO_VERSION` |
| Text domain | Throughout `addons/pro/` | `mcp-ai-wpoos-pro` |
| PHPDoc | All classes/methods | `@since 1.7.0`, `@package WP_MCP_AI_Pro` |

---

## 2. Architecture

### 2.1 Layer Diagram

```
┌──────────────────────────────────────────────────────────────┐
│                     TOOL LAYER (Phase 4)                      │
│  get_social_analytics  track_hashtag  competitor  influencer │
│    └─ thin wrappers calling Analytics_Service                 │
└────────────────────────────────┬─────────────────────────────┘
                                 │
┌────────────────────────────────▼─────────────────────────────┐
│              Analytics_Service (Phase 3 — Singleton)          │
│  get_social_analytics(params): ReportDTO                      │
│  get_ecommerce_analytics(params): ReportDTO                   │
│  register_adapter(platform, adapter): void                    │
│  invalidate_cache(platform, ?account): void                   │
└──────┬──────────────┬──────────────┬─────────────────────────┘
       │              │              │
       ▼              ▼              ▼
┌──────────────┐ ┌──────────┐ ┌─────────────────┐
│ Cache Layer  │ │ Rate     │ │ Metric          │
│ (Phase 1)    │ │ Limiter  │ │ Normalizer      │
│              │ │ (Phase 1)│ │ (Phase 1)       │
│ salted transi│ │ token    │ │ bidirec. mapping│
│ ents with TTL│ │ bucket   │ │ computed metrics│
└──────────────┘ └──────────┘ └─────────────────┘
       │
       ▼
┌──────────────────────────────────────────────────────────────┐
│              ADAPTER LAYER (Phase 2+)                          │
│  MetaAdapter │ TwitterAdapter │ LinkedInAdapter │ TikTokAdapter│
│  WooCommerceAdapter │ GAAnalyticsAdapter │ CloudwaysAdapter   │
│                                                                │
│  All implement: WP_MCP_AI_Analytics_Adapter (Phase 2)         │
└──────────────────────────────────────────────────────────────┘
       │
       ▼
┌──────────────────────────────────────────────────────────────┐
│              DTO LAYER (Phase 1)                               │
│  AccountDTO │ PostDTO │ MetricDTO │ TimeSeriesDTO │ ReportDTO │
│  Immutable, from_array() hydration, to_array() serialization  │
└──────────────────────────────────────────────────────────────┘
```

### 2.2 Data Flow (Social Analytics Request)

```
1. Tool: get_social_analytics({platforms:['instagram','twitter'], date_from:'2026-08-01', ...})
2.   └─ Analytics_Service::get_social_analytics($params)
3.       ├─ Rate_Limiter::check('instagram') → OK
4.       ├─ Cache::get(salted_key) → MISS (cold)
5.       ├─ Meta_Adapter::get_account_insights(...)
6.       │   └─ wp_remote_get('https://graph.facebook.com/v22.0/{ig-user-id}/insights?...')
7.       │   └─ Metric_Normalizer::normalize('impressions', $raw_metric_data)
8.       │   └─ Returns AccountDTO + MetricDTO[] + PostDTO[]
9.       ├─ Twitter_Adapter::get_account_insights(...) [parallel with Meta]
10.      │   └─ wp_remote_get('https://api.twitter.com/2/users/{id}/tweets?...')
11.      │   └─ Returns AccountDTO + MetricDTO[] + PostDTO[]
12.      ├─ Cache::set(salted_key, result, TTL=15min)
13.      └─ Returns ReportDTO { summary, trends, top_posts, comparison, charts }
```

---

## 3. File-by-File Specifications

### 3.1 `analytics/README.md`

**Purpose:** Directory conventions for maintainers and AI agents.

**Content:**
- Directory purpose: "Shared analytics service consumed by all pro-toolkits"
- Neighbors: `../tools/social-media/`, `../tools/ecommerce/`, `../tools/analytics/`
- Entry point: `init.php` (bootstrap on `plugins_loaded:20`)
- Context files: `.context/security-checklist.md`, `.context/tool-registry.md`
- Conventions: WPCS, immutable DTOs, singleton service, adapter pattern

---

### 3.2 `analytics/init.php`

**Purpose:** Bootstrap the analytics subsystem.

**Logic:**
```php
// ABSPATH guard
// Only load if Pro addon is active (check WP_MCP_AI_PRO_VERSION constant)
// Hook: plugins_loaded priority 20
// Load order:
//   1. All DTOs (dto/class-wp-mcp-ai-analytics-*.php)
//   2. Cache (class-wp-mcp-ai-analytics-cache.php)
//   3. Rate Limiter (class-wp-mcp-ai-analytics-rate-limiter.php)
//   4. Metric Normalizer (class-wp-mcp-ai-analytics-metric-normalizer.php)
//   5. Adapter Interface (adapters/interface-wp-mcp-ai-analytics-adapter.php)
//   6. Meta Adapter (adapters/class-wp-mcp-ai-analytics-meta-adapter.php)
//   7. Service (class-wp-mcp-ai-analytics-service.php)
// Instantiate Analytics_Service singleton
```

**Load condition:**
```php
if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
    add_action( 'plugins_loaded', 'wp_mcp_ai_analytics_init', 20 );
}
```

---

### 3.3 `dto/class-wp-mcp-ai-analytics-account-dto.php`

| Aspect | Specification |
|--------|--------------|
| **Pattern** | Immutable DTO, private constructor, `from_array()` static factory |
| **Fields** | `platform` (string), `account_id` (string), `account_name` (string), `account_type` (string), `avatar_url` (?string), `followers_count` (int), `following_count` (int), `posts_count` (int), `verified` (bool), `extra` (array) |
| **Methods** | `get_*()` for each field, `is_verified()` (bool getter for `verified`), `to_array()` |
| **Validation** | Type coercion in constructor (casts to expected types), never throws |
| **PHP 7.4 compat** | Yes — no union types, no named arguments, no readonly properties |

---

### 3.4 `dto/class-wp-mcp-ai-analytics-post-dto.php`

| Aspect | Specification |
|--------|--------------|
| **Pattern** | Immutable DTO, private constructor, `from_array()` static factory |
| **Fields** | `platform` (string), `post_id` (string), `account_id` (string), `content_type` (string), `permalink` (string), `posted_at` (string ISO 8601), `caption` (?string), `hashtags` (string[]), `mentions` (string[]), `media_urls` (string[]), `metrics` (assoc array), `extra` (array) |
| **Methods** | `get_*()` per field, `get_metric(key, default)` for individual metric access, `to_array()` |

---

### 3.5 `dto/class-wp-mcp-ai-analytics-metric-dto.php`

| Aspect | Specification |
|--------|--------------|
| **Pattern** | Immutable DTO, private constructor, `from_array()` static factory |
| **Fields** | `metric_name` (string), `metric_value` (float), `platform` (string), `account_id` (string), `period_start` (ISO 8601), `period_end` (ISO 8601), `granularity` (string: day/week/month), `previous_value` (?float), `change_pct` (?float) |
| **Methods** | `get_*()` per field, `to_array()` |

---

### 3.6 `dto/class-wp-mcp-ai-analytics-timeseries-dto.php`

| Aspect | Specification |
|--------|--------------|
| **Pattern** | Immutable DTO, private constructor, `from_array()` static factory |
| **Fields** | `label` (string), `metric_name` (string), `platform` (string), `account_id` (string), `granularity` (string), `data_points` (`Metric_DTO[]`) |
| **Methods** | `get_*()` per field, `to_array()`, `to_chartjs_dataset()` — returns Chart.js compatible `{label, data[], borderColor, ...}` |

---

### 3.7 `dto/class-wp-mcp-ai-analytics-report-dto.php`

| Aspect | Specification |
|--------|--------------|
| **Pattern** | Immutable DTO, private constructor, `from_array()` static factory |
| **Fields** | `report_id` (string), `report_type` (string: social/ecommerce/seo/cloudways/custom), `generated_at` (ISO 8601), `period` ({from, to}), `accounts` (`Account_DTO[]`), `summary` (assoc array of aggregated totals), `trends` (`TimeSeries_DTO[]`), `top_posts` (`Post_DTO[]`), `comparison` (assoc array: previous period data + change deltas), `charts` (Chart.js compatible array) |
| **Methods** | `get_*()` per field, `to_array()`, `to_json()` for REST API serialization |

---

### 3.8 `class-wp-mcp-ai-analytics-cache.php`

| Aspect | Specification |
|--------|--------------|
| **Pattern** | Singleton via `::instance()`. All methods are instance methods. |
| **Cache backend** | WordPress Transients API (`set_transient` / `get_transient` / `delete_transient`) |
| **Key strategy** | Salted keys: `wp_mcp_ai_analytics_{$platform}_{$resource}_{md5($params)}` — max 172 chars per WP transient limit |
| **TTL Configuration** | Per-data-type constant map: `ACCOUNT_TTL = HOUR_IN_SECONDS`, `METRICS_TTL = 15 * MINUTE_IN_SECONDS`, `TIMESERIES_TTL = 6 * HOUR_IN_SECONDS`, `CHART_TTL = 30 * MINUTE_IN_SECONDS` |
| **Lock pattern** | `wp_cache_add(lock_key, 1, 'analytics-locks', 30)` before warm-up to prevent race conditions |
| **Methods** | `get(platform, resource, params): ?array`, `set(platform, resource, params, data, ?ttl): bool`, `invalidate(platform, ?account_id): void`, `warm(platforms[], accounts[]): void`, `get_stats(): array` (hit rate, count, etc.) |

---

### 3.9 `class-wp-mcp-ai-analytics-rate-limiter.php`

| Aspect | Specification |
|--------|--------------|
| **Pattern** | Singleton via `::instance()` |
| **Algorithm** | Token bucket — tokens replenish at `REFILL_RATE` per second, `BUCKET_SIZE` max tokens |
| **Storage** | WP option `wp_mcp_ai_analytics_rate_{platform}` — stores `{tokens: float, last_refill: int}`. No autoload. |
| **Per-platform limits** | Twitter: 300 req/15min, Meta: 200 req/hr, LinkedIn: 100 req/day, TikTok: 50 req/hr, Default: 100 req/hr |
| **Methods** | `check(platform): bool` (returns true if allowed), `consume(platform): void`, `get_remaining(platform): int`, `get_usage_pct(platform): float` |
| **Hard block** | At 90% consumption, returns false and logs to `wp_mcp_ai_security_audit_logger` |
| **Admin reset** | `reset(platform): void` for admin overrides |

---

### 3.10 `class-wp-mcp-ai-analytics-metric-normalizer.php`

| Aspect | Specification |
|--------|--------------|
| **Pattern** | Singleton via `::instance()` |
| **Core data** | Static constant `METRIC_MAP` — `[unified_metric => [platform => native_name, ...], ...]` |
| **Computed metrics** | `engagement_rate` = `(likes + comments + shares + saves) / impressions * 100` if impressions > 0, else 0 |
| **Methods** | `normalize(platform, native_metric_name): string` (platform → unified), `denormalize(platform, unified_metric_name): string` (unified → platform), `compute_metric(unified_name, raw_values[]): float`, `get_supported_metrics(platform): string[]` |
| **Metric Map** | See Section 3.10.1 below |

#### 3.10.1 Full Metric Normalization Table

| Unified | Meta (FB/IG) | Twitter/X | LinkedIn | TikTok |
|---------|-------------|-----------|----------|--------|
| `impressions` | `impressions` | `impression_count` | `impressionCount` | `video_views` |
| `reach` | `reach` | — | `uniqueImpressionsCount` | — |
| `engagement` | `engagement` | `engagements` | `engagement` | `total_engagement` |
| `likes` | `likes` | `like_count` | `likeCount` | `digg_count` |
| `comments` | `comments` | `reply_count` | `commentCount` | `comment_count` |
| `shares` | `shares` | `retweet_count` | `shareCount` | `share_count` |
| `saves` | `saved` | `bookmark_count` | — | — |
| `followers` | `followers_count` | `followers_count` | `followerCount` | `follower_count` |
| `profile_views` | `profile_views` | — | — | `profile_views` |
| `video_views` | `video_views` | — | — | `video_views` |
| `clicks` | — | `url_link_clicks` | `clickCount` | — |
| `engagement_rate` | COMPUTED | COMPUTED | COMPUTED | COMPUTED |

---

### 3.11 `adapters/interface-wp-mcp-ai-analytics-adapter.php`

| Aspect | Specification |
|--------|--------------|
| **Interface name** | `WP_MCP_AI_Analytics_Adapter` |
| **Methods** | `get_platform(): string`, `get_account_insights(account_id, metrics[], since, until): AccountDTO[]\|WP_Error`, `get_post_insights(post_id, metrics[]): PostDTO\|WP_Error`, `get_follower_growth(account_id, since, until, granularity): MetricDTO[]\|WP_Error`, `get_top_posts(account_id, since, until, limit): PostDTO[]\|WP_Error`, `is_configured(): bool`, `get_rate_limit_remaining(): ?int` |

---

### 3.12 `adapters/class-wp-mcp-ai-analytics-meta-adapter.php`

| Aspect | Specification |
|--------|--------------|
| **Implements** | `WP_MCP_AI_Analytics_Adapter` |
| **API version** | Meta Graph API v22.0 |
| **Auth** | Reads `wp_mcp_ai_social_media_settings['facebook_access_token']` or `['instagram_access_token']` |
| **Endpoints** | `GET /{page-id}/insights?metric=page_impressions,...&since=X&until=Y`, `GET /{ig-user-id}/insights?metric=impressions,reach,...`, `GET /{ig-user-id}/media?fields=id,caption,media_type,insights.metric(...)` |
| **Normalization** | Delegates to `Metric_Normalizer::normalize('meta', $raw_name)` |
| **Error handling** | Returns `WP_Error` on auth failure, rate limit, or API error |
| **Timeout** | 20 seconds per request (matches existing `get_facebook_instagram_insights` constant) |

---

### 3.13 `class-wp-mcp-ai-analytics-service.php`

| Aspect | Specification |
|--------|--------------|
| **Pattern** | Singleton via `::instance()`, instantiated on `plugins_loaded:20` |
| **Adapter registry** | Internal `array<string, WP_MCP_AI_Analytics_Adapter>` map |
| **Public methods** | `get_social_analytics(params): ReportDTO\|WP_Error`, `get_ecommerce_analytics(params): ReportDTO\|WP_Error`, `get_seo_analytics(params): ReportDTO\|WP_Error`, `get_cloudways_analytics(params): ReportDTO\|WP_Error`, `get_custom_analytics(toolkit, params): ReportDTO\|WP_Error`, `register_adapter(platform, adapter): void`, `get_adapter(platform): ?Adapter`, `invalidate_cache(platform, ?account): void`, `get_connected_platforms(): string[]` |
| **Private helpers** | `get_summary_metrics(platforms[], from, to): array`, `get_engagement_metrics(...)`, `get_reach_metrics(...)`, `get_follower_growth(...)`, `get_top_posts(...)`, `get_comparison_period(...)`, `prepare_chart_data(report): array` |
| **Parallel fetching** | Uses `wp_remote_get` in a loop (WordPress doesn't support async IO; future optimization: `Requests::request_multiple()`) |

#### `get_social_analytics()` Parameter Schema

```php
{
    platforms: string[],           // ['instagram','twitter','linkedin','tiktok','facebook','google_business']
    accounts: string[],            // Specific account IDs to filter
    date_from: string,             // ISO 8601, default: 30 days ago
    date_to: string,               // ISO 8601, default: today
    group_by: string,              // 'day'|'week'|'month', default: 'day'
    include_sections: string[],    // Any subset of: ['summary','engagement','reach','growth',
                                   //   'top_posts','comparison','demographics','hashtags',
                                   //   'competitors','influencers']
    top_posts_count: int,          // default: 10, max: 100
    comparison_period: bool,       // Include previous period, default: false
    metrics: string[],             // Specific unified metrics to fetch
    cache_ttl_override: ?int,      // Override default cache TTL (seconds)
}
```

---

## 4. Implementation Checklist

### Phase 1: Foundation (Current)

- [ ] **F1.1** Create `addons/pro/includes/analytics/` directory
- [ ] **F1.2** Create `dto/` subdirectory
- [ ] **F1.3** Create `adapters/` subdirectory
- [ ] **F1.4** Implement `Account_DTO` (classes/dto/class-wp-mcp-ai-analytics-account-dto.php)
- [ ] **F1.5** Implement `Post_DTO` (classes/dto/class-wp-mcp-ai-analytics-post-dto.php)
- [ ] **F1.6** Implement `Metric_DTO` (classes/dto/class-wp-mcp-ai-analytics-metric-dto.php)
- [ ] **F1.7** Implement `TimeSeries_DTO` (classes/dto/class-wp-mcp-ai-analytics-timeseries-dto.php)
- [ ] **F1.8** Implement `Report_DTO` (classes/dto/class-wp-mcp-ai-analytics-report-dto.php)
- [ ] **F1.9** Implement `Analytics_Cache` (class-wp-mcp-ai-analytics-cache.php)
- [ ] **F1.10** Implement `Analytics_Rate_Limiter` (class-wp-mcp-ai-analytics-rate-limiter.php)
- [ ] **F1.11** Implement `Metric_Normalizer` (class-wp-mcp-ai-analytics-metric-normalizer.php)
- [ ] **F1.12** Implement `Analytics_Adapter` interface (adapters/interface-wp-mcp-ai-analytics-adapter.php)
- [ ] **F1.13** Implement `Meta_Adapter` (adapters/class-wp-mcp-ai-analytics-meta-adapter.php)
- [ ] **F1.14** Implement `Analytics_Service` (class-wp-mcp-ai-analytics-service.php)
- [ ] **F1.15** Implement `init.php` bootstrap
- [ ] **F1.16** Create `README.md`

### Phase 2: Validation (After All Files Created)

- [ ] **V2.1** Run `composer install` (if not already done)
- [ ] **V2.2** Run `php -l` syntax check on every new file
- [ ] **V2.3** Run `composer run lint:errors-only` on new analytics directory
- [ ] **V2.4** Run `composer run lint` for full WPCS check
- [ ] **V2.5** Run `composer run lint:compat` for PHP 7.4-8.3 compatibility
- [ ] **V2.6** Fix all errors and warnings
- [ ] **V2.7** Verify zero breaking changes to existing tools
- [ ] **V2.8** Verify all PHPDoc blocks are complete

---

## 5. Validation & Testing

### 5.1 Syntax Validation

```bash
# Check every new file for parse errors
for f in addons/pro/includes/analytics/**/*.php; do
    php -l "$f"
done
```

### 5.2 PHPCS Linting

```bash
# Errors only first (fast feedback)
composer run lint:errors-only

# Full WPCS (may have pre-existing warnings)
composer run lint

# PHP compatibility check
composer run lint:compat
```

### 5.3 Manual Verification

| Check | Method |
|-------|--------|
| No fatal errors on WordPress load | Load admin page with `WP_DEBUG=true` |
| All classes autoloaded | `class_exists('WP_MCP_AI_Analytics_Service')` returns true |
| DTO immutability | Attempt to set a property → PHP error (private) |
| Cache CRUD | `Analytics_Cache::instance()->set(...); get(...)` |
| Rate limiter | `check() → true`, consume 10x, `check() → false` at bucket exhaustion |
| Normalizer | `normalize('meta', 'page_impressions') → 'impressions'` |

---

## 6. Code Quality Gate

All files must pass these criteria before being considered complete:

### 6.1 Required in Every File

- [x] `ABSPATH` guard as first executable line
- [x] File-level PHPDoc with `@package WP_MCP_AI_Pro` and `@since 1.7.0`
- [x] Class-level PHPDoc with `@since 1.7.0`
- [x] Method-level PHPDoc with `@since`, `@param`, `@return`
- [x] Property-level PHPDoc with `@since` and `@var`
- [x] No `@access` tags (not used in WPCS)

### 6.2 Naming Conventions

- Classes: `WP_MCP_AI_Analytics_{Name}` (snake_case with prefix)
- Methods: `snake_case` (WPCS standard)
- Variables: `$snake_case`
- Constants: `UPPER_SNAKE_CASE`
- File names: `class-wp-mcp-ai-analytics-{name}.php`

### 6.3 Security

- No user input without `sanitize_text_field()` or `absint()`
- No output without `esc_html()`, `esc_url()`, etc.
- API credentials read from WP options, never hardcoded
- Cache keys use `md5()` for long parameters
- Rate limiter counters not autoloaded

### 6.4 i18n

- All user-facing strings use `__()`, `_e()`, `esc_html__()`, `esc_attr__()`
- Text domain: `mcp-ai-wpoos-pro`
- No variable text domains
- No concatenation inside translation functions

---
*End of Implementation Plan*
