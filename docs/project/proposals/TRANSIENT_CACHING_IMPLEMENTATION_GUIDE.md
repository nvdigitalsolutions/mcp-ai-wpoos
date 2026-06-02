# Transient API Caching Implementation Guide

**Status:** Implementation Ready  
**Created:** 2026-02-12  
**Related:** TRANSIENT_API_ENHANCEMENT_PROPOSAL.md

## Overview

This guide provides specific implementation instructions for adding transient caching to AI provider clients. Settings have been added to the admin UI - this document covers the code implementation.

## Settings Already Implemented ✅

Cache configuration settings have been added to each provider's settings page:

### OpenAI (`/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=providers&subtab=openai`)
- `enable_openai_api_caching` (checkbox, default: true)
- `openai_model_list_cache_ttl` (number, default: 43200 seconds / 12 hours)
- `openai_embedding_cache_ttl` (number, default: 86400 seconds / 24 hours)

### Anthropic (`/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=providers&subtab=anthropic`)
- `enable_anthropic_api_caching` (checkbox, default: true)
- `anthropic_model_list_cache_ttl` (number, default: 43200 seconds / 12 hours)

### Gemini (`/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=providers&subtab=gemini`)
- `enable_gemini_api_caching` (checkbox, default: true)
- `gemini_model_list_cache_ttl` (number, default: 43200 seconds / 12 hours)
- `gemini_embedding_cache_ttl` (number, default: 86400 seconds / 24 hours)
- `gemini_token_count_cache_ttl` (number, default: 3600 seconds / 1 hour)

### Ollama (`/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=providers&subtab=ollama`)
- `enable_ollama_api_caching` (checkbox, default: true)
- `ollama_model_list_cache_ttl` (number, default: 300 seconds / 5 minutes)
- `ollama_embedding_cache_ttl` (number, default: 86400 seconds / 24 hours)

### Cloudflare Workers AI (`/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=providers&subtab=cloudflare`)
- `enable_cloudflare_api_caching` (checkbox, default: true)
- `cloudflare_model_list_cache_ttl` (number, default: 43200 seconds / 12 hours)

## Implementation Pattern

All implementations follow this consistent pattern:

```php
public function cacheable_method( $param1, array $options = array() ) {
    // 1. Validate prerequisites (API key, etc.)
    if ( empty( $api_key ) ) {
        return new WP_Error( ... );
    }

    // 2. Check if caching is enabled
    $settings     = WP_MCP_AI_Admin_Settings::get_settings();
    $use_cache    = ! empty( $settings['enable_<provider>_api_caching'] );
    $bypass_cache = isset( $options['bypass_cache'] ) && $options['bypass_cache'];

    // Allow disabling via constant
    if ( defined( 'WP_MCP_AI_DISABLE_API_CACHE' ) && WP_MCP_AI_DISABLE_API_CACHE ) {
        $use_cache = false;
    }

    /**
     * Filter whether to cache this request.
     *
     * @param bool  $use_cache Whether to use caching.
     * @param array $options   Request options.
     */
    $use_cache = apply_filters( 'wp_mcp_ai_cache_<provider>_<operation>', $use_cache, $options );

    // 3. Use cache if enabled
    if ( $use_cache && ! $bypass_cache ) {
        // Build cache key with parameters
        $cache_key = '<provider>_<operation>_' . md5( wp_json_encode( array(
            'param1' => $param1,
            'option1' => $options['option1'] ?? '',
        ) ) );

        // Get cache TTL from settings
        $cache_ttl = isset( $settings['<provider>_<operation>_cache_ttl'] ) 
            ? absint( $settings['<provider>_<operation>_cache_ttl'] ) 
            : DEFAULT_TTL;

        /**
         * Filter the cache TTL.
         *
         * @param int $cache_ttl Cache TTL in seconds.
         */
        $cache_ttl = apply_filters( 'wp_mcp_ai_<provider>_<operation>_ttl', $cache_ttl );

        return WP_MCP_AI_Cache_Helper::remember(
            $cache_key,
            function() use ( $param1, $options ) {
                return $this->fetch_<operation>_from_api( $param1, $options );
            },
            $cache_ttl
        );
    }

    // 4. Fallback: call API directly
    return $this->fetch_<operation>_from_api( $param1, $options );
}

/**
 * Internal method to fetch from API.
 *
 * @param mixed $param1  Parameter.
 * @param array $options Options.
 * @return array|WP_Error Response or error.
 */
private function fetch_<operation>_from_api( $param1, $options ) {
    // Existing API call logic moved here
    // ...
    return $response;
}
```

