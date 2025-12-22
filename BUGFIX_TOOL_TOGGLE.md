# Bug Fix: Tool Toggle Nonce Mismatch

## Issue
Users were unable to disable/enable tools from the Tools Manager screen at:
`/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=tools`

Error message: "An error occurred while updating tool status."

## Root Cause
There was a nonce verification mismatch between the JavaScript client and the PHP AJAX handler:

- **JavaScript** (in `assets/js/tools-manager.js`):
  - Sends AJAX request with `wpMcpAiAdmin.nonce`
  - This nonce is created with action `'wp-mcp-ai-settings'` in `class-wp-mcp-ai-settings-dashboard.php` line 438

- **PHP AJAX Handler** (in `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php`):
  - Was checking nonce with action `'wp_mcp_ai_admin'` (line 1476)
  - This caused `check_ajax_referer()` to fail and reject all toggle requests

## Solution
Changed the nonce verification in the AJAX handler from:
```php
check_ajax_referer( 'wp_mcp_ai_admin', 'nonce' );
```

To:
```php
check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );
```

## Files Changed
1. `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` - Fixed nonce verification
2. `tests/test-tool-toggle-ajax.php` - Updated all 6 unit tests to use correct nonce action

## Testing

### Manual Testing
1. Navigate to Tools Manager: `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=tools`
2. Find any tool with the toggle switch (e.g., "search_content" in WordPress Core category)
3. Click the toggle switch to disable the tool
4. Verify:
   - Toggle animates smoothly
   - Status badge changes from "Enabled" (green) to "Disabled" (gray)
   - Success message appears: "Tool disabled successfully"
5. Click toggle again to re-enable
6. Verify status changes back to "Enabled"

### Automated Testing
Run the tool toggle AJAX tests:
```bash
composer install
composer run test tests/test-tool-toggle-ajax.php
```

All 7 tests should pass:
- ✓ Non-admin users cannot toggle tools
- ✓ Admin can disable a tool
- ✓ Admin can enable a tool
- ✓ Missing tool slug returns error
- ✓ Invalid action returns error
- ✓ Non-existent tool returns error
- ✓ Invalid nonce is rejected

## Security Considerations
- The fix maintains the same level of security
- Only users with `manage_options` capability can toggle tools
- Nonce verification is still enforced, just with the correct action name
- No other functionality is affected by this change

## Related Code
The nonce is localized in `class-wp-mcp-ai-settings-dashboard.php`:
```php
wp_localize_script(
    'wp-mcp-ai-dashboard',
    'wpMcpAiAdmin',
    array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'wp-mcp-ai-settings' ),
        'i18n'    => array(
            'enabled'  => __( 'Enabled', 'wp-mcp-ai' ),
            'disabled' => __( 'Disabled', 'wp-mcp-ai' ),
        ),
    )
);
```

## Notes
- Other AJAX handlers in the codebase that use `'wp_mcp_ai_admin'` nonce are correct (e.g., RabbitMQ section) because they have their own inline JavaScript that creates the matching nonce
- The tool toggle handler is the only one using the `wpMcpAiAdmin` localized object from the settings dashboard
