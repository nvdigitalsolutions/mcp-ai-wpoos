# Project Management AI Assistant Modal Troubleshooting

This document helps troubleshoot issues with the AI Assistant modal in Project Management CPTs (Projects, Tasks, Events).

## Expected Behavior

1. When you select an assistant from the dropdown, the modal overlay should open immediately (no button click required)
2. The modal should display as a popup with a semi-transparent backdrop on top of the page (not inline)
3. The chat interface loads inside the modal directly via JavaScript
4. The "Chat with AI" button wrapper should remain hidden at all times

## Common Issues and Solutions

### Issue: Modal displays inline instead of as a popup

**Symptoms:**
- Modal content appears inside the metabox instead of overlaying the page
- No dark backdrop appears
- Modal doesn't center on screen

**Causes and Fixes:**
1. **JavaScript not loaded/running:**
   - Check browser console for JavaScript errors
   - Ensure `admin-pm-ai-assistant.js` is being loaded
   - Check that jQuery is available

2. **CSS not loaded:**
   - Ensure `admin-pm-ai-assistant.css` is being loaded
   - Check for CSS conflicts with other plugins
   - Clear browser cache and reload

3. **Modal not moved to body:**
   - The JavaScript should move the modal from the metabox to `<body>` on page load
   - Check browser console for "[PM AI Assistant] Modal moved to body and hidden" message
   - If not present, JavaScript initialization failed

### Issue: Button appears when selecting assistant (FIXED)

**Symptoms:**
- After selecting an assistant from dropdown, a "Chat with AI" button appears
- Modal doesn't open immediately
- Button has to be clicked to open modal

**Cause:**
- The button wrapper had inline style `display: none !important` which could be overridden by CSS or cleared by cached state
- JavaScript didn't explicitly hide the button wrapper on page load or selection

**Fix (Applied):**
- JavaScript now explicitly calls `$buildActionWrapper.hide()` at three points:
  1. On page initialization (line 56)
  2. When assistant is selected from dropdown (line 92)
  3. When assistant selection is cleared (line 79)
- Modal now opens immediately on dropdown change without showing button
- Multiple layers of protection ensure button stays hidden

### Issue: Modal doesn't open when selecting assistant

**Symptoms:**
- Dropdown selection does nothing
- Modal doesn't appear after selecting assistant
- No console messages

**Causes and Fixes:**
1. **JavaScript errors:**
   - Open browser console (F12)
   - Look for errors related to "PM AI Assistant"
   - Fix any JavaScript conflicts

2. **Event handler not attached:**
   - Check console for "[PM AI Assistant] Assistant selected" message after selecting from dropdown
   - Check console for "[PM AI Assistant] Opening modal directly..." message
   - If missing, jQuery might not be ready or event handler failed to attach

3. **Modal initialization issues:**
   - Check console for "[PM AI Assistant] Button wrapper hidden" message on page load
   - If missing, JavaScript initialization failed
   - Ensure modal element exists in DOM before JavaScript runs

### Issue: Chat interface doesn't load in modal

**Symptoms:**
- Modal opens but shows loading spinner forever
- Modal opens but shows error message "Assistant configuration was not found"
- Modal opens but chat container is empty

**Causes and Fixes:**
1. **Assistant configuration not passed to chat:**
   - Configuration is now built directly in JavaScript instead of PHP
   - Check console for: `[PM AI Assistant] Initializing chat interface for assistant: <id>`
   - Check console for: `[PM AI Assistant] Chat configuration created for instance: ...`
   - If these messages are missing, JavaScript initialization failed

2. **AJAX endpoint issues (DEPRECATED - Chat now initializes directly via JavaScript):**
   - Old behavior used AJAX to fetch chat HTML
   - New behavior builds chat HTML directly in JavaScript
   - No AJAX call needed for initial modal opening

3. **Shortcode class not available:**
   - Ensure `WP_MCP_AI_Shortcode` class exists
   - Check that core plugin is active

