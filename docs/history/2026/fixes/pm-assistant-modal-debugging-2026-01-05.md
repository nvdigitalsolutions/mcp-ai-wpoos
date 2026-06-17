# PM AI Assistant Modal Not Opening - Debugging Guide

**Date**: 2026-01-05  
**Issue**: Modal doesn't open when user selects an assistant in PM CPT metaboxes  
**Status**: 🔍 **INVESTIGATING** - Logging added for diagnosis

---

## Quick Test Instructions

1. Open any Project, Task, or Event edit page in WordPress admin
2. Open browser Developer Tools (F12) and go to Console tab
3. Select an assistant from the "Select Assistant" dropdown
4. Check the console output - you should see log messages like:
   ```
   [PM AI Assistant] Initialization successful, elements found: {selector: true, modal: true, chatContainer: true}
   [PM AI Assistant] Event handler attached to selector
   [PM AI Assistant] Selector change event fired, assistantId: 123
   [PM AI Assistant] Assistant selected: 123 Akira
   [PM AI Assistant] Opening modal directly...
   [PM AI Assistant] Modal visibility class added, checking display...
   [PM AI Assistant] Modal display style: block
   ```

---

## What the Logging Will Tell Us

### Scenario 1: No Log Messages At All
**Meaning**: JavaScript file not loading or initialization not running

**Possible Causes**:
- JavaScript file path incorrect
- Script enqueuing issue
- JavaScript syntax error preventing execution
- Script dependencies not met

**What to Check**:
- View page source, search for `admin-pm-ai-assistant.js`
- Check browser console for JavaScript errors (red text)
- Verify `WP_MCP_AI_Shortcode::SCRIPT_HANDLE` is enqueued

---

### Scenario 2: Only Initialization Log, No Event Handler Log
**Meaning**: Required elements not found in DOM

**Console Output**:
```
[PM AI Assistant] Required elements not found: {selector: 0, chatContainer: 0, modal: 0}
```

**Possible Causes**:
- HTML not rendered by PHP
- Post type not matching (not mcp_ai_project, mcp_ai_task, or mcp_ai_event)
- Project management feature not enabled in settings
- No assistants available

**What to Check**:
- Settings → NV oOS → Enable Project Management checkbox
- At least one published Assistant exists
- Current post type is correct
- View page source, search for `wp-mcp-ai-pm-assistant-select`

---

### Scenario 3: Logs Show Event Handler Attached, But No Change Event
**Meaning**: Dropdown not triggering change event

**Console Output**:
```
[PM AI Assistant] Initialization successful...
[PM AI Assistant] Event handler attached to selector
(No further logs when selecting)
```

**Possible Causes**:
- jQuery event binding failed
- Another script preventing event propagation
- Dropdown disabled or readonly

**What to Check**:
- Inspect the select element - is it enabled?
- Try manually triggering: `jQuery('#wp-mcp-ai-pm-assistant-select').trigger('change')`
- Check for JavaScript errors when selecting

---

### Scenario 4: Change Event Fires, Modal Display Shows "none"
**Meaning**: CSS not applying correctly

**Console Output**:
```
[PM AI Assistant] Opening modal directly...
[PM AI Assistant] Modal visibility class added, checking display...
[PM AI Assistant] Modal display style: none
[PM AI Assistant] Modal has visible class: true
```

**Possible Causes**:
- CSS file not loaded
- CSS specificity issue
- Conflicting styles from theme/plugins
- Modal moved to body but styles lost

**What to Check**:
- View page source, search for `admin-pm-ai-assistant.css`
- Inspect modal element in browser DevTools
- Check computed styles - which rule is winning?
- Look for `display: none !important` overrides

---

### Scenario 5: Modal Opens But Chat Doesn't Initialize
**Meaning**: Chat bundle or initialization issue

**Console Output**:
```
[PM AI Assistant] Opening modal directly...
[PM AI Assistant] Modal display style: block
[PM AI Assistant] Chat container is empty, initializing...
[PM AI Assistant] Initializing chat interface for assistant: 123
[PM AI Assistant] Chat configuration created for instance: wp-mcp-ai-pm-chat-123-...
[PM AI Assistant] wpMcpAiChatInit.init not available
```

**Possible Causes**:
- Chat bundle script not loaded
- `window.wpMcpAiChatInit` undefined
- Script loading order issue

**What to Check**:
- Console: Type `window.wpMcpAiChatInit` - should be an object
- Console: Type `window.wpMcpAiChat` - should have config data
- View page source, search for `wp-mcp-ai-chat` script tag
- Check script dependencies in PHP (line 96)

---

## Frontend-Backend Coordination

The PM AI Assistant requires coordination between PHP and JavaScript:

### PHP Side (Backend)
```php
// 1. Render HTML
render_assistant_selector( $assistants );  // Dropdown
render_chat_container( $post );             // Modal

// 2. Enqueue scripts
wp_enqueue_script( 'wp-mcp-ai-chat' );                    // Chat bundle
wp_enqueue_script( 'wp-mcp-ai-pm-ai-assistant', ..., 
    array( 'jquery', 'wp-mcp-ai-chat' ) );                // PM assistant (depends on chat)

// 3. Localize data
wp_localize_script( 'wp-mcp-ai-chat', 'wpMcpAiChat', ... );           // Chat config
wp_localize_script( 'wp-mcp-ai-pm-ai-assistant', 'wpMcpAiPmAssistant', ... );  // PM config
```

