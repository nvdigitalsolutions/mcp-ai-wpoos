# Fix: Chat Configuration Missing Expected PHP-Localized Values

## Issue

Chat widgets on certain pages (like team pages and admin pages) showed this error in the browser console:

```
[NV oOS] Chat configuration missing expected PHP-localized values. Check shortcode setup and plugin settings.
Array(1)
0: "toolsEndpoint"
length: 1
```

The error appeared in two contexts:
1. **Frontend pages** with chat shortcodes (team pages, etc.)
2. **Admin pages** with chat modals (Build Assistant page, Test pages)

## Root Cause

### Frontend Issue
The per-instance configuration array in `WP_MCP_AI_Shortcode::render_shortcode()` (around line 693) was missing the `uploadEndpoint` key.

### Admin Pages Issue
Admin pages that use chat modals (`WP_MCP_AI_Build_Assistant_Page` and `WP_MCP_AI_Admin_Test_Page_Base`) were missing the `toolsEndpoint` key in their global config localization.

The chat JavaScript code has two layers of configuration:

1. **Global config** (`window.wpMcpAiChat`) - Set via `wp_localize_script()` in `register_assets()`
2. **Per-instance config** (`window.wpMcpAiChatInstances[id]`) - Set via inline script for each shortcode instance

When initializing a chat instance, the JavaScript merges the per-instance config with the global config as fallback:

```javascript
const instanceConfig = Object.assign({}, config);
if (!instanceConfig.uploadEndpoint) {
    instanceConfig.uploadEndpoint = globalConfig.uploadEndpoint || '';
}
```

If the per-instance config is missing `uploadEndpoint`, it falls back to the global config. In certain loading contexts (e.g., when scripts load in a specific order, or when there are multiple instances), if the global config is not properly initialized or is empty, the fallback produces an empty string, causing the warning.

## Solution

### Frontend Fix
Added `uploadEndpoint` to the per-instance configuration array:

```php
$config = array(
    'id'                    => $instance_id,
    'assistantId'           => $assistant_id,
    'userId'                => get_current_user_id(),
    'restUrl'               => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE ) ) ) ),
    'uploadEndpoint'        => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( 'wp/v2/media' ) ) ), // ADDED
    'messagesEndpoint'      => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat-client' ) ) ),
    'toolsEndpoint'         => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ) ) ),
    // ... other config values
);
```

### Admin Pages Fix
Added `toolsEndpoint` to the global configuration in admin pages:

```php
wp_localize_script(
    'wp-mcp-ai-chat',
    'wpMcpAiChat',
    array(
        'restUrl'             => esc_url_raw( $this->normalise_rest_url( rest_url( $rest_namespace ) ) ),
        'uploadEndpoint'      => esc_url_raw( $this->normalise_rest_url( rest_url( 'wp/v2/media' ) ) ),
        'filesEndpoint'       => esc_url_raw( trailingslashit( $this->normalise_rest_url( rest_url( $rest_namespace . '/files' ) ) ) ),
        'toolsEndpoint'       => esc_url_raw( $this->normalise_rest_url( rest_url( $rest_namespace . '/tools' ) ) ), // ADDED
        'transcriptsEndpoint' => esc_url_raw( $this->normalise_rest_url( rest_url( $rest_namespace . '/chat-transcripts' ) ) ),
        // ... other config values
    )
);
```

## Changes Made

1. **includes/class-wp-mcp-ai-shortcode.php (line 698)**: Added `uploadEndpoint` to per-instance config array
2. **includes/admin/class-wp-mcp-ai-build-assistant-page.php (line 167)**: Added `toolsEndpoint` to Build Assistant page global config
3. **includes/admin/class-wp-mcp-ai-admin-test-page-base.php (line 188)**: Added `toolsEndpoint` to Test pages base class global config
4. **tests/test-shortcodes.php**: Added `test_per_instance_config_includes_required_endpoints()` to verify all required endpoints are present in per-instance config

## Testing

Run the new test to verify the fix:

```bash
# Using composer (if configured):
composer run test -- tests/test-shortcodes.php --filter test_per_instance_config_includes_required_endpoints

# Or directly with PHPUnit:
./vendor/bin/phpunit tests/test-shortcodes.php --filter test_per_instance_config_includes_required_endpoints
```

## Why This Fixes the Issue

### Frontend Fix
By ensuring each chat instance has its own complete configuration including `uploadEndpoint`, we eliminate the dependency on the global config fallback. Each instance is now self-sufficient and will work correctly even if:

- The global config is incomplete
- Scripts load in a different order
- Multiple instances exist on the same page
- The page is loaded in different contexts (Elementor editor, regular page, etc.)

### Admin Pages Fix
By adding `toolsEndpoint` to the global config in admin pages, chat modals/popups can now:

- Execute tools like transcription and voice chat
- Access the tools REST API endpoint
- Function properly in the Build Assistant page prompt tab
- Work correctly in all Test pages (Assistant, Profession, Team)

## Related Files

- `includes/class-wp-mcp-ai-shortcode.php` - Shortcode renderer with per-instance config
- `includes/admin/class-wp-mcp-ai-build-assistant-page.php` - Build Assistant page with chat modal
- `includes/admin/class-wp-mcp-ai-admin-test-page-base.php` - Base class for Test pages with chat modals
- `assets/js/chat.js` - Chat JavaScript that merges configs
- `tests/test-shortcodes.php` - Test suite for shortcode functionality

## Prevention

When adding new REST endpoints or configuration values:

1. Add them to BOTH global config (in `register_assets()`) AND per-instance config (in `render_shortcode()`)
2. Add corresponding tests to verify the values are present
3. Ensure the JavaScript code has proper fallback logic

## References

- Issue: "error on test team page"
- Commit: "Add missing uploadEndpoint to per-instance chat configuration"
- Test commit: "Add test to verify per-instance config includes all required endpoints"
