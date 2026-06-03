# PM Assistant Modal Chat - Block Editor Fix

## Overview

This document explains the fix implemented for the PM Assistant modal chat not working correctly in the WordPress block editor (Gutenberg).

## Problem Description

The PM Assistant modal chat was not initializing correctly when editing Projects, Tasks, or Events in the block editor. The issue manifested as:

- The assistant selector dropdown appearing but doing nothing when an assistant was selected
- No modal appearing when clicking on an assistant
- Console errors indicating that required DOM elements could not be found
- The chat interface not loading in the metabox

### Root Cause

The block editor (Gutenberg) loads metaboxes asynchronously using React, which means they are rendered **after** the standard `document.ready` event fires. The original implementation used jQuery's `$(document).ready()` which executed before the metabox HTML was available in the DOM.

## Solution

The fix implements a multi-layered approach to ensure compatibility with both classic and block editors:

### 1. Block Editor Detection

```javascript
function isBlockEditorActive() {
    return typeof wp !== 'undefined' && 
           wp.data && 
           typeof wp.data.select === 'function' &&
           wp.data.select('core/editor') !== undefined;
}
```

This function checks for the presence of the block editor's data store.

### 2. Metabox Polling

```javascript
function waitForMetabox(callback, maxAttempts) {
    // Polls for metabox elements with exponential backoff
    // 100ms initial delay, increases to 500ms max
    // Default 50 attempts = ~10 seconds max wait time
}
```

The polling mechanism:
- Checks for required DOM elements at regular intervals
- Uses exponential backoff to avoid excessive DOM queries
- Provides comprehensive logging for debugging
- Times out after a configurable number of attempts

### 3. WordPress wp.domReady Hook

When available (WordPress 5.0+), the script uses the `wp.domReady` hook which is specifically designed for block editor compatibility:

```javascript
if (typeof wp !== 'undefined' && wp.domReady) {
    wp.domReady(function() {
        waitForMetabox(initPmAiAssistant);
    });
}
```

### 4. Hybrid Approach

For classic editor or when block editor detection is uncertain:

```javascript
$(document).ready(function() {
    const $selector = $('#wp-mcp-ai-pm-assistant-select');
    
    if ($selector.length) {
        // Elements exist, initialize immediately
        initPmAiAssistant();
    } else {
        // Elements don't exist yet, poll for them
        waitForMetabox(initPmAiAssistant, 30);
    }
});
```

### 5. Script Dependencies

The PHP side was updated to include `wp-dom-ready` as a script dependency when available:

```php
$script_dependencies = array( 'jquery', WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
if ( wp_script_is( 'wp-dom-ready', 'registered' ) ) {
    $script_dependencies[] = 'wp-dom-ready';
}
```

## Debugging

### Enable Console Logging

The script includes comprehensive console logging. Open your browser's developer tools (F12) and look for messages prefixed with `[PM AI Assistant]`.

#### Successful Initialization

```
[PM AI Assistant] Script file loaded at: 2026-01-05T22:33:41.629Z
[PM AI Assistant] Determining editor type...
[PM AI Assistant] wp object available? true
[PM AI Assistant] wp.domReady available? true
[PM AI Assistant] wp.data available? true
[PM AI Assistant] Block editor detected, using specialized initialization
[PM AI Assistant] Using wp.domReady hook
[PM AI Assistant] ⚡ wp.domReady fired
[PM AI Assistant] Polling attempt 1/50, delay: 100ms
[PM AI Assistant] Found elements: {selector: 1, modal: 1, chatContainer: 1}
[PM AI Assistant] ✓ All required elements found after 1 attempts
[PM AI Assistant] initPmAiAssistant() function called
[PM AI Assistant] ✓ Initialization successful, all elements found
```

#### Troubleshooting Failed Initialization

If you see timeout messages:

```
[PM AI Assistant] TIMEOUT: Metabox elements not found after 50 attempts
```

This indicates:
1. The metabox HTML is not being rendered (check if Project Management is enabled in settings)
2. Post type is not one of the supported types (mcp_ai_project, mcp_ai_task, mcp_ai_event)
3. There's a conflict with another plugin preventing metabox rendering