### JavaScript Side (Frontend)
```javascript
// 1. Wait for document ready
$(document).ready(function() {
    initPmAiAssistant();  // Initialize
});

// 2. Find elements
$selector = $('#wp-mcp-ai-pm-assistant-select');
$modal = $('#wp-mcp-ai-pm-assistant-modal');

// 3. Attach event handler
$selector.on('change', function() {
    openModal(...);  // Open modal
});

// 4. When modal opens
$modal.removeAttr('style');  // Remove inline style
$modal.addClass('wp-mcp-ai-pm-assistant-modal--visible');  // Add visible class

// 5. Initialize chat
window.wpMcpAiChatInit.init();  // Chat bundle initialization
```

### Required Global Variables

1. **`window.wpMcpAiChat`** (from chat bundle localization)
   ```javascript
   {
       restUrl: '/wp-json/mcp-ai/v1',
       currentUserId: 1,
       nonce: 'abc123...',
       filesEndpoint: '/wp-json/mcp-ai/v1/files/',
       // ... more config
   }
   ```

2. **`window.wpMcpAiPmAssistant`** (from PM assistant localization)
   ```javascript
   {
       contextType: 'project',
       contextData: { id: 123, title: 'My Project', ... },
       postId: 123,
       postType: 'mcp_ai_project',
       nonce: 'xyz789...',
       ajaxUrl: '/wp-admin/admin-ajax.php'
   }
   ```

3. **`window.wpMcpAiChatInit`** (from chat bundle JavaScript)
   ```javascript
   {
       init: function() { /* initialize all chat instances */ }
   }
   ```

4. **`window.wpMcpAiChatInstances`** (created by PM assistant JavaScript)
   ```javascript
   {
       'wp-mcp-ai-pm-chat-123-1234567890': {
           id: 'wp-mcp-ai-pm-chat-123-1234567890',
           assistantId: 123,
           restUrl: '/wp-json/mcp-ai/v1',
           // ... full chat config
       }
   }
   ```

---

## CSS Specificity Analysis

The modal visibility is controlled by CSS classes:

```css
/* Default: Hidden (specificity: 0,1,0) */
.wp-mcp-ai-pm-assistant-modal {
    display: none !important;
}

/* Visible: Shown (specificity: 0,2,0 - WINS!) */
.wp-mcp-ai-pm-assistant-modal.wp-mcp-ai-pm-assistant-modal--visible {
    display: block !important;
}
```

The visible class has higher specificity (2 classes vs 1), so it should always win.

**To verify CSS is working**:
1. Open DevTools
2. Inspect the modal element
3. Check "Computed" tab
4. Look for `display` property
5. See which rule is applied
6. If it shows `none`, check which rule is winning

---

## Manual Testing Commands

Open browser console and try these commands:

```javascript
// Check if elements exist
jQuery('#wp-mcp-ai-pm-assistant-select').length;  // Should be 1
jQuery('#wp-mcp-ai-pm-assistant-modal').length;   // Should be 1

// Check if globals are defined
typeof window.wpMcpAiChat;                        // Should be 'object'
typeof window.wpMcpAiPmAssistant;                 // Should be 'object'
typeof window.wpMcpAiChatInit;                    // Should be 'object'

// Manually trigger modal open
jQuery('#wp-mcp-ai-pm-assistant-modal')
    .removeAttr('style')
    .addClass('wp-mcp-ai-pm-assistant-modal--visible');

// Check modal display
jQuery('#wp-mcp-ai-pm-assistant-modal').css('display');  // Should be 'block'

// Manually trigger selector change
jQuery('#wp-mcp-ai-pm-assistant-select').val('123').trigger('change');
```

---

## Next Steps After Getting Logs

1. **Share console logs** with the development team
2. **Note which scenario** matches your console output
3. **Include browser info**: Chrome 120, Firefox 121, etc.
4. **Include WordPress version**: 6.4.2, etc.
5. **Include plugin version**: Check Settings → NV oOS
6. **Screenshot** of the console and the metabox

---

## Related Files

- **PHP**: `addons/pro/includes/metaboxes/class-wp-mcp-ai-project-management-ai-assistant-metabox.php`
- **JavaScript**: `addons/pro/assets/js/admin-pm-ai-assistant.js`
- **CSS**: `addons/pro/assets/css/admin-pm-ai-assistant.css`
- **Chat Bundle**: `assets/js/chat-bundle.min.js`
- **Tests**: `tests/test-pm-ai-assistant-metabox.php`

---

## Summary

The PM AI Assistant modal not opening issue requires coordination between:
1. PHP rendering HTML
2. PHP enqueueing and localizing scripts
3. JavaScript finding elements
4. JavaScript attaching event handlers
5. CSS making modal visible
6. Chat bundle initializing the chat interface

The comprehensive logging added in this commit will help pinpoint exactly where the chain breaks.

---

**Status**: Awaiting user testing with console logs
**Next Update**: After console logs are reviewed
