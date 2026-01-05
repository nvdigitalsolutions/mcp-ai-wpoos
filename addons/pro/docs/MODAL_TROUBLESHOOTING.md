# Project Management AI Assistant Modal Troubleshooting

This document helps troubleshoot issues with the AI Assistant modal in Project Management CPTs (Projects, Tasks, Events).

## Expected Behavior

1. When you select an assistant from the dropdown, a "Chat with AI" button appears
2. When you click the button, a modal overlay should appear on top of the page (not inline)
3. The modal should display as a popup with a semi-transparent backdrop
4. The chat interface loads inside the modal via AJAX

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

### Issue: Modal doesn't open when clicking button

**Symptoms:**
- Button appears after selecting assistant
- Clicking button does nothing
- No modal appears

**Causes and Fixes:**
1. **JavaScript errors:**
   - Open browser console (F12)
   - Look for errors related to "PM AI Assistant"
   - Fix any JavaScript conflicts

2. **Event handler not attached:**
   - Check console for "[PM AI Assistant] Assistant selected" message
   - If missing, jQuery might not be ready

3. **AJAX failures:**
   - Check console for AJAX errors
   - Verify `wpMcpAiPmAssistant` is defined in page source
   - Check that nonce is valid

### Issue: Chat interface doesn't load in modal

**Symptoms:**
- Modal opens but shows loading spinner forever
- Modal opens but shows error message "Assistant configuration was not found"
- Modal opens but chat container is empty

**Causes and Fixes:**
1. **Assistant configuration not passed to chat (FIXED):**
   - Previous issue: Inline scripts from `wp_add_inline_script()` don't execute in AJAX context
   - Fix: Configuration now extracted from PHP and injected via JavaScript
   - Check console for: `[PM AI Assistant] Chat configuration injected for instance: ...`
   - Check console for: `[PM AI Assistant] Assistant ID: <number>`
   - If these messages are missing, the fix may not be working

2. **AJAX endpoint not available:**
   - Check Network tab in browser console
   - Look for `admin-ajax.php` request
   - Verify response is successful (200 status)

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
```

Expected messages when selecting assistant:
```
[PM AI Assistant] Assistant selected: 331 Jamaica Relief
```

Expected messages when opening modal:
```
[PM AI Assistant] Opening modal for assistant: 331 Jamaica Relief
[PM AI Assistant] Chat container is empty, initializing...
[PM AI Assistant] Chat configuration injected for instance: wp-mcp-ai-chat-xxxxx
[PM AI Assistant] Assistant ID: 331
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
3. Inline styles removed, CSS classes control visibility
4. User selects assistant → Button appears
5. User clicks button → `openModal()` called
6. AJAX request fetches chat HTML → Injected into modal
7. Chat initialized → User can interact

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