4. **Chat initialization errors:**
   - Check for JavaScript errors after chat HTML is injected
   - Look for `window.wpMcpAiChatInit` availability
   - Clear localStorage and try again

## Debugging Steps

### 1. Enable Console Logging

The JavaScript includes debug logging. Open browser console (F12) and look for messages prefixed with "[PM AI Assistant]".

Expected messages on page load:
```
[PM AI Assistant] Modal moved to body and hidden
[PM AI Assistant] Button wrapper hidden
```

Expected messages when selecting assistant:
```
[PM AI Assistant] Assistant selected: 331 Jamaica Relief
[PM AI Assistant] Opening modal directly...
```

Expected messages when opening modal:
```
[PM AI Assistant] Opening modal for assistant: 331 Jamaica Relief
[PM AI Assistant] Modal visibility class added, checking display...
[PM AI Assistant] Modal display style: block
[PM AI Assistant] Modal has visible class: true
[PM AI Assistant] Chat container is empty, initializing...
[PM AI Assistant] Initializing chat interface for assistant: 331
[PM AI Assistant] Chat configuration created for instance: wp-mcp-ai-pm-chat-331-xxxxx
[PM AI Assistant] Triggering chat initialization for: wp-mcp-ai-pm-chat-331-xxxxx
```

### 2. Check CSS Application

Inspect the modal element in browser dev tools:
- Modal should have class `wp-mcp-ai-pm-assistant-modal`
- It should have `display: none !important` from CSS
- When visible, it should have class `wp-mcp-ai-pm-assistant-modal--visible`
- It should have `display: block !important` when visible

### 3. Verify JavaScript Execution

In browser console, run:
```javascript
jQuery('#wp-mcp-ai-pm-assistant-modal').length
```
Should return `1` if modal exists.

```javascript
jQuery('#wp-mcp-ai-pm-assistant-modal').parent()[0].tagName
```
Should return `"BODY"` (uppercase) if modal was moved correctly.

### 4. Test AJAX Endpoint

In browser console, run:
```javascript
jQuery.post(ajaxurl, {
    action: 'wp_mcp_ai_pm_render_chat',
    assistant_id: 331,
    nonce: wpMcpAiPmAssistant.nonce
}, function(response) {
    console.log('AJAX Response:', response);
});
```

Should return success response with HTML content.

## Technical Details

### Modal Structure

The modal uses a fixed positioning overlay:
- Parent: `position: fixed` with `z-index: 100000`
- Backdrop: Covers full viewport with semi-transparent black
- Panel: Centered using `transform: translate(-50%, -50%)`

### CSS Classes

- `.wp-mcp-ai-pm-assistant-modal` - Base modal styles (hidden by default)
- `.wp-mcp-ai-pm-assistant-modal--visible` - Visible state (display: block)
- `.wp-mcp-ai-pm-assistant-modal-open` - Added to body when modal is open

### JavaScript Flow

1. Page loads → `initPmAiAssistant()` runs
2. Modal is moved from metabox to `<body>`
3. Button wrapper is explicitly hidden with jQuery `.hide()`
4. Inline styles removed from modal, CSS classes control visibility
5. User selects assistant → Modal opens immediately (no button shown)
6. Chat interface built directly in JavaScript → Injected into modal
7. Chat configuration created in JavaScript → Passed to chat instance
8. Chat initialized → User can interact

## Still Having Issues?

If none of the above helps:

1. Disable other plugins temporarily to check for conflicts
2. Switch to a default WordPress theme to check for theme conflicts
3. Clear all caches (browser, WordPress, server)
4. Check PHP error logs for server-side issues
5. Verify WordPress version is 6.0 or higher
6. Ensure PHP version is 7.4 or higher

## Reporting Bugs

When reporting modal issues, include:
- Browser and version
- WordPress version
- Active plugins list
- Console errors (if any)
- Network tab screenshot showing AJAX requests
- Screenshots of the issue
