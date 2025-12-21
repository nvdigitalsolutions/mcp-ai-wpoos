# Phase 3 Implementation: Error Handling & Logging Improvements

## Overview

This document describes the error handling and logging improvements implemented in Phase 3 of the WP oOS plugin modernization effort.

## New Components

### 1. Enhanced Logger (WP_MCP_AI_Logger)

#### Severity Levels

The logger now supports five severity levels following standard logging practices:

- **CRITICAL**: System failures or security issues that prevent core functionality
- **ERROR**: Errors that require attention but don't prevent the system from working
- **WARNING**: Potential issues that should be addressed
- **INFO**: General informational messages about plugin operations
- **DEBUG**: Detailed information for troubleshooting

#### New Methods

```php
// Convenience methods for each severity level
WP_MCP_AI_Logger::log_critical( $message, $context );
WP_MCP_AI_Logger::log_error( $message, $context );
WP_MCP_AI_Logger::log_warning( $message, $context );
WP_MCP_AI_Logger::log_info( $message, $context );
WP_MCP_AI_Logger::log_debug( $message, $context );

// User-friendly error messages
$friendly_error = WP_MCP_AI_Logger::get_user_friendly_error( 
    $error_code, 
    $error_message, 
    $context 
);
// Returns: array( 'message' => '...', 'suggestions' => array(...) )
```

#### User-Friendly Error Messages

The logger includes built-in translations for common error scenarios:

| Error Code | User Message | Suggestions Provided |
|------------|--------------|---------------------|
| `openai_api_error` | Unable to connect to the AI service | API key verification steps |
| `rate_limit_exceeded` | Rate limit exceeded | Wait time and upgrade options |
| `network_error` | Network connection failed | Connectivity troubleshooting |
| `invalid_api_key` | API authentication failed | API key validation steps |
| `insufficient_quota` | API quota exhausted | Quota management guidance |
| `tool_execution_failed` | Tool execution failed | Tool-specific troubleshooting |
| `file_upload_error` | File upload failed | File size and type guidance |

#### Customization

Developers can customize error messages using the filter:

```php
add_filter( 'wp_mcp_ai_user_friendly_error', function( $result, $error_code ) {
    if ( 'custom_error' === $error_code ) {
        $result['message'] = 'Custom error message';
        $result['suggestions'] = array( 'Custom suggestion' );
    }
    return $result;
}, 10, 2 );
```

### 2. Centralized Error Handler (WP_MCP_AI_Error_Handler)

#### Purpose

Provides consistent error creation across the plugin with:
- Automatic logging at appropriate severity levels
- User-friendly message generation
- Sensitive data sanitization
- Context enrichment

#### Error Creation Methods

```php
// General error with automatic logging
$error = WP_MCP_AI_Error_Handler::create_error(
    'error_code',
    'Technical message',
    array( 'context_data' => 'value' ),
    WP_MCP_AI_Logger::LEVEL_ERROR,
    true  // Add user-friendly suggestions
);

// REST API error (includes HTTP status)
$error = WP_MCP_AI_Error_Handler::create_rest_error(
    'invalid_request',
    'Invalid parameters',
    400,  // HTTP status code
    array( 'field' => 'assistant_id' )
);

// External API error (OpenAI, Gemini, etc.)
$error = WP_MCP_AI_Error_Handler::create_api_error(
    'openai',  // Provider name
    'API request failed',
    $api_response,  // Raw API response
    401  // HTTP status code
);

// Validation error
$error = WP_MCP_AI_Error_Handler::create_validation_error(
    'email',  // Field name
    'Invalid email address',
    array( 'provided_value' => 'not-an-email' )
);

// Authentication error
$error = WP_MCP_AI_Error_Handler::create_auth_error(
    'Invalid credentials'
);

// Permission denied error
$error = WP_MCP_AI_Error_Handler::create_permission_error(
    'You do not have permission',
    'manage_options'  // Required capability
);

// Rate limit error
$error = WP_MCP_AI_Error_Handler::create_rate_limit_error(
    'Too many requests',
    120  // Retry after (seconds)
);
```

#### Automatic Severity Detection

The error handler automatically determines log severity based on HTTP status codes:

- **5xx errors**: CRITICAL (server errors)
- **4xx errors**: WARNING (client errors)
- **3xx redirects**: INFO
- **2xx success**: ERROR (fallback)

#### API Response Sanitization

When logging API errors, sensitive data is automatically removed:

```php
// Input:
$api_response = array(
    'error' => array( 'message' => 'Invalid API key' ),
    'api_key' => 'sk-secret-key',  // Sensitive!
    'access_token' => 'secret',    // Sensitive!
);

// Sanitized output only includes safe fields:
// array( 'error' => array( 'message' => '...' ) )
```

#### Error Display Formatting

