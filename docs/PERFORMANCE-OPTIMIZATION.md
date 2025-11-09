# Performance Optimization Guide

This document describes the performance optimizations implemented in WP Open Operator System (WP oOS) as part of Phase 1: Performance Optimization.

## Table of Contents

- [Caching System](#caching-system)
- [Database Query Optimization](#database-query-optimization)
- [Asset Loading Strategy](#asset-loading-strategy)
- [Configuration](#configuration)
- [Best Practices](#best-practices)

## Caching System

### Overview

WP oOS implements a centralized caching system using the WordPress Transients API. This provides consistent, performant caching across all plugin components.

### Cache Helper Class

The `WP_MCP_AI_Cache_Helper` class provides all caching functionality:

```php
// Get cached value
$value = WP_MCP_AI_Cache_Helper::get( 'cache_key' );

// Set cached value (1 hour expiration)
WP_MCP_AI_Cache_Helper::set( 'cache_key', $data, HOUR_IN_SECONDS );

// Delete specific cache
WP_MCP_AI_Cache_Helper::delete( 'cache_key' );

// Delete pattern-based caches
WP_MCP_AI_Cache_Helper::delete_pattern( 'assistant_%' );

// Clear all plugin caches
WP_MCP_AI_Cache_Helper::clear_all_caches();
```

### Cached Data Types

#### 1. Assistant Lists
- **Cache Key**: `assistants_list_[hash]`
- **Expiration**: 30 minutes
- **Usage**: List of all published assistants
- **Invalidation**: When any assistant is created, updated, or deleted

#### 2. Assistant Configurations
- **Cache Key**: `assistant_config_[id]`
- **Expiration**: 1 hour
- **Usage**: Individual assistant settings and metadata
- **Invalidation**: When specific assistant is updated

#### 3. Elementor Options
- **Cache Key**: `elementor_assistant_options`
- **Expiration**: 1 hour
- **Usage**: Assistant dropdown options in Elementor widgets
- **Invalidation**: When any assistant is created, updated, or deleted

### Cache Invalidation

Automatic cache invalidation is handled via WordPress hooks:

```php
// Invalidate on assistant save
add_action( 'save_post_mcp_ai_assistant', 'wp_mcp_ai_invalidate_assistant_cache_on_save' );

// Invalidate on assistant deletion
add_action( 'delete_post', 'wp_mcp_ai_invalidate_assistant_cache_on_delete' );
add_action( 'wp_trash_post', 'wp_mcp_ai_invalidate_assistant_cache_on_delete' );

// Invalidate on meta update
add_action( 'updated_post_meta', 'wp_mcp_ai_invalidate_assistant_cache_on_meta_update' );
add_action( 'added_post_meta', 'wp_mcp_ai_invalidate_assistant_cache_on_meta_update' );
```

### Disabling Cache

You can disable caching in two ways:

**1. Via Constant (wp-config.php)**:
```php
define( 'WP_MCP_AI_DISABLE_CACHE', true );
```

**2. Via Filter**:
```php
add_filter( 'wp_mcp_ai_cache_enabled', '__return_false' );
```

## Database Query Optimization

### WP_Query Optimizations

All `WP_Query` calls have been optimized with appropriate parameters:

#### no_found_rows
Skips the `SQL_CALC_FOUND_ROWS` query, reducing database overhead when pagination info isn't needed:

```php
$query = new WP_Query(
    array(
        'no_found_rows' => true,  // Skip counting total rows
        // ... other args
    )
);
```

**When to use**: When you don't need `found_posts`, `max_num_pages`, or pagination information.

**Performance impact**: Reduces query time by 20-40% on large datasets.

#### update_post_term_cache
Controls whether taxonomy term caches are updated:

```php
$query = new WP_Query(
    array(
        'update_post_term_cache' => false,  // Skip term cache updates
        // ... other args
    )
);
```

**When to use**: When the query doesn't need taxonomy/term data.

**Performance impact**: Reduces memory usage and eliminates unnecessary database queries for term relationships.

#### update_post_meta_cache
Controls whether post meta caches are updated:

```php
$query = new WP_Query(
    array(
        'update_post_meta_cache' => true,  // Update meta cache
        // ... other args
    )
);
```

**When to use**: Set to `true` when you need post meta data, `false` when you don't.

**Performance impact**: Significant reduction in queries when set to `false` on posts with lots of meta data.

#### fields
Returns only specific fields instead of full post objects:

```php
$query = new WP_Query(
    array(
        'fields' => 'ids',  // Return only post IDs
        // ... other args
    )
);
```

**Options**: `'ids'`, `'id=>parent'`, or default (full objects).

**Performance impact**: Reduces memory usage by 50-80% when full post objects aren't needed.

### Optimized Locations

The following files have been optimized:

1. **includes/repositories/class-wp-mcp-ai-assistant-repository.php**
   - `find_all()` method
   - `search()` method
   - `count_by_status()` method

2. **includes/tools/class-wp-mcp-ai-tool-search-content.php**
   - Content search queries

3. **includes/tools/class-wp-mcp-ai-tool-search-attachments.php**
   - Attachment search queries

4. **includes/tools/class-wp-mcp-ai-tool-get-elementor-templates.php**
   - Template listing queries

5. **includes/class-wp-mcp-ai-rest-mcp-methods.php**
   - MCP prompts list endpoint

6. **includes/class-wp-mcp-ai-federation-directory-rest.php**
   - Peer listing and discovery queries

7. **includes/class-wp-mcp-ai-federation-peer-verifier.php**
   - Peer verification queries

8. **includes/elementor/class-wp-mcp-ai-elementor-widget.php**
   - Assistant options query

## Asset Loading Strategy

### Conditional Loading

All CSS and JavaScript assets are loaded conditionally, only when needed:

#### Admin Pages

```php
public function enqueue_assets( $hook ) {
    // Only load on our settings page
    if ( 'settings_page_wp-mcp-ai' !== $hook ) {
        return;
    }
    
    wp_enqueue_style( 'wp-mcp-ai-admin-settings', ... );
    wp_enqueue_script( 'wp-mcp-ai-admin-settings', ... );
}
```

**Implemented on**:
- Settings page
- Settings dashboard
- Diagnostic pages
- Auth0 setup page
- All admin tools pages

#### Frontend

```php
public function render_shortcode() {
    // Only enqueue when shortcode is rendered
    wp_enqueue_script( 'wp-mcp-ai-chat' );
    wp_enqueue_style( 'wp-mcp-ai-chat' );
    
    // ... render output
}
```

**Benefits**:
- Reduces page load time on pages without WP oOS features
- Minimizes HTTP requests
- Prevents JavaScript conflicts
- Improves overall WordPress admin performance

### Asset Registration vs. Enqueuing

The plugin uses WordPress best practices:

1. **Register** assets early (on `init` hook)
2. **Enqueue** assets only when needed (on specific page/shortcode render)

```php
// Register (early, doesn't load assets)
add_action( 'init', array( $this, 'register_assets' ) );

// Enqueue (only when needed)
wp_enqueue_script( self::SCRIPT_HANDLE );
```

## Configuration

### Cache Expiration Times

You can customize cache expiration times using filters:

```php
// Modify assistant list cache expiration (default: 30 minutes)
add_filter( 'wp_mcp_ai_cache_assistants_list_expiration', function( $expiration ) {
    return 15 * MINUTE_IN_SECONDS;  // 15 minutes
});

// Modify assistant config cache expiration (default: 1 hour)
add_filter( 'wp_mcp_ai_cache_assistant_config_expiration', function( $expiration ) {
    return 2 * HOUR_IN_SECONDS;  // 2 hours
});
```

### Custom Cache Keys

For custom caching needs, you can use the helper methods directly:

```php
// Cache custom data
$cache_key = 'my_custom_data_' . $user_id;
WP_MCP_AI_Cache_Helper::set( $cache_key, $my_data, DAY_IN_SECONDS );

// Retrieve custom data
$cached_data = WP_MCP_AI_Cache_Helper::get( $cache_key );
if ( false === $cached_data ) {
    // Cache miss - generate data
    $cached_data = generate_my_data( $user_id );
    WP_MCP_AI_Cache_Helper::set( $cache_key, $cached_data, DAY_IN_SECONDS );
}
```

## Best Practices

### When to Cache

✅ **DO cache**:
- Frequently accessed data that changes infrequently
- Database query results used across multiple requests
- Configuration data
- List of posts/custom post types

❌ **DON'T cache**:
- User-specific sensitive data (unless properly isolated)
- Real-time data that must be current
- Data that changes on every request
- Results from queries with variable parameters

### Cache Invalidation

Always invalidate caches when data changes:

```php
// Example: Invalidate after updating assistant
function update_assistant_setting( $assistant_id, $setting, $value ) {
    update_post_meta( $assistant_id, $setting, $value );
    
    // Invalidate assistant-specific cache
    WP_MCP_AI_Cache_Helper::invalidate_assistant_cache( $assistant_id );
}
```

### Query Optimization Checklist

When writing new WP_Query calls, consider:

1. ✅ Do you need total post count? → Use `no_found_rows => true` if not
2. ✅ Do you need taxonomy data? → Use `update_post_term_cache => false` if not
3. ✅ Do you need post meta data? → Set `update_post_meta_cache` appropriately
4. ✅ Do you need full post objects? → Use `fields => 'ids'` if IDs are sufficient
5. ✅ Can results be cached? → Wrap query in cache helper if appropriate

### Performance Monitoring

Monitor cache effectiveness:

```php
// Log cache hits/misses (for development)
add_action( 'shutdown', function() {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $stats = WP_MCP_AI_Cache_Helper::get_stats();
        error_log( 'Cache Stats: ' . print_r( $stats, true ) );
    }
});
```

### Memory Considerations

- Transient caches are stored in the database by default
- For high-traffic sites, consider using a persistent object cache (Redis, Memcached)
- WordPress will automatically use object cache if available
- Clear caches during plugin updates: `WP_MCP_AI_Cache_Helper::clear_all_caches()`

## Troubleshooting

### Cache Not Working

1. Check if caching is enabled:
```php
$enabled = WP_MCP_AI_Cache_Helper::is_caching_enabled();
var_dump( $enabled );  // Should be true
```

2. Verify transients are being stored:
```php
global $wpdb;
$transients = $wpdb->get_results(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_%'"
);
var_dump( $transients );
```

3. Check for object cache:
```php
// Object cache overrides transients
$using_object_cache = wp_using_ext_object_cache();
var_dump( $using_object_cache );
```

### Stale Cache Data

If you see outdated data:

1. Clear all caches manually:
```php
WP_MCP_AI_Cache_Helper::clear_all_caches();
```

2. Check invalidation hooks are firing:
```php
add_action( 'save_post_mcp_ai_assistant', function( $post_id ) {
    error_log( 'Invalidating cache for assistant: ' . $post_id );
}, 5 );
```

3. Reduce cache expiration time for testing

### Performance Still Slow

1. Enable query monitoring:
```php
define( 'SAVEQUERIES', true );
```

2. Check for other slow queries:
```php
add_action( 'shutdown', function() {
    global $wpdb;
    $queries = $wpdb->queries;
    // Analyze slow queries
});
```

3. Consider persistent object cache (Redis/Memcached) for high-traffic sites

## Changelog

### Version 1.0.0 (Phase 1)
- ✅ Implemented centralized cache helper class
- ✅ Added transient caching for assistant data
- ✅ Optimized all WP_Query calls with appropriate parameters
- ✅ Implemented automatic cache invalidation hooks
- ✅ Verified conditional asset loading across all pages
- ✅ Added caching to service layer

## References

- [WordPress Transients API](https://developer.wordpress.org/apis/handbook/transients/)
- [WP_Query Performance](https://10up.github.io/Engineering-Best-Practices/php/#performance)
- [WordPress Object Cache](https://developer.wordpress.org/reference/classes/wp_object_cache/)