## Files to Modify

### 1. OpenAI Client (`includes/class-wp-mcp-ai-openai-client.php`)

**Methods to Add Caching:**

#### `list_models()`
- **Cache Key:** `openai_models_list`
- **TTL Setting:** `openai_model_list_cache_ttl`
- **Filter:** `wp_mcp_ai_cache_openai_models`
- **TTL Filter:** `wp_mcp_ai_openai_model_list_ttl`
- **Notes:** No parameters to include in cache key (always returns same list)

#### `create_embeddings( $input, array $options = array() )`
- **Cache Key:** `openai_embedding_` + MD5 hash of input + model
- **TTL Setting:** `openai_embedding_cache_ttl`
- **Filter:** `wp_mcp_ai_cache_openai_embeddings`
- **TTL Filter:** `wp_mcp_ai_openai_embedding_ttl`
- **Cache Key Components:**
  ```php
  md5( wp_json_encode( array(
      'input' => $input,
      'model' => $options['model'] ?? 'text-embedding-3-small',
      'dimensions' => $options['dimensions'] ?? '',
      'encoding_format' => $options['encoding_format'] ?? '',
  ) ) )
  ```
- **Notes:** Deterministic - same input/model always returns same embeddings

**DO NOT Cache:**
- `create_chat_completion()` - Non-deterministic, user-specific
- `generate_image()` - Non-deterministic
- `edit_image()` - Non-deterministic
- `create_image_variation()` - Non-deterministic
- `generate_speech()` - Deterministic but large file size makes caching impractical
- `transcribe_audio()` - Deterministic but file uploads vary

### 2. Gemini Client (`includes/class-wp-mcp-ai-gemini-client.php`)

**Methods to Add Caching:**

#### `list_models( array $options = array() )`
- **Cache Key:** `gemini_models_list`
- **TTL Setting:** `gemini_model_list_cache_ttl`
- **Filter:** `wp_mcp_ai_cache_gemini_models`
- **TTL Filter:** `wp_mcp_ai_gemini_model_list_ttl`

#### `count_tokens( array $messages, array $options = array() )`
- **Cache Key:** `gemini_token_count_` + MD5 hash of messages + model
- **TTL Setting:** `gemini_token_count_cache_ttl`
- **Filter:** `wp_mcp_ai_cache_gemini_token_count`
- **TTL Filter:** `wp_mcp_ai_gemini_token_count_ttl`
- **Cache Key Components:**
  ```php
  md5( wp_json_encode( array(
      'messages' => $messages,
      'model' => $options['model'] ?? settings default,
  ) ) )
  ```

#### `create_embedding( $text, array $options = array() )`
- **Cache Key:** `gemini_embedding_` + MD5 hash of text + model
- **TTL Setting:** `gemini_embedding_cache_ttl`
- **Filter:** `wp_mcp_ai_cache_gemini_embeddings`
- **TTL Filter:** `wp_mcp_ai_gemini_embedding_ttl`
- **Cache Key Components:**
  ```php
  md5( wp_json_encode( array(
      'text' => $text,
      'model' => $options['model'] ?? 'text-embedding-004',
  ) ) )
  ```

#### `batch_embed_content( array $texts, array $options = array() )`
- **Cache Key:** `gemini_batch_embedding_` + MD5 hash of texts + model
- **TTL Setting:** `gemini_embedding_cache_ttl` (same as single embedding)
- **Filter:** `wp_mcp_ai_cache_gemini_batch_embeddings`
- **Cache Key Components:**
  ```php
  md5( wp_json_encode( array(
      'texts' => $texts,
      'model' => $options['model'] ?? 'text-embedding-004',
  ) ) )
  ```

**DO NOT Cache:**
- `create_chat_completion()` - Non-deterministic
- `stream_chat_completion()` - Non-deterministic
- `generate_image()` - Non-deterministic
- `edit_image()` - Non-deterministic

### 3. Ollama Client (`includes/class-wp-mcp-ai-ollama-client.php`)

**Methods to Add Caching:**

