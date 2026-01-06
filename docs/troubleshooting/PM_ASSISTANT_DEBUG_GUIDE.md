# PM Assistant Debugging Guide

## Issue
PM assistant selector dropdown shows but selecting an assistant doesn't render the chat UI.

## Live Test URL
https://bots.nvdigital.solutions/wp-admin/post.php?post=8684&action=edit

## Debugging Steps

### 1. Open Browser Console
- Press F12 or right-click → Inspect
- Go to the Console tab
- Refresh the page

### 2. Check Initial Load Messages
Look for these console messages:
```
[PM AI Assistant] Script loaded: [timestamp]
[PM AI Assistant] Initializing...
[PM AI Assistant] ✓ All elements found, attaching event handlers
[PM AI Assistant] ✓ Initialization complete
```

**If you DON'T see these messages:**
- The JavaScript file isn't loading
- Check Network tab for 404 errors on `admin-pm-ai-assistant.js`

### 3. Select an Assistant from Dropdown
After selecting an assistant, you should see:
```
[PM AI Assistant] Assistant selected: [ID] [Name]
[PM AI Assistant] Rendering chat inline for assistant: [ID]
[PM AI Assistant] Base configuration: { ... }
[PM AI Assistant] ✓ Configuration created for instance: [instance-id]
[PM AI Assistant] Configuration: { assistantId: ..., hasNonce: true/false, ... }
```

### 4. Check for Critical Errors

#### Missing wpMcpAiChat Global
```
[PM AI Assistant] wpMcpAiChat global not found, using defaults
[PM AI Assistant] Available globals: [list of MCP globals]
```
**Fix**: The chat script isn't properly localized. Check if `wp-mcp-ai-chat` script is enqueued.

#### Missing Nonce
```
[PM AI Assistant] REST nonce is missing! Authentication will fail.
[PM AI Assistant] wpMcpAiChat contents: { ... }
```
**Fix**: The nonce isn't being passed. This will cause all API calls to return 401/403 errors.

#### Chat Init Not Available
```
[PM AI Assistant] Chat init not ready, retrying... (1/10)
[PM AI Assistant] Chat init not ready, retrying... (2/10)
...
[PM AI Assistant] Chat init function not available after 10 retries
```
**Fix**: The `wp-mcp-ai-chat` (chat-bundle.min.js) script isn't loaded or has an error.

### 5. Check Network Tab
After selecting an assistant:
- Go to Network tab
- Look for requests to `/wp-json/mcp-ai/v1/chat-client`
- Check if they return 200 (success) or 401/403 (auth error)

### 6. Check for Script Conflicts
In the console, run:
```javascript
console.log('wpMcpAiChat:', window.wpMcpAiChat);
console.log('wpMcpAiChatInit:', window.wpMcpAiChatInit);
console.log('wpMcpAiChatInstances:', window.wpMcpAiChatInstances);
```

**Expected Results:**
- `wpMcpAiChat`: Should be an object with `nonce`, `restUrl`, etc.
- `wpMcpAiChatInit`: Should have an `init` function
- `wpMcpAiChatInstances`: Should be an object (may be empty before selecting assistant)

### 7. Common Issues and Fixes

#### Issue: Chat UI doesn't show at all
**Possible Causes:**
1. Inline container not showing: Check if `#wp-mcp-ai-pm-assistant-inline-container` has `display: none` style
2. Chat HTML not rendered: Check if `#wp-mcp-ai-pm-assistant-chat-container` is empty

**Console Commands to Check:**
```javascript
jQuery('#wp-mcp-ai-pm-assistant-inline-container').is(':visible')  // Should be true after selection
jQuery('#wp-mcp-ai-pm-assistant-chat-container').html()  // Should contain chat HTML
```

#### Issue: Chat shows but doesn't respond
**Possible Causes:**
1. Missing nonce (check console for "REST nonce is missing")
2. Wrong assistant ID
3. API endpoint not reachable

**Console Commands to Check:**
```javascript
// Check if assistant ID is valid
var config = window.wpMcpAiChatInstances[Object.keys(window.wpMcpAiChatInstances)[0]];
console.log('Assistant ID:', config.assistantId);
console.log('Has nonce:', !!config.restNonce);
console.log('REST URL:', config.restUrl);
```

#### Issue: Old modal-based assistant showing instead
**Cause:** The old CPT assistant (`cpt-assistant.js`) might be loading for this post type.

**Check:** Look for metabox with ID `wp_mcp_ai_assistant` (old) vs `wp_mcp_ai_pm_ai_assistant` (new)

The old one is for: post, page, product, quiz, place
The new one is for: mcp_ai_project, mcp_ai_task, mcp_ai_event

### 8. Report Findings
Please share:
1. All console messages (copy/paste)
2. Whether `wpMcpAiChat.nonce` is present
3. Post type of ID 8684
4. Any error messages in console or network tab
5. Screenshot showing the metabox state

## Quick Fix Attempts

If nonce is missing, try adding this filter in your theme's functions.php:
```php
add_filter('wp_mcp_ai_rest_nonce', function($nonce) {
    error_log('PM Assistant Nonce: ' . $nonce);
    return $nonce;
});
```

Then check your error log to see if nonce is being generated.
