# Troubleshooting Guide: Connection Test Buttons Not Working

## Issue
When clicking the "Test Connection" buttons on the WP oOS settings page, nothing happens.

## Common Causes & Solutions

### 1. JavaScript File Not Loading

**Check:**
- Open browser DevTools (F12)
- Go to Network tab
- Refresh the page
- Filter by "admin-settings.js"
- Verify the file loads with HTTP 200 status

**Fix if not loading:**
- Clear browser cache (Ctrl+Shift+R)
- Check file permissions: `chmod 644 assets/js/admin-settings.js`
- Verify WP_MCP_AI_URL constant is defined correctly

### 2. jQuery Not Available

**Check:**
- Open browser Console (F12)
- Type: `jQuery.fn.jquery`
- Should return version number (e.g., "3.7.1")

**Fix if undefined:**
- WordPress jQuery may not be loaded
- Check for plugin conflicts
- Disable other plugins temporarily to test

### 3. JavaScript Errors Preventing Initialization

**Check:**
- Open browser Console (F12)
- Look for red error messages
- Common errors:
  - "Uncaught ReferenceError: wpMcpAiAdmin is not defined"
  - "Uncaught TypeError: $ is not a function"

**Fix:**
Run the diagnostic script in `/debug-connection-tests.js`

### 4. Wrong Admin Page

**Check:**
- Verify you're on: `wp-admin/options-general.php?page=wp-mcp-ai-settings`
- The script only loads on the MCP AI settings page

### 5. Script Enqueuing Issue

**Check PHP:**
```php
// In class-wp-mcp-ai-admin-settings.php
public function enqueue_admin_assets( $hook ) {
    // This should match your current page hook
    error_log('Current hook: ' . $hook);
    error_log('Expected hook: settings_page_' . self::PAGE_SLUG);
    
    if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
        error_log('Script NOT enqueued - hook mismatch!');
        return;
    }
    // ... rest of function
}
```

**Debug:**
Add this to your `wp-config.php` temporarily:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Then check `wp-content/debug.log` for the hook messages.

### 6. Nonce or AJAX URL Not Set

**Check in Console:**
```javascript
console.log(wpMcpAiAdmin);
// Should output: {ajaxUrl: "...", nonce: "..."}
```

**If undefined:**
The `wp_localize_script` call failed. Check:
- Script handle matches: 'wp-mcp-ai-admin-settings'
- Function `enqueue_admin_assets` is being called
- No PHP errors preventing execution

## Quick Diagnostic Script

Paste this in your browser console while on the MCP AI settings page:

```javascript
// From /debug-connection-tests.js
(function() {
    console.log('=== Connection Test Button Diagnostics ===');
    console.log('jQuery loaded:', typeof jQuery !== 'undefined' ? '✅ v' + jQuery.fn.jquery : '❌ NO');
    console.log('wpMcpAiAdmin:', typeof wpMcpAiAdmin !== 'undefined' ? '✅' : '❌');
    console.log('Ollama button:', jQuery('#wp-mcp-ai-test-ollama-connection').length > 0 ? '✅' : '❌');
    console.log('LM Studio button:', jQuery('#wp-mcp-ai-test-lm-studio-connection').length > 0 ? '✅' : '❌');
    console.log('Cloudflare button:', jQuery('#wp-mcp-ai-test-cloudflare-connection').length > 0 ? '✅' : '❌');
    
    const $btn = jQuery('#wp-mcp-ai-test-ollama-connection');
    if ($btn.length > 0) {
        const events = jQuery._data($btn[0], 'events');
        console.log('Ollama button click handlers:', events && events.click ? '✅ ' + events.click.length : '❌ NONE');
    }
})();
```

## Expected Console Output (When Working)

```
=== Connection Test Button Diagnostics ===
jQuery loaded: ✅ v3.7.1
wpMcpAiAdmin: ✅
Ollama button: ✅
LM Studio button: ✅
Cloudflare button: ✅
Ollama button click handlers: ✅ 1
```

## If Still Not Working

1. **Check for plugin conflicts:**
   - Deactivate all other plugins
   - Activate only WP oOS
   - Test if buttons work
   - Reactivate plugins one by one to find conflict

2. **Check theme conflicts:**
   - Switch to a default theme (Twenty Twenty-Four)
   - Test if buttons work
   - May indicate theme is breaking admin scripts

3. **Verify file integrity:**
   ```bash
   # Check if file was modified
   md5sum assets/js/admin-settings.js
   
   # Check file size
   wc -l assets/js/admin-settings.js
   # Should be 421 lines
   ```

4. **Test with different browser:**
   - Try Chrome, Firefox, Safari
   - Clear cache in each
   - Disable browser extensions

## Manual Fix: Add Debug Logging

If the issue persists, add debug logging to the JavaScript:

```javascript
// Add to assets/js/admin-settings.js after line 414

$(function () {
    console.log('WP oOS: Initializing admin handlers...');
    console.log('wpMcpAiAdmin:', wpMcpAiAdmin);
    
    initColorPickers();
    console.log('✅ Color pickers initialized');
    
    initOllamaHandlers();
    console.log('✅ Ollama handlers initialized');
    
    initLMStudioHandlers();
    console.log('✅ LM Studio handlers initialized');
    
    initCloudwaysHandlers();
    console.log('✅ Cloudways handlers initialized');
    
    initCloudflareHandlers();
    console.log('✅ Cloudflare handlers initialized');
    
    console.log('WP oOS: All handlers initialized successfully');
});
```

Then reload the page and check console for which function is failing.

## Contact Support

If none of these solutions work, provide:
1. Browser console errors (screenshot)
2. Network tab showing admin-settings.js (screenshot)
3. Output of diagnostic script
4. WordPress version
5. PHP version
6. Active plugins list