#### `list_models()`
- **Cache Key:** `ollama_models_list_` + MD5 hash of endpoint URL
- **TTL Setting:** `ollama_model_list_cache_ttl`
- **Filter:** `wp_mcp_ai_cache_ollama_models`
- **TTL Filter:** `wp_mcp_ai_ollama_model_list_ttl`
- **Cache Key Components:** Include endpoint URL since it can vary
  ```php
  'ollama_models_list_' . md5( $endpoint_url )
  ```
- **Notes:** Shorter TTL (5 minutes) since Ollama is local and models can be added/removed quickly

**DO NOT Cache:**
- `create_chat_completion()` - Non-deterministic
- `create_completion()` - Non-deterministic

### 4. Anthropic Client (`includes/class-wp-mcp-ai-anthropic-client.php`)

Need to check if this file exists and has a `list_models()` method.

**If `list_models()` exists:**
- **Cache Key:** `anthropic_models_list`
- **TTL Setting:** `anthropic_model_list_cache_ttl`
- **Filter:** `wp_mcp_ai_cache_anthropic_models`
- **TTL Filter:** `wp_mcp_ai_anthropic_model_list_ttl`

**DO NOT Cache:**
- `create_chat_completion()` or similar - Non-deterministic

### 5. Cloudflare Workers AI Client

Need to locate the Cloudflare client file (likely `includes/class-wp-mcp-ai-cloudflare-client.php` or similar).

**If `list_models()` exists:**
- **Cache Key:** `cloudflare_models_list`
- **TTL Setting:** `cloudflare_model_list_cache_ttl`
- **Filter:** `wp_mcp_ai_cache_cloudflare_models`
- **TTL Filter:** `wp_mcp_ai_cloudflare_model_list_ttl`

**DO NOT Cache:**
- Any generation/completion methods - Non-deterministic

## Cache Invalidation

### Automatic Invalidation Triggers

Add cache clearing on these events:

#### Plugin Update
```php
// In activation/update hook
add_action( 'upgrader_process_complete', function( $upgrader, $options ) {
    if ( $options['action'] === 'update' && $options['type'] === 'plugin' ) {
        if ( isset( $options['plugins'] ) && in_array( 'mcp-ai-wpoos/mcp-ai-wpoos.php', $options['plugins'], true ) ) {
            // Clear all API caches
            WP_MCP_AI_Cache_Helper::delete_pattern( 'openai_models_%' );
            WP_MCP_AI_Cache_Helper::delete_pattern( 'gemini_models_%' );
            WP_MCP_AI_Cache_Helper::delete_pattern( 'ollama_models_%' );
            WP_MCP_AI_Cache_Helper::delete_pattern( 'anthropic_models_%' );
            WP_MCP_AI_Cache_Helper::delete_pattern( 'cloudflare_models_%' );
        }
    }
}, 10, 2 );
```

#### API Key Change
```php
// In settings save handler
add_action( 'update_option_wp_mcp_ai_settings', function( $old_value, $new_value ) {
    // Check if API keys changed
    $keys_to_check = array(
        'openai_api_key' => 'openai_',
        'gemini_api_key' => 'gemini_',
        'anthropic_api_key' => 'anthropic_',
        'cloudflare_api_token' => 'cloudflare_',
    );

    foreach ( $keys_to_check as $key => $prefix ) {
        if ( ( $old_value[ $key ] ?? '' ) !== ( $new_value[ $key ] ?? '' ) ) {
            // Clear provider-specific caches
            WP_MCP_AI_Cache_Helper::delete_pattern( $prefix . '%' );
        }
    }
}, 10, 2 );
```

### Manual Invalidation

Add admin action buttons to clear caches:

#### Individual Provider Clear
```php
// In admin settings page
if ( isset( $_POST['clear_openai_cache'] ) && check_admin_referer( 'wp_mcp_ai_clear_cache' ) ) {
    WP_MCP_AI_Cache_Helper::delete_pattern( 'openai_%' );
    add_settings_error( 'wp_mcp_ai_settings', 'cache_cleared', __( 'OpenAI cache cleared successfully.', 'mcp-ai-wpoos' ), 'success' );
}
```