Extract user-friendly messages for display:

```php
$error = WP_MCP_AI_Error_Handler::create_rest_error(...);

$display = WP_MCP_AI_Error_Handler::format_error_for_display( $error );
// Returns: array(
//     'message' => 'User-friendly message',
//     'suggestions' => array( 'Step 1', 'Step 2', ... )
// )
```

#### Conditional Logging

Prevent logging of expected errors:

```php
$error = new WP_Error( 'rest_invalid_param', '...' );

// Returns false for expected WordPress REST API errors
$should_log = WP_MCP_AI_Error_Handler::should_log_error( $error );

// Customize via filter
add_filter( 'wp_mcp_ai_skip_error_logging', function( $skip_codes ) {
    $skip_codes[] = 'my_expected_error';
    return $skip_codes;
});
```

### 3. Enhanced Shortcode Error Handling

The `[mcp_ai_chat]` shortcode now includes comprehensive error logging:

#### Logged Error Scenarios

1. **Missing Assistant ID**
   - **Severity**: WARNING
   - **Context**: Shortcode attributes, rendering context

2. **Unavailable Assistant**
   - **Severity**: ERROR
   - **Context**: Assistant ID, post type, post status

3. **Permission Denied**
   - **Severity**: WARNING
   - **Context**: Required capability, user ID, user capabilities

4. **Unexpected Exceptions**
   - **Severity**: CRITICAL
   - **Context**: Exception message, file, line, stack trace

#### Example Error Log Entry

```
[WP oOS] ERROR: Shortcode attempted to render unavailable assistant
Context: {
    "assistant_id": 123,
    "assistant_exists": false,
    "post_type": null,
    "post_status": null,
    "attributes": {"assistant": "123"}
}
```

## Integration Guide

### Using the Error Handler in Your Code

**Before (old approach):**
```php
public function my_function() {
    if ( ! $required_value ) {
        error_log( 'Missing required value' );
        return new WP_Error( 'missing_value', 'Required value missing' );
    }
}
```

**After (new approach):**
```php
public function my_function() {
    if ( ! $required_value ) {
        return WP_MCP_AI_Error_Handler::create_rest_error(
            'missing_value',
            'Required value missing',
            400,
            array( 'field' => 'required_value' )
        );
    }
}
```

### Benefits

1. **Automatic logging** at appropriate severity
2. **User-friendly messages** automatically generated
3. **Consistent error format** across the plugin
4. **Sensitive data protection** built-in
5. **Better debugging** with rich context

### Checking Logged Errors

Logged errors are stored and can be retrieved:

```php
// Get recent error messages
$errors = WP_MCP_AI_Logger::get_recent_error_messages( 20 );

// Get recent activity (tool executions, chat interactions, etc.)
$activity = WP_MCP_AI_Logger::get_recent_activity_entries( 20, array( 'tool_execution' ) );
```

## Testing

Comprehensive test coverage has been added:

- **test-logger-enhancements.php**: Tests all severity levels and user-friendly error generation
- **test-error-handler.php**: Tests all error creation methods and logging behavior

Run tests:
```bash
vendor/bin/phpunit tests/test-logger-enhancements.php
vendor/bin/phpunit tests/test-error-handler.php
```

## Performance Considerations

### Logging Toggle

Logging can be enabled/disabled via WordPress admin:

**Settings → WP oOS → Enable Logging**

Or programmatically:
```php
$settings = WP_MCP_AI_Admin_Settings::get_settings();
$logging_enabled = ! empty( $settings['enable_logging'] );
```

### Log Rotation

Recent errors are automatically limited to:
- **50 most recent error/warning entries**
- **100 most recent activity entries**

Older entries are automatically pruned.

### Log Pruning

Clear all logs:
```php
WP_MCP_AI_Logger::prune_error_log();
```

## Backward Compatibility

All changes are backward compatible:

- Existing `WP_MCP_AI_Logger::log_error()` calls continue to work
- Old error codes still trigger user-friendly messages where applicable
- No breaking changes to public APIs

## Future Enhancements

Potential improvements for future phases:

1. **Log Level Filtering**: Allow admins to set minimum log level (e.g., only log ERROR and CRITICAL)
2. **External Log Integration**: Send critical errors to external monitoring services
3. **Error Reporting Dashboard**: Admin page to view and search logged errors
4. **Error Notifications**: Email/Slack notifications for critical errors
5. **Performance Monitoring**: Track API response times and failure rates
6. **Custom Error Templates**: Allow themes to customize error message display

## Related Documentation

- [WordPress Coding Standards](CODE-REVIEW-MASTER.md)
- [Best Practices](BEST_PRACTICES.md)
- [API Documentation](../../../reference/api/rest-api.md)
- [Tool Reference](../../../reference/tools/tool-reference.md)
