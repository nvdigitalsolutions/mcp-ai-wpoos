# JetEngine API Compatibility

## Overview

WP oOS integrates with JetEngine Custom Content Types (CCT) to store performance monitoring data, chat transcripts, and other structured data. This document describes the compatibility layer implemented to support both old and new versions of JetEngine.

## The API Change

In JetEngine version 3.3+, Crocoblock made significant changes to the Item_Handler API:

- **Removed**: `Item_Handler::query_items()` method
- **Replaced with**: Direct database queries using `$type_object->db->query()`

This breaking change affected any code that programmatically queried CCT items using the old API.

## Affected Files

The following files were updated to support both old and new JetEngine APIs:

1. `includes/services/class-wp-mcp-ai-performance-monitor-service.php` - Core implementation
2. `includes/admin/class-wp-mcp-ai-performance-reporter.php` - Performance reporting
3. `includes/elementor/class-wp-mcp-ai-elementor-performance-recommendations-widget.php` - Elementor widget
4. `includes/elementor/class-wp-mcp-ai-elementor-test-results-table-widget.php` - Elementor widget

## Solution Implementation

### Backward-Compatible Query Method

The Performance Monitor CCT class now includes a `query_items()` static method that automatically detects which API is available:

```php
public static function query_items( $args, $limit = 100, $offset = 0 ) {
    $type_object = self::get_content_type();

    if ( ! $type_object ) {
        return array();
    }

    // Try new API first (JetEngine 3.3+)
    if ( ! empty( $type_object->db ) && method_exists( $type_object->db, 'query' ) ) {
        $query_args = self::prepare_jetengine_query_args( $args, $type_object );
        $type_object->db->set_format_flag( ARRAY_A );
        $items = $type_object->db->query( $query_args, $limit, $offset );
        return is_array( $items ) ? $items : array();
    }

    // Fallback to old API (JetEngine < 3.3)
    $handler = $type_object->get_item_handler();
    if ( $handler && method_exists( $handler, 'query_items' ) ) {
        $items = $handler->query_items( $args );
        return is_array( $items ) ? $items : array();
    }

    return array();
}
```

### Query Argument Conversion

The new JetEngine API requires query arguments in a specific format. The `prepare_jetengine_query_args()` method converts simple key-value pairs to the required format:

**Simple Equality**:
```php
// Input
array( 'component' => 'rest_api' )

// Converted to
array(
    array(
        'field'    => 'component',
        'operator' => '=',
        'value'    => 'rest_api',
    )
)
```

**Date Ranges**:
```php
// Input
array(
    'tested_at' => array(
        'type'  => 'DATE',
        'value' => array( '2024-01-01 00:00:00', '2024-12-31 23:59:59' ),
    )
)

// Converted to
array(
    array(
        'field'    => 'tested_at',
        'operator' => 'BETWEEN',
        'value'    => array( '2024-01-01 00:00:00', '2024-12-31 23:59:59' ),
        'type'     => 'DATE',
    )
)
```

## Usage Example

All code that previously called `$handler->query_items()` has been updated to use the new static method:

**Before**:
```php
$handler = WP_MCP_AI_Performance_Monitor_CCT::get_item_handler();
if ( ! $handler ) {
    return array();
}

$args = array( 'component' => 'rest_api' );
$items = $handler->query_items( $args );
```

**After**:
```php
$args = array( 'component' => 'rest_api' );
$items = WP_MCP_AI_Performance_Monitor_CCT::query_items( $args );

// Empty result check and fallback handling
if ( empty( $items ) ) {
    // Fallback to WordPress options or return empty
}
```

## Supported JetEngine Versions

This implementation supports:

- **JetEngine 3.3+**: Uses new `$type_object->db->query()` API
- **JetEngine 3.0-3.2**: Uses legacy `$handler->query_items()` API
- **No JetEngine**: Falls back to WordPress options storage

## Testing

A comprehensive test suite has been added in `tests/test-performance-monitor-cct-query-compatibility.php` that verifies:

1. The `query_items()` method exists and is public/static
2. Argument conversion works correctly for simple equality
3. Argument conversion works correctly for date ranges
4. Malformed date ranges are handled gracefully
5. Empty arguments produce empty query args
6. The method signature matches expected usage
7. Returns empty array when JetEngine is unavailable

## Error Messages

If you encounter this error:

```
Fatal error: Uncaught Error: Call to undefined method Jet_Engine\Modules\Custom_Content_Types\Item_Handler::query_items()
```

This indicates you're using JetEngine 3.3+ with code that hasn't been updated. Ensure you're using the latest version of WP oOS which includes this compatibility layer.

## WordPress Options Fallback

When JetEngine is not available, the Performance Monitor CCT automatically falls back to WordPress options:

- **Storage**: `wp_mcp_ai_performance_tests` option
- **Limit**: 100 most recent tests (auto-pruning)
- **Format**: Array with test IDs as keys

This ensures the plugin remains functional even in Base Version mode (without JetEngine).

## Migration Notes

If you're upgrading from an older version of WP oOS:

1. **No migration required**: The compatibility layer handles both APIs transparently
2. **No data loss**: Existing CCT data remains accessible
3. **No configuration changes**: Works automatically after plugin update

## References

- [JetEngine REST API Overview](https://crocoblock.com/knowledge-base/features/rest-api-overview/)
- [JetEngine CCT CRUD Examples](https://gist.github.com/Crocoblock/a9be7dbb1cb05aa2741aec97757c7f72)
- [API to interact with JetEngine CCT from PHP](https://gist.github.com/MjHead/d9609c8a0ab3ecb2388d838a81dc1c80)

## Related Documentation

- [Performance Monitoring](../../guides/admin/monitoring/performance-monitoring.md)
- [Base vs Full Version Comparison](../../reference/technical/base-vs-full-comparison.md)
- [Assistant Storage: CPT vs CCT](./assistant-storage-cpt-vs-cct.md)
