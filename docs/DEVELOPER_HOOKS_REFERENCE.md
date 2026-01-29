# WordPress Integration - Developer Hooks Reference

This document provides a comprehensive reference for all action and filter hooks available in the NV oOS WordPress plugin for deep WordPress integration.

## Table of Contents

1. [Tool Execution Hooks](#tool-execution-hooks)
2. [Caching Hooks](#caching-hooks)
3. [Performance Hooks](#performance-hooks)
4. [Privacy & Compliance Hooks](#privacy--compliance-hooks)
5. [Content Management Hooks](#content-management-hooks)
6. [Admin & UI Hooks](#admin--ui-hooks)

---

## Tool Execution Hooks

### Action: `wp_mcp_ai_before_tool_execute`

Fires before any tool execution.

**Parameters:**
- `array $arguments` - Tool arguments
- `array $context` - Execution context
- `string $tool_slug` - Tool identifier

**Example:**
```php
add_action( 'wp_mcp_ai_before_tool_execute', function( $arguments, $context, $tool_slug ) {
    // Log tool execution
    error_log( "Executing tool: {$tool_slug}" );
}, 10, 3 );
```

---

### Action: `wp_mcp_ai_before_tool_execute_{tool_slug}`

Fires before specific tool execution. Dynamic hook with tool slug.

**Parameters:**
- `array $arguments` - Tool arguments
- `array $context` - Execution context

**Example:**
```php
// Hook into create_post tool execution
add_action( 'wp_mcp_ai_before_tool_execute_create_post', function( $arguments, $context ) {
    // Modify arguments before post creation
    if ( ! isset( $arguments['author_id'] ) ) {
        $arguments['author_id'] = get_current_user_id();
    }
}, 10, 2 );
```

---

### Action: `wp_mcp_ai_after_tool_execute`

Fires after any tool execution completes.

**Parameters:**
- `mixed $result` - Tool execution result
- `array $arguments` - Tool arguments
- `array $context` - Execution context
- `string $tool_slug` - Tool identifier

**Example:**
```php
add_action( 'wp_mcp_ai_after_tool_execute', function( $result, $arguments, $context, $tool_slug ) {
    if ( is_wp_error( $result ) ) {
        // Handle tool execution errors
        error_log( "Tool {$tool_slug} failed: " . $result->get_error_message() );
    }
}, 10, 4 );
```

---

### Action: `wp_mcp_ai_after_tool_execute_{tool_slug}`

Fires after specific tool execution completes.

**Parameters:**
- `mixed $result` - Tool execution result
- `array $arguments` - Tool arguments
- `array $context` - Execution context

**Example:**
```php
add_action( 'wp_mcp_ai_after_tool_execute_auto_categorize', function( $result, $arguments, $context ) {
    // Send notification after auto-categorization
    if ( ! is_wp_error( $result ) ) {
        wp_mail( 
            get_option( 'admin_email' ),
            'Post Categorized',
            "Post {$result['post_id']} was automatically categorized."
        );
    }
}, 10, 3 );
```

---

### Filter: `wp_mcp_ai_tool_result`

Filters tool execution result.

**Parameters:**
- `mixed $result` - Tool execution result
- `array $arguments` - Tool arguments
- `array $context` - Execution context
- `string $tool_slug` - Tool identifier

**Returns:** `mixed` - Filtered result

**Example:**
```php
add_filter( 'wp_mcp_ai_tool_result', function( $result, $arguments, $context, $tool_slug ) {
    // Add timestamp to all tool results
    if ( is_array( $result ) ) {
        $result['timestamp'] = current_time( 'mysql' );
    }
    return $result;
}, 10, 4 );
```

---

### Filter: `wp_mcp_ai_tool_result_{tool_slug}`

Filters specific tool execution result.

**Parameters:**
- `mixed $result` - Tool execution result
- `array $arguments` - Tool arguments
- `array $context` - Execution context

**Returns:** `mixed` - Filtered result

**Example:**
```php
add_filter( 'wp_mcp_ai_tool_result_search_content', function( $result, $arguments, $context ) {
    // Filter search results to only include published posts
    if ( isset( $result['posts'] ) ) {
        $result['posts'] = array_filter( $result['posts'], function( $post ) {
            return 'publish' === $post['post_status'];
        } );
    }
    return $result;
}, 10, 3 );
```

---

## Caching Hooks

### Filter: `wp_mcp_ai_cache_enabled`

Filters whether caching is enabled globally.

**Parameters:**
- `bool $enabled` - Whether caching is enabled (default: true)

**Returns:** `bool` - Whether caching is enabled

**Example:**
```php
add_filter( 'wp_mcp_ai_cache_enabled', function( $enabled ) {
    // Disable caching in development
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        return false;
    }
    return $enabled;
} );
```

---

### Action: `wp_mcp_ai_warm_cache`

Fires when cache warming is initiated.

**Parameters:**
- `string $cache_helper` - Cache helper class name

**Example:**
```php
add_action( 'wp_mcp_ai_warm_cache', function( $cache_helper ) {
    // Warm custom data cache
    $custom_data = get_expensive_data();
    call_user_func( array( $cache_helper, 'set' ), 'custom_data', $custom_data );
} );
```

---

### Action: `wp_mcp_ai_cache_cleared`

Fires when caches are cleared.

**Parameters:**
- `string $cache_type` - Type of cache cleared ('all', 'assistants', 'tools', etc.)

**Example:**
```php
add_action( 'wp_mcp_ai_cache_cleared', function( $cache_type ) {
    // Log cache clearing
    error_log( "NV oOS cache cleared: {$cache_type}" );
} );
```

---

## Performance Hooks

### Action: `wp_mcp_ai_tool_performance`

Fires when tool execution completes with performance metrics.

**Parameters:**
- `string $tool_slug` - Tool identifier
- `float $execution_time` - Execution time in seconds
- `array $arguments` - Tool arguments

**Example:**
```php
add_action( 'wp_mcp_ai_tool_performance', function( $tool_slug, $execution_time, $arguments ) {
    // Track performance metrics
    if ( $execution_time > 3.0 ) {
        error_log( "Slow tool execution: {$tool_slug} took {$execution_time}s" );
    }
}, 10, 3 );
```

---

### Filter: `wp_mcp_ai_performance_threshold`

Filters the performance threshold for slow execution warnings.

**Parameters:**
- `float $threshold` - Threshold in seconds (default: 5.0)
- `string $tool_slug` - Tool identifier

**Returns:** `float` - Performance threshold

**Example:**
```php
add_filter( 'wp_mcp_ai_performance_threshold', function( $threshold, $tool_slug ) {
    // Higher threshold for image generation tools
    if ( strpos( $tool_slug, 'generate_image' ) !== false ) {
        return 15.0;
    }
    return $threshold;
}, 10, 2 );
```

---

## Privacy & Compliance Hooks

### Filter: `wp_mcp_ai_privacy_exporter_data`

Filters privacy export data before inclusion in export file.

**Parameters:**
- `array $export_data` - Export data
- `int $user_id` - User ID
- `string $tool_slug` - Tool identifier

**Returns:** `array` - Filtered export data

**Example:**
```php
add_filter( 'wp_mcp_ai_privacy_exporter_data', function( $export_data, $user_id, $tool_slug ) {
    // Anonymize sensitive fields
    if ( isset( $export_data['email'] ) ) {
        $export_data['email'] = wp_privacy_anonymize_data( 'email', $export_data['email'] );
    }
    return $export_data;
}, 10, 3 );
```

---

### Action: `wp_mcp_ai_privacy_erased`

Fires after privacy data is erased for a user.

**Parameters:**
- `int $user_id` - User ID
- `array $erasure_results` - Erasure results with counts

**Example:**
```php
add_action( 'wp_mcp_ai_privacy_erased', function( $user_id, $erasure_results ) {
    // Log erasure
    error_log( sprintf(
        'Privacy data erased for user %d: %d items removed',
        $user_id,
        $erasure_results['items_removed']
    ) );
}, 10, 2 );
```

---

## Content Management Hooks

### Filter: `wp_mcp_ai_auto_categorize_categories`

Filters suggested categories before assignment.

**Parameters:**
- `array $categories` - Suggested category IDs/names
- `int $post_id` - Post ID
- `array $analysis` - AI analysis results

**Returns:** `array` - Filtered categories

**Example:**
```php
add_filter( 'wp_mcp_ai_auto_categorize_categories', function( $categories, $post_id, $analysis ) {
    // Always include "Uncategorized" if no categories found
    if ( empty( $categories ) ) {
        $categories = array( 1 ); // Uncategorized ID
    }
    return $categories;
}, 10, 3 );
```

---

### Action: `wp_mcp_ai_content_categorized`

Fires after content is automatically categorized.

**Parameters:**
- `int $post_id` - Post ID
- `array $categories` - Assigned categories
- `array $analysis` - AI analysis results

**Example:**
```php
add_action( 'wp_mcp_ai_content_categorized', function( $post_id, $categories, $analysis ) {
    // Send notification
    $post = get_post( $post_id );
    wp_mail(
        get_post_meta( $post_id, 'author_email', true ),
        'Your post has been categorized',
        sprintf( 'Your post "%s" has been automatically categorized.', $post->post_title )
    );
}, 10, 3 );
```

---

### Filter: `wp_mcp_ai_internal_links_suggestions`

Filters internal link suggestions before display.

**Parameters:**
- `array $suggestions` - Link suggestions
- `int $post_id` - Current post ID
- `array $args` - Analysis arguments

**Returns:** `array` - Filtered suggestions

**Example:**
```php
add_filter( 'wp_mcp_ai_internal_links_suggestions', function( $suggestions, $post_id, $args ) {
    // Filter out links to drafts
    return array_filter( $suggestions, function( $suggestion ) {
        return 'publish' === get_post_status( $suggestion['post_id'] );
    } );
}, 10, 3 );
```

---

## Admin & UI Hooks

### Action: `wp_mcp_ai_admin_notices`

Fires in admin to display custom notices.

**Parameters:**
- `string $screen_id` - Current admin screen ID

**Example:**
```php
add_action( 'wp_mcp_ai_admin_notices', function( $screen_id ) {
    if ( 'edit-post' === $screen_id ) {
        echo '<div class="notice notice-info"><p>AI categorization is active for this post type.</p></div>';
    }
} );
```

---

### Filter: `wp_mcp_ai_dashboard_widgets`

Filters dashboard widgets to display.

**Parameters:**
- `array $widgets` - Widget configurations

**Returns:** `array` - Filtered widgets

**Example:**
```php
add_filter( 'wp_mcp_ai_dashboard_widgets', function( $widgets ) {
    // Add custom widget
    $widgets['custom_ai_stats'] = array(
        'title'    => 'Custom AI Statistics',
        'callback' => 'my_custom_widget_callback',
        'priority' => 'high',
    );
    return $widgets;
} );
```

---

## Custom Hook Registration

Developers can register custom hooks using the following patterns:

### Tool-Specific Hooks

For a custom tool with slug `my_custom_tool`:

```php
// Before execution
do_action( 'wp_mcp_ai_before_tool_execute_my_custom_tool', $arguments, $context );

// After execution
do_action( 'wp_mcp_ai_after_tool_execute_my_custom_tool', $result, $arguments, $context );

// Filter result
$result = apply_filters( 'wp_mcp_ai_tool_result_my_custom_tool', $result, $arguments, $context );
```

---

## Best Practices

1. **Priority Management**: Use appropriate priorities (10 is default). Lower numbers run earlier.

2. **Error Handling**: Always check for `WP_Error` objects in hook callbacks.

3. **Performance**: Avoid heavy processing in frequently-called hooks. Use caching.

4. **Documentation**: Document your hook usage for maintainability.

5. **Backward Compatibility**: Check if hooks exist before using them:
   ```php
   if ( has_action( 'wp_mcp_ai_before_tool_execute' ) ) {
       // Hook exists, safe to use
   }
   ```

---

## Contributing

If you create custom tools or extensions, please document any new hooks you add following this format and submit a pull request to the [repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos).

---

**Last Updated:** January 29, 2026
**Version:** 1.0.0
