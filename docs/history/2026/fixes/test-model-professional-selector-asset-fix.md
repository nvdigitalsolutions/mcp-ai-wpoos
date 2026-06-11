# Test Model Professional Selector Asset Fix

## Problem
The Test Model admin page (`edit.php?post_type=mcp_ai_profession&page=wp-mcp-ai-test-model`) was not properly rendering JavaScript and CSS for the professional selector shortcode embedded in the page.

## Root Cause
The professional selector JavaScript (`wp-mcp-ai-professional-selector`) has a dependency on the chat script (`wp-mcp-ai-chat`), but the test model page was only enqueuing the professional selector assets. This meant the chat script was never loaded, causing JavaScript errors and preventing the professional selector from initializing properly.

The dependency chain should be:
```
wp-mcp-ai-chat (base chat functionality)
  └── wp-mcp-ai-professional-selector (depends on chat)
      └── wp-mcp-ai-admin-test-model (page-specific styles)
```

## Solution
Modified `includes/admin/class-wp-mcp-ai-admin-test-model.php` to enqueue the chat shortcode assets (both JavaScript and CSS) before enqueuing the professional selector assets in the `enqueue_assets()` method.

### Code Changes
```php
// Enqueue chat shortcode assets (required dependency for professional selector).
$dependencies = array();
if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
    wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
    wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
}

// Enqueue professional selector shortcode assets.
if ( class_exists( 'WP_MCP_AI_Professional_Selector_Shortcode' ) ) {
    wp_enqueue_style( WP_MCP_AI_Professional_Selector_Shortcode::STYLE_HANDLE );
    wp_enqueue_script( WP_MCP_AI_Professional_Selector_Shortcode::SCRIPT_HANDLE );
    $dependencies[] = WP_MCP_AI_Professional_Selector_Shortcode::STYLE_HANDLE;
}
```

## Testing
Created comprehensive test suite in `tests/test-admin-test-model-assets.php` that verifies:
- Chat assets (JS and CSS) are enqueued on the test model page
- Professional selector assets (JS and CSS) are enqueued
- Test model specific assets are enqueued
- Assets are NOT enqueued on other admin pages
- Script dependencies are in the correct order

## Files Modified
1. `includes/admin/class-wp-mcp-ai-admin-test-model.php` - Added chat asset enqueuing
2. `tests/test-admin-test-model-assets.php` - New test file

## Impact
- Fixes JavaScript and CSS rendering on the test model page
- Ensures the professional selector shortcode works properly in admin context
- No breaking changes to existing functionality
- Minimal code changes (5 lines added)

## Related Issues
This is similar to how other admin pages that use shortcodes handle asset dependencies. The pattern is now consistent across:
- Test Assistant page (already working)
- Test Profession page (already working)
- Test Model page (fixed by this change)

## Date
2026-01-27
