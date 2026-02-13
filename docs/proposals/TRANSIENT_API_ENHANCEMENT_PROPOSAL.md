# Transient API Enhancement Proposal

**Status:** Draft  
**Created:** 2026-02-12  
**Author:** GitHub Copilot Agent

## Executive Summary

This proposal outlines enhancements to the plugin's use of the WordPress Transient API based on best practices and current implementation analysis. The primary goal is to reduce external API calls, improve performance, and protect against rate limits for OpenAI, Gemini, and Ollama integrations.

## Current State Analysis

### What's Working Well

1. **Existing Cache Helper (`class-wp-mcp-ai-cache-helper.php`)**
   - Excellent foundation with both transient and object cache support
   - Well-implemented cache stampede protection via `remember_with_lock()`
   - Good TTL constants for different data types
   - Comprehensive invalidation methods

2. **HuggingFace Datasets Client**
   - Already implements proper transient caching
   - Uses MD5 hashing for cache keys based on request parameters
   - Configurable TTL (default 3600s)
   - Good pattern for caching external API responses

3. **General Transient Usage**
   - Extensive use for OAuth state management
   - Good use for async job tracking
   - Proper use for rate limiting

### Critical Gaps

1. **No Caching for Primary AI Providers**
   - **OpenAI Client**: Direct API calls without response caching
   - **Gemini Client**: Direct API calls without response caching  
   - **Ollama Client**: Direct API calls without response caching

2. **Cacheable Operations Not Cached**
   - Model list queries (rarely change)
   - Token counting operations (deterministic for same input)
   - Embedding generation (deterministic for same input and model)
   - Model capability checks

3. **Missing Cache Instrumentation**
   - No hit/miss ratio tracking
   - No cache performance monitoring
   - Limited visibility into cache effectiveness

## Best Practices from WordPress Community

Based on standard WordPress transient API best practices:

### 1. Cache External API Responses
- **Benefit**: Reduces latency and protects against rate limits
- **Implementation**: Cache API responses with appropriate TTLs
- **TTL Recommendations**:
  - Model lists: 12 hours (rarely change)
  - Embeddings: 24 hours (deterministic)
  - Token counts: 1 hour (deterministic but may change with model updates)
  - Chat completions: DO NOT cache (non-deterministic, user-specific)

### 2. Use Unique, Namespaced Keys
- **Current**: Good - using `wp_mcp_ai_` prefix
- **Enhancement**: Include request parameters in cache keys using MD5 hashing

### 3. Implement Graceful Degradation
- **Pattern**: Cache-aside with fallback
- **Implementation**: If cache fails, make API call and continue

### 4. Cache Stampede Protection
- **Current**: Implemented in `Cache_Helper::remember_with_lock()`
- **Enhancement**: Apply to all expensive API operations

### 5. Monitor and Track Performance
- **Current**: Limited monitoring
- **Enhancement**: Add cache hit/miss tracking and admin dashboard

## Proposed Enhancements

### Phase 1: Core Caching Infrastructure (Priority: HIGH)

#### 1.1 Add Caching to OpenAI Client

**Operations to Cache:**
- ✅ Model lists (`/v1/models`) - TTL: 12 hours
- ✅ File metadata queries - TTL: 1 hour
- ✅ Batch status queries (until complete) - TTL: 30 seconds
- ❌ Chat completions (non-deterministic)
- ❌ Image generation (non-deterministic)

**Implementation:**
```php
// Example: Cache model list
public function list_models() {
    $cache_key = 'openai_models_list';
    
    return WP_MCP_AI_Cache_Helper::remember(
        $cache_key,
        function() {
            // Existing API call code
        },
        12 * HOUR_IN_SECONDS
    );
}
```

#### 1.2 Add Caching to Gemini Client

**Operations to Cache:**
- ✅ Model lists - TTL: 12 hours
- ✅ Token counting (same input/model) - TTL: 1 hour
- ✅ Embeddings (same input/model) - TTL: 24 hours
- ❌ Chat completions (non-deterministic)

**Implementation:**
```php
// Example: Cache token counting
public function count_tokens( $content, $model ) {
    $cache_key = 'gemini_tokens_' . md5( $model . wp_json_encode( $content ) );
    
    return WP_MCP_AI_Cache_Helper::remember(
        $cache_key,
        function() use ( $content, $model ) {
            // Existing API call code
        },
        HOUR_IN_SECONDS
    );
}
```

