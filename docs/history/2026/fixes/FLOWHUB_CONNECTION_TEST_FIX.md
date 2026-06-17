# FlowHub Connection Test Critical Error Fix

## Issue
Users were experiencing a critical error (white screen/fatal error) when clicking the "Test" link for FlowHub connections in the Remote Sites admin page at:
```
/wp-admin/admin.php?page=wp-mcp-ai-remote-sites&action=test&connection_id=conn_pkjv3qfskjp5&_wpnonce=...
```

## Root Cause
The `WP_MCP_AI_Flowhub_Client` class uses two helper classes in its methods:
- `WP_MCP_AI_Logger::log_event()` and `WP_MCP_AI_Logger::log_error()`
- `WP_MCP_AI_HTTP::prepare_transport_error()`

These classes are loaded in the main plugin file (`mcp-ai-wpoos.php`), but when testing connections through the admin UI, the loading order could result in the Flowhub client being loaded before these dependencies were available, causing a fatal error:

```
Fatal error: Uncaught Error: Class 'WP_MCP_AI_Logger' not found in .../includes/class-wp-mcp-ai-flowhub-client.php
```

### Loading Sequence (Before Fix)
1. WordPress loads plugin
2. Admin action handler triggered (`handle_actions()`)
3. `test_connection()` method called
4. Flowhub client required: `require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';`
5. Flowhub client class defined
6. `get_inventory()` method called
7. **FATAL ERROR**: `WP_MCP_AI_Logger::log_event()` called but class not loaded

## Solution
Added explicit dependency loading at the top of both affected client files:

### Files Modified
1. `includes/class-wp-mcp-ai-flowhub-client.php`
2. `includes/class-wp-mcp-ai-payhere-client.php`

### Code Added
```php
// Ensure required classes are loaded.
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
    require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
}

if ( ! class_exists( 'WP_MCP_AI_HTTP' ) ) {
    require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-http-helper.php';
    require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-http.php';
}
```

This ensures that:
1. Dependencies are checked before the class is defined
2. If not already loaded, they are loaded immediately
3. The client class can safely use these helpers

## Testing
Added unit tests in `addons/pro/tests/test-remote-site-manager.php`:
- `test_flowhub_client_loads_dependencies()` - Verifies Flowhub client loads without errors
- `test_payhere_client_loads_dependencies()` - Verifies PayHere client loads without errors

### Manual Testing Steps
1. Navigate to **NV oOS → Remote Sites** in WordPress admin
2. Find a FlowHub connection in the list
3. Click the **Test** link
4. Expected result: Success message or specific API error (not a PHP fatal error)
5. The page should load successfully with feedback about the connection test

## Why PayHere Was Also Fixed
The PayHere client (`class-wp-mcp-ai-payhere-client.php`) has the same pattern:
- Uses `WP_MCP_AI_Logger` for logging
- Uses `WP_MCP_AI_HTTP` for error handling
- Could be called from tools or other contexts where dependencies might not be loaded

Although PayHere doesn't have a dedicated `test_connection()` method like FlowHub, fixing it prevents similar issues in other contexts.

## Impact
- **User-facing**: FlowHub connection testing now works reliably
- **Developer-facing**: Provides a pattern for other client classes
- **Backwards compatible**: No breaking changes, only defensive loading

## Related Connection Types
Other connection types (iSAMS, QuickBooks, EZuite ERP, Generic REST API) use the default WordPress REST API test method and don't have dedicated client classes, so they are not affected by this issue.

## Best Practices Learned
When creating client classes that:
1. Use helper/utility classes (Logger, HTTP, etc.)
2. Are loaded dynamically (via `require_once` in various contexts)
3. May be called from admin actions, tools, or other entry points

Always add defensive dependency loading at the top of the file to ensure required classes are available before the class definition.

## Files Changed
- `includes/class-wp-mcp-ai-flowhub-client.php` - Added dependency loading
- `includes/class-wp-mcp-ai-payhere-client.php` - Added dependency loading  
- `addons/pro/tests/test-remote-site-manager.php` - Added tests for dependency loading

## Deployment Notes
- No database changes required
- No settings changes required
- Safe to deploy to production immediately
- Fix is self-contained in the client files
