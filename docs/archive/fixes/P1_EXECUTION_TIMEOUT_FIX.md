# P1: PHP Execution Timeout and Private Network Blocking Fix

## Implementation Summary

This implementation fixes two critical issues preventing connections to local AI providers (Ollama, LM Studio), especially in scenarios where WordPress is hosted remotely (e.g., Cloudways) connecting to AI servers on private LANs (e.g., 192.168.2.222).

## Issues Resolved

### Issue 1: PHP Execution Timeout (P1 Badge)
**Problem**: AJAX handlers call `get_request_timeout(true)` to get 60-120 second HTTP timeouts, but code never increases PHP's `max_execution_time`. On hosts with default 30s limit, PHP kills the request with "Maximum execution time exceeded" before HTTP timeout completes.

**Solution**: Added `ensure_execution_time()` method to Resource Manager, called before all long HTTP requests.

### Issue 2: Private Network Connection Blocking
**Problem**: Connections to private IPs like 192.168.2.222 from remote WordPress (Cloudways) were being blocked.

**Solution**: HTTP Helper already handles this correctly. Enhanced user guidance in settings descriptions.

## Files Changed

- `includes/class-resource-manager.php` - Added execution time handling
- `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` - Updated 4 handlers
- `includes/class-wp-mcp-ai-ollama-client.php` - Updated timeout resolution
- `includes/class-wp-mcp-ai-lm-studio-client.php` - Updated timeout resolution  
- `includes/admin/sections/class-wp-mcp-ai-section-providers.php` - Enhanced descriptions
- `tests/test-execution-time-handling.php` - NEW comprehensive test suite

## Settings Decision

**NO new settings added** - Existing settings are sufficient. Enhanced descriptions provide guidance instead.

See full details in this file.
