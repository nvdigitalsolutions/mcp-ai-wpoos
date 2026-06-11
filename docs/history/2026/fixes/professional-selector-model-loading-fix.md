# Professional Selector Model Loading Fix

## Issue
The professional selector widget (used in Elementor and as a shortcode) was failing to load the AI model dropdown for logged-in users, showing the error "Failed to load configuration. Please try again." The browser console showed a 403 Forbidden error from `wp-admin/admin-ajax.php`.

## Root Cause
The issue occurred because:

1. **Missing AJAX Hook**: The `WP_MCP_AI_Professional_Selector_Shortcode` class only registered the `wp_ajax_nopriv_wp_mcp_ai_get_models_for_provider` hook (for logged-out users) but not the `wp_ajax_wp_mcp_ai_get_models_for_provider` hook (for logged-in users).

2. **Admin Handler Conflict**: When logged-in users made requests, WordPress routed them to the admin AJAX handler (`WP_MCP_AI_Admin_AJAX_Handlers::handle_get_models_for_provider`) instead of the professional selector's handler.

3. **Nonce Mismatch**: The admin handler expected a nonce created with action `'wp-mcp-ai-model-selector'`, but the professional selector JavaScript was sending a nonce created with action `'wp-mcp-ai-professional-selector'`.

4. **Permission Check**: The admin handler also required `'edit_posts'` capability, which not all frontend users have.

## Solution
Added the missing `wp_ajax` hook registration in `includes/class-wp-mcp-ai-professional-selector-shortcode.php`:

```php
// Before (line 45-46):
// Add nopriv hook for model selector (frontend access).
add_action( 'wp_ajax_nopriv_wp_mcp_ai_get_models_for_provider', array( $this, 'handle_get_models_for_provider' ) );

// After (line 45-47):
// Add hooks for model selector (both logged-in and frontend access).
add_action( 'wp_ajax_wp_mcp_ai_get_models_for_provider', array( $this, 'handle_get_models_for_provider' ) );
add_action( 'wp_ajax_nopriv_wp_mcp_ai_get_models_for_provider', array( $this, 'handle_get_models_for_provider' ) );
```

This ensures that:
- **Logged-in users** are routed to the professional selector's handler (which uses the correct nonce and has no special capability requirements beyond the nonce verification)
- **Logged-out users** continue to use the same handler through the `nopriv` hook
- Both user types use the same handler method, ensuring consistent behavior

## Files Changed
1. `includes/class-wp-mcp-ai-professional-selector-shortcode.php` - Added `wp_ajax` hook registration
2. `tests/test-professional-selector-model-loading.php` - New comprehensive test suite

## Tests Added
The test file includes:
- Hook registration verification for both logged-in and guest users
- Handler mapping verification (ensuring both hooks use the shortcode handler)
- Nonce validation tests for both user types
- Permission and error message validation
- Admin handler non-interference verification

## Related Code
- **JavaScript**: `assets/js/professional-selector.js` (lines 69-76, 140-164) - Sends AJAX requests with the professional selector nonce
- **Admin Handler**: `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` (lines 2383-2448) - Separate handler for admin model selector with different nonce
- **Elementor Widget**: `includes/elementor/class-wp-mcp-ai-elementor-professional-selector-widget.php` - Uses the shortcode

## Impact
This fix ensures the professional selector widget works correctly for:
- Logged-in users (all roles)
- Guest/anonymous users (when `allow_guests="true"`)
- Both shortcode and Elementor widget implementations

## Testing
To test the fix manually:
1. Add the professional selector shortcode or Elementor widget to a page
2. As a logged-in user, select a professional and provider
3. Verify the model dropdown populates without errors
4. Check browser console for no 403 errors
5. Repeat as a logged-out user (if guest access is enabled)

To run automated tests:
```bash
vendor/bin/phpunit tests/test-professional-selector-model-loading.php
```