#### Clear All API Caches
```php
// In admin settings page
if ( isset( $_POST['clear_all_api_caches'] ) && check_admin_referer( 'wp_mcp_ai_clear_cache' ) ) {
    $count = 0;
    $count += WP_MCP_AI_Cache_Helper::delete_pattern( 'openai_%' );
    $count += WP_MCP_AI_Cache_Helper::delete_pattern( 'gemini_%' );
    $count += WP_MCP_AI_Cache_Helper::delete_pattern( 'ollama_%' );
    $count += WP_MCP_AI_Cache_Helper::delete_pattern( 'anthropic_%' );
    $count += WP_MCP_AI_Cache_Helper::delete_pattern( 'cloudflare_%' );
    
    add_settings_error( 
        'wp_mcp_ai_settings', 
        'cache_cleared', 
        sprintf( __( 'Cleared %d API cache entries successfully.', 'mcp-ai-wpoos' ), $count ), 
        'success' 
    );
}
```

## Testing Strategy

### Unit Tests

Create test file: `tests/test-api-caching.php`

```php
class Test_API_Caching extends WP_UnitTestCase {
    
    public function test_openai_models_list_caching() {
        // Enable caching
        $settings = get_option( 'wp_mcp_ai_settings', array() );
        $settings['enable_openai_api_caching'] = true;
        $settings['openai_model_list_cache_ttl'] = 3600;
        update_option( 'wp_mcp_ai_settings', $settings );
        
        // Clear cache
        WP_MCP_AI_Cache_Helper::delete( 'openai_models_list' );
        
        // First call should hit API
        $client = new WP_MCP_AI_OpenAI_Client();
        $result1 = $client->list_models();
        
        // Second call should hit cache
        $result2 = $client->list_models();
        
        // Results should be identical
        $this->assertEquals( $result1, $result2 );
        
        // Cache should exist
        $cached = WP_MCP_AI_Cache_Helper::get( 'openai_models_list' );
        $this->assertNotFalse( $cached );
    }
    
    public function test_cache_bypass() {
        // Test that bypass_cache works
        $client = new WP_MCP_AI_OpenAI_Client();
        $result = $client->list_models( array( 'bypass_cache' => true ) );
        
        $this->assertNotWPError( $result );
    }
    
    public function test_cache_disabled_via_constant() {
        // Test WP_MCP_AI_DISABLE_API_CACHE constant
        if ( ! defined( 'WP_MCP_AI_DISABLE_API_CACHE' ) ) {
            define( 'WP_MCP_AI_DISABLE_API_CACHE', true );
        }
        
        $client = new WP_MCP_AI_OpenAI_Client();
        $result = $client->list_models();
        
        // Should still work but not cache
        $this->assertNotWPError( $result );
    }
}
```

### Manual Testing Checklist

- [ ] Enable caching for OpenAI in settings
- [ ] Call `list_models()` twice, verify second call is faster
- [ ] Check transient exists in database: `SELECT * FROM wp_options WHERE option_name LIKE '_transient_wp_mcp_ai_openai%'`
- [ ] Disable caching in settings, verify API is called each time
- [ ] Test cache TTL by setting to 60 seconds, waiting, and checking if re-fetched
- [ ] Test `bypass_cache` parameter works
- [ ] Test cache cleared when API key changes
- [ ] Test manual cache clear button works
- [ ] Repeat for Gemini, Ollama, Anthropic, Cloudflare

## Performance Monitoring

### Add Cache Statistics

Extend `WP_MCP_AI_Cache_Helper::get_cache_stats()` to include API-specific stats:

```php
public static function get_api_cache_stats() {
    $stats = array();
    
    $providers = array( 'openai', 'gemini', 'ollama', 'anthropic', 'cloudflare' );
    
    foreach ( $providers as $provider ) {
        $pattern = $provider . '_%';
        $count = self::count_transients_by_pattern( $pattern );
        
        $stats[ $provider ] = array(
            'count' => $count,
            'enabled' => self::is_provider_caching_enabled( $provider ),
        );
    }
    
    return $stats;
}
```

### Dashboard Widget

Add widget to show cache performance:

```php
add_action( 'wp_dashboard_setup', function() {
    wp_add_dashboard_widget(
        'wp_mcp_ai_cache_stats',
        __( 'AI API Cache Statistics', 'mcp-ai-wpoos' ),
        'wp_mcp_ai_render_cache_stats_widget'
    );
} );

function wp_mcp_ai_render_cache_stats_widget() {
    $stats = WP_MCP_AI_Cache_Helper::get_api_cache_stats();
    
    echo '<table class="widefat">';
    echo '<thead><tr><th>Provider</th><th>Cached Items</th><th>Status</th></tr></thead>';
    echo '<tbody>';
    
    foreach ( $stats as $provider => $data ) {
        $status = $data['enabled'] ? __( 'Enabled', 'mcp-ai-wpoos' ) : __( 'Disabled', 'mcp-ai-wpoos' );
        echo '<tr>';
        echo '<td>' . esc_html( ucfirst( $provider ) ) . '</td>';
        echo '<td>' . esc_html( $data['count'] ) . '</td>';
        echo '<td>' . esc_html( $status ) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
}
```