#### 1.3 Add Caching to Ollama Client

**Operations to Cache:**
- ✅ Model lists - TTL: 5 minutes (local, fast, but still network call)
- ✅ Embeddings (same input/model) - TTL: 24 hours
- ❌ Chat completions (non-deterministic)

### Phase 2: Settings & Configuration (Priority: MEDIUM)

#### 2.1 Add Cache Configuration Settings

Add new settings to **Settings → NV oOS → Performance**:

```php
// New settings
'enable_api_response_caching' => true/false (default: true)
'openai_model_list_cache_ttl' => 12 * HOUR_IN_SECONDS
'gemini_model_list_cache_ttl' => 12 * HOUR_IN_SECONDS
'ollama_model_list_cache_ttl' => 5 * MINUTE_IN_SECONDS
'embedding_cache_ttl' => 24 * HOUR_IN_SECONDS
'token_count_cache_ttl' => HOUR_IN_SECONDS
```

#### 2.2 Add Cache Invalidation Controls

Add admin action to clear API caches:
- Clear all AI provider caches
- Clear specific provider caches
- Clear by cache type (models, embeddings, token counts)

### Phase 3: Monitoring & Analytics (Priority: LOW)

#### 3.1 Add Cache Performance Tracking

Implement hit/miss tracking:

```php
class WP_MCP_AI_Cache_Stats {
    public static function record_hit( $cache_type ) {
        $stats = get_option( 'wp_mcp_ai_cache_stats', array() );
        $stats[ $cache_type ]['hits'] = ( $stats[ $cache_type ]['hits'] ?? 0 ) + 1;
        update_option( 'wp_mcp_ai_cache_stats', $stats );
    }
    
    public static function record_miss( $cache_type ) {
        $stats = get_option( 'wp_mcp_ai_cache_stats', array() );
        $stats[ $cache_type ]['misses'] = ( $stats[ $cache_type ]['misses'] ?? 0 ) + 1;
        update_option( 'wp_mcp_ai_cache_stats', $stats );
    }
    
    public static function get_hit_rate( $cache_type ) {
        $stats = get_option( 'wp_mcp_ai_cache_stats', array() );
        $hits = $stats[ $cache_type ]['hits'] ?? 0;
        $misses = $stats[ $cache_type ]['misses'] ?? 0;
        $total = $hits + $misses;
        
        return $total > 0 ? ( $hits / $total ) * 100 : 0;
    }
}
```

#### 3.2 Add Cache Dashboard Widget

Add widget to admin dashboard showing:
- Cache hit/miss ratios by provider
- Total cached items count
- Cache size (estimated)
- Quick actions (clear cache, warm cache)

### Phase 4: Advanced Optimizations (Priority: FUTURE)

#### 4.1 Implement Cache Warming

Pre-populate frequently accessed data:
- Model lists on plugin activation
- Common assistant configurations
- Default tool definitions

#### 4.2 Implement Probabilistic Early Expiration

Prevent cache stampede on high-traffic sites:
- Add β parameter (0.5-2.0) for early expiration
- Formula: `expires_at - (time() - created_at) * β * log(random())`
- Refresh cache in background before expiration

#### 4.3 Add Stale-While-Revalidate Pattern

For non-critical data:
- Serve stale cache immediately
- Refresh in background via cron
- Update cache asynchronously

## Implementation Plan

### Week 1: Core Caching (Phase 1)
- Day 1-2: Implement OpenAI Client caching
- Day 3-4: Implement Gemini Client caching
- Day 5: Implement Ollama Client caching

### Week 2: Settings & Testing (Phase 2)
- Day 1-2: Add cache configuration settings
- Day 3-4: Add cache invalidation controls
- Day 5: Comprehensive testing

### Week 3: Monitoring (Phase 3 - Optional)
- Day 1-2: Implement cache statistics tracking
- Day 3-4: Add cache dashboard widget
- Day 5: Documentation updates

### Week 4: Advanced Features (Phase 4 - Optional)
- To be determined based on performance data

## Success Metrics

### Primary Metrics
1. **API Call Reduction**: Target 40-60% reduction in external API calls for model lists
2. **Response Time Improvement**: Target 50-80% faster response for cached operations
3. **Rate Limit Protection**: Zero rate limit errors for model list queries

