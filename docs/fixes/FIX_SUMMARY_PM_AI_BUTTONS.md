# PM AI Assistant Modal Button Functionality Fix

## Issue Description

The PM AI Assistant modal displays correctly after the previous CSS fix, but the buttons (Quick Actions and "Open AI Assistant") do not work. When attempting to use the assistant in the project/task/event edit screens, clicking buttons produces no response, suggesting the frontend JavaScript for the chat client is not properly initialized.

## Root Cause

The metabox enqueues the chat bundle script (`wp-mcp-ai-chat` / `chat-bundle.min.js`), which provides the `window.wpMcpAiChatInit.init()` function required to initialize the chat interface. However, the chat bundle depends on the `window.wpMcpAiChat` global object containing:
- REST API endpoints (restUrl, toolsEndpoint, filesEndpoint, etc.)
- Authentication nonce for API requests
- User context and configuration settings

While the `WP_MCP_AI_Shortcode::register_assets()` method is responsible for localizing this data, there was no guarantee that this localization would be present in the admin context when the metabox loads.

### Why This Happens

1. The shortcode's `register_assets()` is hooked to the `init` action
2. During `admin_enqueue_scripts`, the metabox enqueues the chat script
3. If the shortcode instance hasn't been created yet, or if something prevents the localization from attaching, the `wpMcpAiChat` global is missing
4. When the unified PM assistant script tries to initialize the chat, it fails because `wpMcpAiChatInit.init()` requires the localization data
5. Users see working buttons but no response when clicking them

## Solution Implemented

### 1. Added `ensure_chat_localization()` Method

Created a new private method in `WP_MCP_AI_Project_Management_AI_Assistant_Metabox` that:

```php
private function ensure_chat_localization() {
    // Check if localization was already done
    $wp_scripts = wp_scripts();
    if ( isset( $wp_scripts->registered[ WP_MCP_AI_Shortcode::SCRIPT_HANDLE ] ) ) {
        $script_data = $wp_scripts->registered[ WP_MCP_AI_Shortcode::SCRIPT_HANDLE ];
        // If wpMcpAiChat already exists in the script data, return early
        if ( isset( $script_data->extra['data'] ) && strpos( $script_data->extra['data'], 'wpMcpAiChat' ) !== false ) {
            return;
        }
    }
    
    // Add localization if not present
    wp_localize_script(
        WP_MCP_AI_Shortcode::SCRIPT_HANDLE,
        'wpMcpAiChat',
        array(
            'restUrl'             => /* ... REST API base URL ... */,
            'uploadEndpoint'      => /* ... media upload endpoint ... */,
            'filesEndpoint'       => /* ... files endpoint ... */,
            'toolsEndpoint'       => /* ... tools endpoint ... */,
            'transcriptsEndpoint' => /* ... transcripts endpoint ... */,
            'historyPerPage'      => 20,
            'currentUserId'       => get_current_user_id(),
            'nonce'               => wp_create_nonce( 'wp_rest' ),
            'showUsageCosts'      => /* ... from settings ... */,
            'asyncToolTimeout'    => /* ... from settings ... */,
            'strings'             => array(
                'placeholder' => __( 'Ask something…', 'wp-mcp-ai' ),
            ),
        )
    );
}
```

### 2. Called Method After Enqueuing Chat Script

Modified the `enqueue_assets()` method to call `ensure_chat_localization()` immediately after enqueuing the chat script:

```php
// Enqueue chat assets.
wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );

// Ensure chat script localization is available in admin context.
$this->ensure_chat_localization();
```

### 3. Added Test Coverage

Created `test_chat_localization_is_available()` in `test-pm-ai-assistant-metabox.php` to verify:
- The chat script is enqueued
- Localization data is attached to the script
- The `wpMcpAiChat` global name is present
- Required keys like `restUrl` and `nonce` are included

## How It Works

1. **Enqueue Phase**: When the metabox loads on a project/task/event edit screen, it enqueues the chat bundle script.

2. **Localization Check**: Immediately after enqueueing, `ensure_chat_localization()` is called.

3. **Conditional Localization**: The method checks if `wpMcpAiChat` data is already attached to the script by inspecting `$wp_scripts->registered[SCRIPT_HANDLE]->extra['data']`.

4. **Fallback Localization**: If the data is not present, the method adds it using `wp_localize_script()`, ensuring WordPress will output the JavaScript global when rendering the page.

5. **Chat Initialization**: When the page loads:
   - The `admin-pm-ai-assistant-unified.js` script initializes
   - When a user selects an assistant and clicks "Open AI Assistant"
   - The script creates the chat HTML and configuration
   - It calls `window.wpMcpAiChatInit.init()` to initialize the chat interface
   - The chat bundle has access to `window.wpMcpAiChat` with all required endpoints and auth data

## Benefits

- **Defensive Programming**: Ensures localization is present even if the shortcode's normal registration path doesn't execute
- **No Duplication**: Checks for existing localization before adding it
- **Minimal Overhead**: Only adds data if it's missing
- **Maintains Compatibility**: Doesn't interfere with the shortcode's normal operation on frontend pages

## Testing

### Manual Testing

1. Navigate to a project, task, or event edit screen in wp-admin
2. Open browser DevTools console
3. Select an assistant from the dropdown
4. Verify buttons become enabled
5. Click "Open AI Assistant" button
6. Verify modal opens with chat interface
7. Type a message and send
8. Verify message is sent to the assistant and response is received

### Automated Testing

Run the PM AI Assistant metabox test suite:

```bash
vendor/bin/phpunit tests/test-pm-ai-assistant-metabox.php
```

The new `test_chat_localization_is_available()` test verifies:
- Chat script is properly enqueued
- Localization data is attached
- Required configuration keys are present

## Files Changed

### Modified Files

1. **`addons/pro/includes/metaboxes/class-wp-mcp-ai-project-management-ai-assistant-metabox.php`**
   - Added `ensure_chat_localization()` method (lines 469-517)
   - Modified `enqueue_assets()` to call new method (line 91)
   - Added detailed comments explaining the purpose

2. **`tests/test-pm-ai-assistant-metabox.php`**
   - Added `test_chat_localization_is_available()` test (lines 291-343)
   - Verifies script enqueuing and localization data presence

## Related Issues

- **PM_AI_ASSISTANT_MODAL_FIX.md**: Previous fix for modal CSS display
- **PM_ASSISTANT_DEBUG_GUIDE.md**: Debugging guide that helped identify this issue
- The debug guide specifically mentions checking for "Missing wpMcpAiChat Global" and "Missing Nonce" issues, which this fix addresses

## Future Considerations

- Consider making `WP_MCP_AI_Shortcode::register_assets()` a static method to avoid the need for temporary instances
- Consider creating a shared method for chat localization that can be called by both the shortcode and metaboxes
- Consider adding console warnings in the unified script when `wpMcpAiChat` is missing to help diagnose similar issues

## Rollback Instructions

If this fix causes any issues, it can be safely rolled back by:

1. Removing the `ensure_chat_localization()` method call from line 91 of the metabox file
2. Removing the `ensure_chat_localization()` method itself (lines 469-517)
3. The previous CSS fix will remain functional, though buttons still won't work without the localization

The fix is isolated to the metabox class and doesn't modify any core chat functionality, making it safe to remove if needed.