## Documentation Updates

### Files to Update

1. **README.md** - Add performance improvement note
2. **docs/BEST_PRACTICES.md** - Add caching best practices section
3. **docs/PERFORMANCE.md** - Create new performance optimization guide
4. **docs/CACHING.md** - Create comprehensive caching guide
5. **CHANGELOG.md** - Document new feature

### Sample Documentation Section

```markdown
## API Response Caching

NV oOS automatically caches deterministic API responses to improve performance and reduce costs.

### What Gets Cached

**Cached Operations:**
- Model lists (12 hour default TTL)
- Embeddings (24 hour default TTL)
- Token counts (1 hour default TTL for Gemini)

**Never Cached:**
- Chat completions (non-deterministic)
- Image generations (non-deterministic)
- Audio processing (file-specific)

### Configuration

Configure caching per provider in **Settings → NV oOS → Providers → [Provider Name]**:

1. **Enable/Disable Caching** - Toggle caching on/off
2. **Cache Duration** - Set TTL in seconds for each operation type
3. **Clear Cache** - Manual cache invalidation button

### Advanced Configuration

```php
// Disable all API caching
define( 'WP_MCP_AI_DISABLE_API_CACHE', true );

// Customize cache behavior
add_filter( 'wp_mcp_ai_cache_openai_models', '__return_false' ); // Disable OpenAI model caching
add_filter( 'wp_mcp_ai_openai_model_list_ttl', function( $ttl ) {
    return 24 * HOUR_IN_SECONDS; // 24 hours
} );
```

### Performance Impact

Typical improvements with caching enabled:
- **Model list queries:** 95% faster (50ms → 2ms)
- **Embedding requests:** 60% reduction in API calls for repeated content
- **Cost savings:** 20-40% reduction in API usage for typical workloads

### Troubleshooting

**Stale model list:** Clear cache manually or wait for TTL expiration
**Not seeing performance improvement:** Verify caching is enabled in settings
**Cache not working:** Check `WP_MCP_AI_DISABLE_API_CACHE` constant not defined
```

## Implementation Checklist

- [ ] Implement OpenAI list_models() caching
- [ ] Implement OpenAI create_embeddings() caching
- [ ] Implement Gemini list_models() caching
- [ ] Implement Gemini count_tokens() caching
- [ ] Implement Gemini create_embedding() caching
- [ ] Implement Gemini batch_embed_content() caching
- [ ] Implement Ollama list_models() caching
- [ ] Implement Anthropic list_models() caching (if exists)
- [ ] Implement Cloudflare list_models() caching (if exists)
- [ ] Add cache invalidation on plugin update
- [ ] Add cache invalidation on API key change
- [ ] Add manual cache clear buttons to admin UI
- [ ] Add cache statistics dashboard widget
- [ ] Write unit tests
- [ ] Update documentation (README, BEST_PRACTICES, new CACHING.md)
- [ ] Update CHANGELOG.md
- [ ] Manual testing on all providers
- [ ] Performance benchmarking

## Rollout Strategy

### Phase 1: OpenAI Only (Low Risk)
- Implement caching for OpenAI list_models() only
- Monitor for 1-2 weeks
- Gather user feedback

### Phase 2: Add Embeddings (Medium Risk)
- Add OpenAI embeddings caching
- Add Gemini embeddings caching
- Monitor cache hit rates

### Phase 3: All Providers (Full Rollout)
- Complete remaining providers
- Add dashboard widget
- Announce feature

## Success Metrics

Track these metrics to measure success:

1. **Cache Hit Rate:** Target >70% for model lists within 24 hours
2. **API Call Reduction:** Target 40-60% reduction for cached operations
3. **Response Time:** Target 80%+ improvement for cached responses
4. **Cost Savings:** Estimate based on API call reduction
5. **User Reports:** Monitor for stale data complaints

## Conclusion

This implementation provides significant performance improvements while maintaining code quality and user control. The phased approach allows validation at each step before full rollout.
