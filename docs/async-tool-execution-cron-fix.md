# Async Tool Execution Cron Fix

## Problem

Video creation and other async tool execution was failing because the `wp_mcp_ai_async_tool_execution` cron hook was not executing. Jobs would be queued but would never run, causing video generation to stall indefinitely.

## Root Cause

The issue was in the root security key system's `can_initialize()` method. When the root security key was required (enabled in plugin settings), the method would block plugin initialization in all non-admin contexts:

```php
public function can_initialize() {
    // If key requirement is not enabled, always allow initialization.
    if ( ! $this->is_key_required() ) {
        return true;
    }

    // If in admin, allow showing the unlock interface.
    if ( is_admin() ) {
        return true;
    }

    // Block initialization for non-admin contexts when key is required.
    return false;  // <-- This blocked cron execution!
}
```

**The Problem**: WordPress cron runs in a non-admin context where `is_admin()` returns `false`. With the security key required, this caused the `WP_MCP_AI::bootstrap()` method to return early (line 727-731 of wp-mcp-ai.php), which meant:

1. The `wp_mcp_ai_bootstrapped` action was never fired
2. The `wp_mcp_ai_init_async_executor()` function was never called
3. The async tool executor's `init()` method was never called
4. The cron hook handler was never registered
5. Scheduled async jobs could never execute

## Solution

### 1. Allow Plugin Initialization During Cron (Primary Fix)

Modified `WP_MCP_AI_Root_Security_Key::can_initialize()` to explicitly allow initialization during WordPress cron execution:

```php
// Allow initialization during WordPress cron execution.
// Cron jobs (including async tool execution) need to run even when
// the root security key is required. Cron runs in a non-admin,
// non-user context, so we need to explicitly allow it.
if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
    return true;
}
```

This ensures that when WordPress cron runs (`DOING_CRON` constant is `true`), the plugin can fully initialize and register the async tool execution handler.

### 2. Make Executor Init Idempotent (Defensive Fix)

Added a static flag to `WP_MCP_AI_Tool_Async_Executor` to track hook registration and prevent duplicate registrations:

```php
/**
 * Static flag to track if hooks have been registered.
 * Ensures init() is idempotent and can be called multiple times safely.
 */
protected static $hooks_registered = false;

public function init() {
    // Only register hooks once per request, even if called multiple times.
    if ( self::$hooks_registered ) {
        return;
    }

    add_action( self::CRON_HOOK, array( $this, 'execute_async_tool' ), 10, 1 );
    
    // ... rest of initialization ...
    
    // Mark hooks as registered for this request.
    self::$hooks_registered = true;
}
```

This makes the `init()` method safe to call multiple times, preventing duplicate hook registrations while ensuring hooks are always registered when needed.

## Execution Flow (After Fix)

### Normal Request
1. Plugin file loaded
2. `plugins_loaded` fires at priority 20
3. `wp_mcp_ai_bootstrap()` called
4. Security key check: `is_admin()` = true → allowed
5. `wp_mcp_ai_bootstrapped` action fires
6. Async executor initialized and hook registered

### WordPress Cron Request
1. WordPress cron starts (`DOING_CRON` = true)
2. Plugins loaded
3. `plugins_loaded` fires at priority 20
4. `wp_mcp_ai_bootstrap()` called
5. Security key check: **`DOING_CRON` = true → allowed** ✓ (NEW)
6. `wp_mcp_ai_bootstrapped` action fires
7. Async executor initialized and hook registered
8. Cron can execute `wp_mcp_ai_async_tool_execution` jobs

### Frontend Request (with security key required)
1. Plugin file loaded
2. `plugins_loaded` fires at priority 20
3. `wp_mcp_ai_bootstrap()` called
4. Security key check: `is_admin()` = false, `DOING_CRON` = false → blocked
5. Bootstrap returns early (prevents frontend access)
6. Frontend remains protected ✓

## Security Implications

The fix maintains security while enabling cron functionality:

- **Frontend Protection**: Frontend requests remain blocked when security key is required
- **Cron Access**: Cron jobs can execute (necessary for async operations)
- **Admin Access**: Admin interface allows key entry and verification
- **API Protection**: REST API endpoints remain protected (separate authentication)

Cron execution is inherently safe because:
1. Cron jobs don't expose data to end users
2. They run server-side with no user interaction
3. Job data comes from the database (already validated when queued)
4. Cron is triggered by WordPress core, not external requests

## Testing

### Manual Verification
Run the included test script to verify the logic:
```bash
php /tmp/test_cron_flow.php
```

Expected output confirms cron is now allowed while frontend is blocked.

### Automated Tests
New test file: `tests/test-async-executor-cron-initialization.php`

Tests verify:
1. Bootstrap is allowed during cron with security key enabled
2. Async executor can initialize during cron execution
3. Init method is idempotent (no duplicate registrations)

Run tests:
```bash
composer test tests/test-async-executor-cron-initialization.php
```

## Files Modified

1. **includes/class-wp-mcp-ai-root-security-key.php**
   - Added `DOING_CRON` check to `can_initialize()` method

2. **includes/services/class-wp-mcp-ai-tool-async-executor.php**
   - Added `$hooks_registered` static flag
   - Made `init()` method idempotent

3. **tests/test-async-executor-cron-initialization.php** (new)
   - Comprehensive tests for the fix

## Impact

This fix resolves:
- Video generation failures (primary issue)
- Any async tool execution via `wp_mcp_ai_async_tool_execution` hook
- Background job processing when security key is enabled
- Long-running operations that use the async executor

## Future Considerations

1. **Monitor cron execution**: Use WP-CLI or admin UI to verify cron jobs are running:
   ```bash
   wp cron event list
   wp cron test
   ```

2. **Security key best practices**: 
   - Only enable when needed (sensitive production environments)
   - Document that enabling blocks frontend but allows cron
   - Consider separate "cron key" for future enhancement

3. **Error logging**: The fix adds no new error logging. Consider adding debug logging for cron initialization in future updates.

## Rollback Plan

If issues arise, the fix can be safely reverted without data loss:
```bash
git revert 768ae76
```

The security key system will return to blocking all non-admin contexts, which may be desired in some environments.
