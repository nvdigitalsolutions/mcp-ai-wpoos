# Testing Guide: Tool Toggle Fix

## What Was Fixed
The tool toggle switches on the Tools Manager screen were not working due to a nonce verification error. This has been fixed by aligning the nonce action between JavaScript and PHP.

## Before the Fix
When clicking the toggle switch:
- ❌ Toggle would appear to work momentarily
- ❌ Then revert back to previous state
- ❌ Error message: "An error occurred while updating tool status"
- ❌ Browser console would show: 403 Forbidden or nonce verification failed

## After the Fix
When clicking the toggle switch:
- ✅ Toggle animates smoothly
- ✅ Status badge updates immediately
- ✅ Success message appears: "Tool disabled successfully" or "Tool enabled successfully"
- ✅ No errors in browser console
- ✅ Tool status persists after page refresh

## Manual Testing Steps

### 1. Access the Tools Manager
Navigate to: `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=tools`

Or: **WP Admin → WP oOS → Settings → Tools tab → Tools Manager subtab**

### 2. Locate a Tool to Test
Look for any tool in the list. Good candidates:
- **WordPress Core category**: `search_content`, `list_posts`, `create_post`
- **WordPress Plugins category**: `get_jetengine_items`, `get_woo_products`

### 3. Test Disabling a Tool
1. Find an **Enabled** tool (green "Enabled" badge)
2. Click the toggle switch to the left of the tool row
3. **Expected behavior**:
   - Toggle animates from right to left
   - Toggle background changes from blue (#0073aa) to gray (#ccc)
   - Status badge changes from green "Enabled" to gray "Disabled"
   - Success message appears at top: "Tool disabled successfully"
   - No errors in browser console (F12 → Console tab)

### 4. Test Enabling a Tool
1. Find a **Disabled** tool (gray "Disabled" badge) 
   - If you just disabled one, use that
2. Click the toggle switch
3. **Expected behavior**:
   - Toggle animates from left to right
   - Toggle background changes from gray to blue
   - Status badge changes from gray "Disabled" to green "Enabled"
   - Success message appears: "Tool enabled successfully"
   - No errors in browser console

### 5. Verify Persistence
1. After toggling a tool, refresh the page (F5)
2. Check that the tool status remains as you set it
3. The toggle position and status badge should match your last action

### 6. Test Edge Cases
- **Permission check**: Log in as a non-admin user and verify they cannot see/toggle tools
- **Multiple tools**: Toggle several tools in sequence to ensure no race conditions
- **Network issues**: With browser DevTools open, throttle network to "Slow 3G" and verify graceful handling

## Automated Testing

### Unit Tests
```bash
# Install dependencies (first time only)
composer install

# Run the tool toggle tests
composer run test tests/test-tool-toggle-ajax.php
```

**Expected output**: All 7 tests should pass
```
✓ test_non_admin_cannot_toggle_tools
✓ test_admin_can_disable_tool
✓ test_admin_can_enable_tool  
✓ test_missing_tool_slug_returns_error
✓ test_invalid_action_returns_error
✓ test_nonexistent_tool_returns_error
✓ test_invalid_nonce_is_rejected
```

## Browser Console Verification

### Before Fix (Failed Request)
```javascript
// Network tab shows:
POST /wp-admin/admin-ajax.php
Status: 403 Forbidden

// Console shows:
Error: An error occurred while updating tool status.
```

### After Fix (Successful Request)
```javascript
// Network tab shows:
POST /wp-admin/admin-ajax.php
Status: 200 OK
Response: {"success":true,"data":{"message":"Tool disabled successfully.","enabled":false}}

// Console shows:
(no errors)
```

## Technical Details

### AJAX Request (from `assets/js/tools-manager.js`)
```javascript
$.ajax({
    url: wpMcpAiAdmin.ajaxUrl,
    type: 'POST',
    data: {
        action: 'wp_mcp_ai_toggle_tool',
        nonce: wpMcpAiAdmin.nonce,    // Uses 'wp-mcp-ai-settings' action
        tool_slug: toolSlug,
        tool_action: action           // 'enable' or 'disable'
    }
});
```

### PHP Handler (from `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php`)
```php
public function handle_toggle_tool() {
    // NOW MATCHES JavaScript nonce action
    check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );
    
    // Verify user has permission
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( ... );
    }
    
    // Enable or disable the tool
    $registry = WP_MCP_AI_Tool_Registry::get_instance();
    if ( 'enable' === $action ) {
        $success = $registry->enable_tool( $tool_slug );
    } else {
        $success = $registry->disable_tool( $tool_slug );
    }
}
```

## Troubleshooting

### Issue: Toggle still doesn't work
**Possible causes**:
1. Browser cache - Hard refresh (Ctrl+Shift+R or Cmd+Shift+R)
2. Plugin cache - Deactivate and reactivate the plugin
3. JavaScript not loaded - Check browser console for errors
4. Nonce expired - Log out and log back in

### Issue: "Invalid nonce" error persists
**Solution**:
1. Clear all WordPress transients
2. Clear browser cookies for the site
3. Ensure `wp-mcp-ai-dashboard` script is enqueued on tools page

### Issue: Works but doesn't persist
**Possible causes**:
1. Database connection issue
2. Option table permissions
3. Check error logs for database errors

## Support
If issues persist after applying this fix, check:
- PHP error log: `/wp-content/debug.log`
- Browser console: F12 → Console tab
- Network tab: F12 → Network tab (filter by "admin-ajax.php")
