# JetEngine API Compatibility Layer

## Overview

This document describes the compatibility layer implemented to support different versions of JetEngine that may have different query API methods.

## Problem

JetEngine's Custom Content Type (CCT) item handlers have evolved over time:

- **Newer versions** (≥ 3.x): Provide `query_items()` method on the item handler
- **Older versions** (< 3.x): Use `get_factory()->db->query()` API instead

When the plugin calls `$handler->query_items()` on older JetEngine versions, it causes a fatal error because the method doesn't exist.

## Solution

We've implemented a `query_items_safe()` method in all classes that query JetEngine CCT data. This method:

1. **First tries** the newer `query_items()` method if it exists
2. **Falls back** to the older `get_factory()->db->query()` API if query_items doesn't exist
3. **Returns empty array** if neither method is available (e.g., when JetEngine is not installed)

## Implementation

### Files Modified

The following files have been updated with the `query_items_safe()` compatibility layer:

1. `includes/class-wp-mcp-ai-performance-monitor-cct.php`
2. `includes/admin/class-wp-mcp-ai-performance-reporter.php`
3. `includes/elementor/class-wp-mcp-ai-elementor-performance-recommendations-widget.php`
4. `includes/elementor/class-wp-mcp-ai-elementor-test-results-table-widget.php`

### Code Pattern

```php
/**
 * Safely query items with fallback for older JetEngine versions.
 *
 * @param object $handler JetEngine item handler.
 * @param array  $args    Query arguments.
 * @return array Query results.
 */
protected static function query_items_safe( $handler, $args ) {
    // Try the query_items method first (newer JetEngine versions).
    if ( method_exists( $handler, 'query_items' ) ) {
        return $handler->query_items( $args );
    }

    // Fallback: Use the factory->db->query API (older JetEngine versions).
    $factory = method_exists( $handler, 'get_factory' ) ? $handler->get_factory() : null;

    if ( $factory && ! empty( $factory->db ) && method_exists( $factory->db, 'query' ) ) {
        return $factory->db->query( $args );
    }

    // If neither method works, return empty array.
    return array();
}
```

### Usage Example

Before (Direct call - breaks on older JetEngine):
```php
$handler = WP_MCP_AI_Performance_Monitor_CCT::get_item_handler();
$items = $handler->query_items( $args ); // ❌ Fatal error on older JetEngine
```

After (Safe call with fallback):
```php
$handler = WP_MCP_AI_Performance_Monitor_CCT::get_item_handler();
$items = self::query_items_safe( $handler, $args ); // ✅ Works with all versions
```

## Testing

A comprehensive test suite has been added in `tests/test-jetengine-query-items-compatibility.php` that covers:

- ✅ Behavior with `query_items()` method available (newer JetEngine)
- ✅ Behavior with `get_factory()->db->query()` available (older JetEngine)
- ✅ Behavior when neither method is available (graceful degradation)
- ✅ Integration with Performance Monitor CCT
- ✅ Integration with Performance Reporter

## Version Compatibility

This compatibility layer supports:

| JetEngine Version | Query Method | Status |
|-------------------|--------------|--------|
| ≥ 3.x | `query_items()` | ✅ Supported |
| < 3.x | `get_factory()->db->query()` | ✅ Supported (fallback) |
| Not installed | N/A | ✅ Graceful degradation (empty array) |

## Future Considerations

### When to Update This Layer

This compatibility layer can be removed when:

1. **Minimum JetEngine version requirement** is set to a version that definitely has `query_items()`
2. **All users have upgraded** to newer JetEngine versions
3. **JetEngine API stabilizes** and backward compatibility is no longer needed

### Adding New Query Functionality

When adding new functionality that queries JetEngine CCT data:

1. **Always use** `query_items_safe()` instead of calling `query_items()` directly
2. **Add the method** to the class if it doesn't already exist (copy from existing implementations)
3. **Test both** the newer and older API paths
4. **Document** any JetEngine version-specific behavior

## Related Files

- `includes/class-wp-mcp-ai-model-rate-limits-cct.php` - Uses `factory->db->query()` directly (older pattern)
- `includes/class-wp-mcp-ai-jetengine-cct.php` - Base JetEngine CCT implementation
- `docs/jet-engine-rest-routes.md` - JetEngine REST API reference

## Troubleshooting

### "Call to undefined method query_items()" Error

**Cause**: Direct call to `query_items()` on older JetEngine version

**Solution**: Replace with `query_items_safe()` call

**Example**:
```php
// Before (breaks):
$items = $handler->query_items( $args );

// After (works):
$items = self::query_items_safe( $handler, $args );
```

### Empty Results When JetEngine is Active

**Check**:
1. Is JetEngine Data Stores module enabled?
2. Is the CCT registered properly?
3. Does the item handler exist? (`get_item_handler()` returns non-null)

**Debug**:
```php
$handler = WP_MCP_AI_Performance_Monitor_CCT::get_item_handler();
if ( ! $handler ) {
    error_log( 'JetEngine handler not available' );
}

// Check which API is being used
if ( method_exists( $handler, 'query_items' ) ) {
    error_log( 'Using newer query_items() API' );
} elseif ( method_exists( $handler, 'get_factory' ) ) {
    error_log( 'Using older factory->db->query() API' );
} else {
    error_log( 'No query API available' );
}
```

## References

- [JetEngine Documentation](https://crocoblock.com/knowledge-base/jetengine/)
- [WordPress Coding Standards - Method Existence Checks](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/#checking-for-existence)
- Issue: "Align JetEngine version or add a compatibility fallback"