### Secondary Metrics
1. **Cache Hit Rate**: Target >70% for model lists within 24 hours of first query
2. **Cache Size**: Monitor transient table size (should be <1MB additional)
3. **User Experience**: Faster assistant loading, faster Elementor widget rendering

## Risks & Mitigation

### Risk 1: Stale Cache Data
- **Impact**: Users see outdated model lists
- **Mitigation**: 
  - Implement manual cache refresh button
  - Set reasonable TTLs (12 hours for model lists)
  - Add cache version to invalidate on plugin updates

### Risk 2: Cache Storage Growth
- **Impact**: Database bloat from cached data
- **Mitigation**:
  - Use appropriate TTLs for auto-cleanup
  - Implement cache size limits
  - Add cleanup cron job for orphaned transients

### Risk 3: Deterministic Assumption Errors
- **Impact**: Caching non-deterministic operations
- **Mitigation**:
  - Never cache chat completions
  - Never cache image generations
  - Only cache true deterministic operations
  - Add opt-out mechanism via filter hooks

### Risk 4: Cache Key Collisions
- **Impact**: Wrong data returned for requests
- **Mitigation**:
  - Use MD5 hashing of full request parameters
  - Include model name in cache key
  - Namespace all keys with provider prefix

## Configuration & Filters

### Constants
```php
// Disable all API response caching
define( 'WP_MCP_AI_DISABLE_API_CACHE', true );

// Override specific TTLs
define( 'WP_MCP_AI_MODEL_LIST_TTL', 6 * HOUR_IN_SECONDS );
```

### Filters
```php
// Customize cache behavior
add_filter( 'wp_mcp_ai_cache_api_responses', '__return_true' );
add_filter( 'wp_mcp_ai_openai_model_list_ttl', function( $ttl ) {
    return 24 * HOUR_IN_SECONDS;
});

// Bypass cache for specific requests
add_filter( 'wp_mcp_ai_should_cache_request', function( $should_cache, $provider, $operation ) {
    if ( 'openai' === $provider && 'models' === $operation ) {
        return false; // Force fresh data
    }
    return $should_cache;
}, 10, 3 );
```

## Testing Strategy

### Unit Tests
- Test cache key generation
- Test TTL application
- Test cache invalidation
- Test fallback behavior when cache unavailable

### Integration Tests
- Test OpenAI client caching
- Test Gemini client caching
- Test Ollama client caching
- Test cache helper integration

### Performance Tests
- Measure response time before/after caching
- Measure API call reduction
- Measure cache hit rates under load

### Manual Testing
- Test admin settings interface
- Test cache invalidation buttons
- Test cache dashboard display
- Test behavior with WP_DEBUG enabled

## Documentation Updates

### Files to Update
1. **docs/tool-reference.md** - Document caching behavior for tools
2. **docs/rest-api.md** - Document caching for REST endpoints
3. **docs/BEST_PRACTICES.md** - Add caching best practices
4. **README.md** - Mention performance improvements
5. **CHANGELOG.md** - Document new features

### New Documentation
1. **docs/CACHING.md** - Comprehensive caching guide
2. **docs/PERFORMANCE.md** - Performance optimization guide

## Rollback Plan

If issues arise:

1. **Immediate Rollback**: Set `define( 'WP_MCP_AI_DISABLE_API_CACHE', true )`
2. **Partial Rollback**: Disable specific provider caching via settings
3. **Cache Clear**: Admin can clear all caches via settings page
4. **Code Rollback**: Revert commits, redeploy previous version

## Future Considerations

### Object Cache Integration
- Already supported via `WP_MCP_AI_Cache_Helper`
- Automatically uses Redis/Memcached if available
- No additional work needed

### CDN/Edge Caching
- Not applicable (API responses are user/site-specific)
- Consider for public assistant data in future

### Cross-Site Caching (Multisite)
- Use `get_site_transient()` for network-wide model lists
- Reduce redundant API calls across multisite network

## Conclusion

Implementing transient caching for AI provider API calls will significantly improve plugin performance, reduce external API dependencies, and provide better user experience. The proposed phased approach allows incremental implementation with measurable improvements at each stage.

**Recommendation**: Proceed with Phase 1 (Core Caching) immediately as it provides the highest value with minimal risk.
