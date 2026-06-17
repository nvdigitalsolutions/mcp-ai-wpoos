# PM AI Assistant - Quick Diagnostic Guide

**Issue**: Assistant dropdown selection doesn't open modal

**Quick Fix**: Open browser console (F12) to see detailed diagnostic logs

## What You Should See

### ✅ Success Pattern
```
[PM AI Assistant] Script file loaded at: 2026-01-05T20:15:00.000Z
[PM AI Assistant] jQuery is available, version: 3.7.1
[PM AI Assistant] ⚡ Document ready event fired, calling initPmAiAssistant()
[PM AI Assistant] ✓ Initialization successful, all elements found
[PM AI Assistant] ✓ Change event handler attached to selector
```
When you select an assistant:
```
[PM AI Assistant] ⚡ Selector change event fired! {assistantId: "123", ...}
[PM AI Assistant] ➜ Opening modal for assistant: 123 Sophie
[PM AI Assistant] ✓ Chat initialization successful
```

### ❌ Common Problems

#### Problem 1: No Logs at All
**Symptom**: Console is empty
**Meaning**: JavaScript file not loading
**Check**: View page source, search for `admin-pm-ai-assistant.js`

#### Problem 2: "CRITICAL: jQuery is not available!"
**Meaning**: jQuery not loaded before our script
**Fix**: Check script dependencies in PHP code

#### Problem 3: "CRITICAL: Required elements not found"
**Meaning**: HTML metabox not rendered
**Check**:
- Settings → NV oOS → Enable Project Management ✓
- At least one published Assistant exists
- Current post is a Project, Task, or Event

#### Problem 4: Handler attached but no "⚡ Selector change event fired"
**Meaning**: Dropdown change event blocked
**Try**: Manually trigger: `jQuery('#wp-mcp-ai-pm-assistant-select').trigger('change')`

#### Problem 5: "CRITICAL: window.wpMcpAiChatInit.init not available"
**Meaning**: Chat bundle script not loaded
**Check**: View source for `chat-bundle.min.js`

## Testing Command

Paste this in browser console to test manually:
```javascript
// Check if everything is loaded
console.log('Script loaded?', typeof window.wpMcpAiChatInit);
console.log('jQuery loaded?', typeof jQuery);
console.log('Selector exists?', jQuery('#wp-mcp-ai-pm-assistant-select').length);
console.log('Modal exists?', jQuery('#wp-mcp-ai-pm-assistant-modal').length);

// Try to trigger selection manually
jQuery('#wp-mcp-ai-pm-assistant-select').val('1619').trigger('change');
```

## Report Issue With

1. **Full console output** (copy/paste)
2. **Browser**: Chrome/Firefox/Safari + version
3. **WordPress version**: Settings → About WordPress
4. **Any red errors** in console

---

**See `docs/fixes/pm-assistant-logging-fix-2026-01-05.md` for complete details**
