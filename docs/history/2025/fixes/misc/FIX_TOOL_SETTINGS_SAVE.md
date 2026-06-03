# Tool Compatibility Flags Save Issue - Fix Summary

## Issue
Users were unable to save changes to tool compatibility flags, receiving a "Failed to save tool settings" error message even when the operation should have succeeded.

## Root Cause
The AJAX handler method `handle_save_tool_settings()` in `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` requires both the capability flags save and force-sync save operations to return `true` for the overall operation to succeed:

```php
if ( $flags_saved && $sync_saved ) {
    // Success
} else {
    // Error: "Failed to save tool settings"
}
```

The problem is that WordPress's `update_option()` function returns `false` when the new value is identical to the existing value (i.e., when no change is made). This is documented WordPress behavior - `update_option()` only returns `true` when it actually updates the database.

### Example of the Bug
1. User sets capability flags for a tool
2. User clicks "Save" (first save succeeds)
3. User clicks "Save" again without making changes
4. Both `update_capability_flags()` and `set_force_sync()` call `update_option()`
5. `update_option()` returns `false` because values are unchanged
6. AJAX handler sees `false` and reports "Failed to save tool settings"

## Solution
Modified the `WP_MCP_AI_Tool_Settings_Manager` class to detect when values haven't changed and return `true` in those cases, since "no change needed" is a successful operation.

### Changes Made

**File:** `includes/services/class-wp-mcp-ai-tool-settings-manager.php`

#### Method: `update_capability_flags()`
- Store the old value before modifications
- After building the new value, compare it to the old value
- If identical, return `true` immediately (no update needed)
- Otherwise, call `update_option()` and return its result

```php
public static function update_capability_flags( $tool_slug, $flags ) {
    $all_custom_flags = get_option( self::CAPABILITY_FLAGS_OPTION, array() );
    $old_custom_flags = $all_custom_flags;  // Store old value

    if ( empty( $flags ) ) {
        unset( $all_custom_flags[ $tool_slug ] );
    } else {
        $all_custom_flags[ $tool_slug ] = array_map( 'sanitize_key', $flags );
    }

    // Check if the value actually changed
    if ( $all_custom_flags === $old_custom_flags ) {
        return true;  // No change needed = success
    }

    return update_option( self::CAPABILITY_FLAGS_OPTION, $all_custom_flags );
}
```

#### Method: `set_force_sync()`
- Same pattern: store old value, compare, return `true` if unchanged
- Only call `update_option()` if values differ

```php
public static function set_force_sync( $tool_slug, $force_sync ) {
    $force_sync_tools = get_option( self::FORCE_SYNC_OPTION, array() );
    $old_force_sync_tools = $force_sync_tools;  // Store old value

    if ( $force_sync ) {
        $force_sync_tools[ $tool_slug ] = true;
    } else {
        unset( $force_sync_tools[ $tool_slug ] );
    }

    // Check if the value actually changed
    if ( $force_sync_tools === $old_force_sync_tools ) {
        return true;  // No change needed = success
    }

    return update_option( self::FORCE_SYNC_OPTION, $force_sync_tools );
}
```

## Testing
Created comprehensive tests in `tests/test-tool-settings-save-unchanged.php`:
- Test saving identical values (the bug scenario)
- Test changing values
- Test clearing values
- Test multiple tools with same flags

Also created standalone verification scripts (in bin/, gitignored):
- `verify-tool-settings-fix.php` - Unit-level tests
- `verify-ajax-scenario.php` - AJAX handler flow tests

All tests pass successfully.

## Impact
- **Before Fix:** Saving unchanged tool settings would fail with error message
- **After Fix:** Saving unchanged tool settings succeeds (returns success message)
- **Behavior Change:** None - this only fixes the false-negative error case
- **Backward Compatibility:** Fully compatible - only changes internal return value logic

## Files Modified
1. `includes/services/class-wp-mcp-ai-tool-settings-manager.php` (12 lines added)
2. `tests/test-tool-settings-save-unchanged.php` (new file, 234 lines)

## Related Code
- AJAX Handler: `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php::handle_save_tool_settings()`
- JavaScript: `assets/js/admin-tool-orchestration.js`
- UI Renderer: `includes/admin/class-wp-mcp-ai-editable-capability-flags-renderer.php`