### Common Issues

#### Issue: "CRITICAL: Required elements not found"

**Cause**: The metabox HTML was not rendered on the page.

**Solutions**:
1. Verify Project Management is enabled: Settings → NV oOS → Enable Project Management
2. Check you're editing a Project, Task, or Event post type
3. Check for JavaScript errors that might prevent rendering
4. Ensure the Pro addon is active and loaded

#### Issue: Modal appears but chat doesn't initialize

**Cause**: The chat bundle script (`wp-mcp-ai-chat`) is not loaded or initialized.

**Solutions**:
1. Check browser console for errors from `chat-bundle.min.js`
2. Verify the chat bundle script is enqueued: `wp_script_is('wp-mcp-ai-chat', 'enqueued')`
3. Check that `window.wpMcpAiChatInit.init` function exists
4. Look for conflicts with other plugins that might override global `wp` object

#### Issue: Works in classic editor but not block editor

**Cause**: The `wp-dom-ready` script is not registered or the block editor detection is failing.

**Solutions**:
1. Update to WordPress 5.0 or higher (required for block editor)
2. Check console for block editor detection messages
3. Verify `wp.data.select('core/editor')` returns a value in console
4. Try disabling the block editor temporarily to confirm the classic editor works

## Testing

### Manual Testing Checklist

#### Classic Editor
1. [ ] Create/edit a Project post
2. [ ] Verify metabox appears in sidebar
3. [ ] Select an assistant from dropdown
4. [ ] Verify modal opens immediately
5. [ ] Test chat functionality

#### Block Editor
1. [ ] Create/edit a Project post
2. [ ] Wait for metabox to load (may take 1-2 seconds)
3. [ ] Verify metabox appears in sidebar
4. [ ] Select an assistant from dropdown
5. [ ] Verify modal opens (may take 1-2 seconds)
6. [ ] Test chat functionality
7. [ ] Check browser console for successful initialization messages

#### Both Editors
- [ ] Test with Tasks
- [ ] Test with Events
- [ ] Test modal close button
- [ ] Test backdrop click to close
- [ ] Test Escape key to close
- [ ] Test chat input and message sending
- [ ] Verify modal doesn't interfere with post saving

## Performance Considerations

### Polling Impact

The polling mechanism is designed to be efficient:
- **Initial delay**: 100ms (fast initial check)
- **Exponential backoff**: Delays increase to 500ms max (reduces CPU usage)
- **Max attempts**: 50 attempts = ~10 seconds max wait
- **Early termination**: Stops immediately when elements are found

### Script Loading

- Scripts load in footer to avoid blocking page render
- Dependencies ensure proper load order
- No synchronous XHR or blocking operations

## Future Improvements

Potential enhancements for future versions:

1. **MutationObserver**: Replace polling with DOM mutation observer for more efficient detection
2. **Custom Event**: Fire a custom event when metabox is ready to avoid polling entirely
3. **Block Editor Integration**: Create a native block editor component instead of using metabox
4. **Preact/React**: Convert to proper React component for better block editor integration
5. **Lazy Loading**: Load chat bundle only when modal is opened to reduce initial page weight

## References

- [WordPress Block Editor Handbook](https://developer.wordpress.org/block-editor/)
- [wp.domReady Documentation](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-dom-ready/)
- [WordPress Script Dependencies](https://developer.wordpress.org/reference/functions/wp_enqueue_script/)
- [React in WordPress](https://developer.wordpress.org/block-editor/how-to-guides/javascript/working-with-javascript/)

## Support

If you encounter issues not covered in this document:

1. Check the browser console for error messages
2. Review the [Troubleshooting Guide](deployment-troubleshooting.md)
3. Open an issue on GitHub with:
   - Browser and WordPress versions
   - Editor type (classic or block)
   - Console log output
   - Steps to reproduce

## Version History

- **v1.1.0**: Initial fix for block editor compatibility (January 2026)
  - Added block editor detection
  - Implemented metabox polling with exponential backoff
  - Added wp-dom-ready dependency
  - Enhanced logging and error handling
