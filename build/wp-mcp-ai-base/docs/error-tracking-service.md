# Error Tracking Service

## Overview

The Error Tracking Service provides centralized error monitoring and analysis capabilities for the WP oOS plugin. It tracks errors across all components, calculates error rates, and integrates with the Performance Monitor CCT for comprehensive diagnostics.

## Features

- **Real-time Error Tracking** - Track errors as they occur across all plugin components
- **Error Rate Calculation** - Calculate error rates as percentage of total requests
- **Component-Level Granularity** - Track and analyze errors per component
- **Automatic Cleanup** - Automatically removes errors older than 7 days
- **Performance Integration** - Integrates with Performance Monitor CCT
- **Caching** - Caches error rates for 5 minutes to reduce overhead
- **Statistics** - Provides aggregated statistics by component

## Usage

### Getting the Service Instance

```php
$error_service = wp_mcp_ai_get_error_tracking_service();
```

### Tracking an Error

```php
// Basic error tracking
$error_id = $error_service->track_error(
    'rest_api',           // Component name
    'API request failed', // Error message
    array(                // Optional context
        'endpoint' => '/wp-json/mcp-ai/v1/chat',
        'status'   => 500
    )
);
```

### Getting Error Rate

```php
// Get error rate for last hour
$error_rate = $error_service->get_error_rate( 'rest_api', 3600 );

// Get error rate with explicit total requests
$error_rate = $error_service->get_error_rate( 'rest_api', 3600, 100 );
```

### Retrieving Errors

```php
// Get recent errors (last 50)
$errors = $error_service->get_recent_errors( 50 );

// Get errors by component for last hour
$errors = $error_service->get_errors_by_component( 'rest_api', 3600 );

// Get errors for last 24 hours
$errors = $error_service->get_recent_errors( 100, 86400 );
```

### Error Statistics

```php
// Get statistics for last hour
$stats = $error_service->get_error_statistics( 3600 );

// Example output:
// array(
//     'rest_api' => array(
//         'count'               => 15,
//         'first_seen'          => 1699876543,
//         'last_seen'           => 1699880143,
//         'unique_message_count' => 3
//     ),
//     'chat_ui' => array(
//         'count'               => 5,
//         'first_seen'          => 1699879000,
//         'last_seen'           => 1699880000,
//         'unique_message_count' => 2
//     )
// )
```

### Recording Errors with Performance Metrics

```php
// Track error and store in Performance Monitor CCT
$error_id = $error_service->record_error_with_metrics(
    'rest_api',
    'API timeout occurred',
    array( 'timeout' => 30 ),
    true  // Store in CCT
);
```

## Component Names

Standard component names used in error tracking:

- `rest_api` - REST API endpoints
- `chat_ui` - Chat user interface
- `mcp_core` - MCP core functionality
- `elementor` - Elementor integration
- `cpt_assistant` - Assistant custom post type
- `cpt_ai_peer` - AI Peer custom post type

## Error Data Structure

Each tracked error contains:

```php
array(
    'id'          => 'err_6541c9e5f0a3e7.12345678',
    'component'   => 'rest_api',
    'message'     => 'Error message',
    'context'     => array( /* additional context */ ),
    'timestamp'   => 1699880143,
    'user_id'     => 1,
    'ip_address'  => '192.168.1.1',
    'request_uri' => '/wp-admin/admin-ajax.php',
    'user_agent'  => 'Mozilla/5.0...'
)
```

## Integration with Performance Monitor

The Error Tracking Service integrates with the Performance Monitor CCT:

```php
// Performance Reporter automatically checks error rates
$report = WP_MCP_AI_Performance_Reporter::generate_report();

// Report includes error rate alerts:
// - Critical: > 10% error rate
// - High: > 5% error rate
// - Medium: > 1% error rate
```

## Hooks and Filters

### Actions

#### `wp_mcp_ai_error`
Trigger error tracking manually.

```php
do_action( 'wp_mcp_ai_error', 'rest_api', 'Error message', array( 'context' ) );
```

#### `wp_mcp_ai_error_tracked`
Fires after an error is tracked.

```php
add_action( 'wp_mcp_ai_error_tracked', function( $error_id, $component, $message, $context ) {
    // React to tracked error
}, 10, 4 );
```

#### `wp_mcp_ai_cleanup_old_errors`
Daily cleanup of old errors (scheduled action).

### Filters

#### `wp_mcp_ai_error_tracking_enabled`
Enable or disable error tracking.

```php
add_filter( 'wp_mcp_ai_error_tracking_enabled', '__return_false' );
```

#### `wp_mcp_ai_estimate_total_requests`
Customize request estimation for error rate calculation.

```php
add_filter( 'wp_mcp_ai_estimate_total_requests', function( $estimated, $component, $time_period ) {
    // Custom estimation logic
    return $estimated * 2;
}, 10, 3 );
```

## Configuration

### Error Retention Period

Errors are automatically cleaned up after 7 days. This is defined by:

```php
WP_MCP_AI_Error_Tracking_Service::RETENTION_PERIOD; // 604800 seconds (7 days)
```

### Maximum Stored Errors

A maximum of 1000 errors are kept in the database:

```php
WP_MCP_AI_Error_Tracking_Service::MAX_STORED_ERRORS; // 1000
```

### Cache Duration

Error rates are cached for 5 minutes to reduce database queries.

## Best Practices

### 1. Use Appropriate Components

Always use standardized component names for consistency:

```php
// Good
$error_service->track_error( 'rest_api', 'Error message', $context );

// Avoid
$error_service->track_error( 'my_custom_component', 'Error message', $context );
```

### 2. Provide Context

Include relevant context data to aid debugging:

```php
$error_service->track_error( 'rest_api', 'Request failed', array(
    'endpoint'     => '/wp-json/mcp-ai/v1/chat',
    'method'       => 'POST',
    'status_code'  => 500,
    'response'     => $response_body,
    'request_time' => microtime( true )
));
```

### 3. Monitor Error Rates

Regularly check error rates to catch issues early:

```php
$error_rate = $error_service->get_error_rate( 'rest_api', 3600 );

if ( $error_rate > 5 ) {
    // Send alert to admin
    wp_mail(
        get_option( 'admin_email' ),
        'High Error Rate Alert',
        "Error rate for rest_api is $error_rate%"
    );
}
```

### 4. Clear Errors During Development

During development, you may want to clear errors:

```php
// Only in development
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    $error_service->clear_all_errors();
}
```

## Performance Considerations

The Error Tracking Service is designed to be lightweight:

- **Caching**: Error rates are cached for 5 minutes
- **Efficient Storage**: Uses WordPress options with size limits
- **Automatic Cleanup**: Old errors are removed automatically
- **Minimal Overhead**: Sanitization and validation are minimal

## Troubleshooting

### Errors Not Being Tracked

1. Check if error tracking is enabled:
```php
$enabled = apply_filters( 'wp_mcp_ai_error_tracking_enabled', true );
var_dump( $enabled );
```

2. Verify the action is firing:
```php
add_action( 'wp_mcp_ai_error', function( $component, $message ) {
    error_log( "Error tracked: $component - $message" );
}, 10, 2 );
```

### Error Rate Seems Incorrect

The error rate calculation requires accurate request counts. By default, the service estimates request counts. For accurate rates:

```php
// Track actual requests
$actual_requests = 150;
$error_rate = $error_service->get_error_rate( 'rest_api', 3600, $actual_requests );
```

### Old Errors Not Being Cleaned

Verify the scheduled cleanup is running:

```php
$scheduled = wp_next_scheduled( 'wp_mcp_ai_cleanup_old_errors' );
if ( ! $scheduled ) {
    // Re-schedule
    wp_schedule_event( time(), 'daily', 'wp_mcp_ai_cleanup_old_errors' );
}
```

## API Reference

### Methods

#### `track_error( $component, $message, $context = array() )`
Track an error.

**Parameters:**
- `$component` (string) - Component name
- `$message` (string) - Error message
- `$context` (array) - Additional context data

**Returns:** (int) Error ID

---

#### `get_error_rate( $component, $time_period = 3600, $total_requests = null )`
Get error rate for a component.

**Parameters:**
- `$component` (string) - Component name
- `$time_period` (int) - Time period in seconds (default: 3600 = 1 hour)
- `$total_requests` (int|null) - Total requests (default: null = estimate)

**Returns:** (float) Error rate as percentage

---

#### `get_errors_by_component( $component, $time_period = 3600 )`
Get errors for a specific component.

**Parameters:**
- `$component` (string) - Component name
- `$time_period` (int) - Time period in seconds

**Returns:** (array) Array of errors

---

#### `get_recent_errors( $limit = 50, $time_period = null )`
Get recent errors across all components.

**Parameters:**
- `$limit` (int) - Maximum number of errors to return
- `$time_period` (int|null) - Time period in seconds (default: null = all)

**Returns:** (array) Array of errors

---

#### `get_error_statistics( $time_period = 3600 )`
Get error statistics by component.

**Parameters:**
- `$time_period` (int) - Time period in seconds

**Returns:** (array) Statistics array

---

#### `clear_all_errors()`
Clear all tracked errors.

**Returns:** (bool) True on success

---

#### `record_error_with_metrics( $component, $message, $context = array(), $store_in_cct = false )`
Track error and optionally store in Performance Monitor CCT.

**Parameters:**
- `$component` (string) - Component name
- `$message` (string) - Error message
- `$context` (array) - Error context
- `$store_in_cct` (bool) - Whether to store in CCT

**Returns:** (int) Error ID

## See Also

- [Performance Monitoring Guide](performance-monitoring.md)
- [Performance Testing Guide](performance-testing-guide.md)
- [Performance Monitor CCT Documentation](jetengine-api-compatibility.md)
- [WP oOS Documentation Index](DOCUMENTATION_INDEX.md)
