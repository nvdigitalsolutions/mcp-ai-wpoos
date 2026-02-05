# Slash Command URL Duplication - Fix Summary

## Problem Addressed
Slash commands were failing with a 404 error due to duplicate namespace in the URL:
```
Error: /wp-json/mcp-ai/v1//mcp-ai/v1/slash-command/list
Expected: /wp-json/mcp-ai/v1/slash-command/list
```

## Root Causes Identified
1. **URL Construction Method**: String concatenation approach was potentially vulnerable to WordPress filters
2. **Timing Issue**: JavaScript initialization could occur before `mcpAiData` was fully available in the page

## Solutions Implemented

### 1. URL Construction Fix
**File**: `includes/slash-commands/slash-commands-init.php`

Changed from string concatenation to full-path approach:
```php
// Before (concatenation approach)
$rest_base = trailingslashit( rest_url( 'mcp-ai/v1' ) );
$endpoint = $rest_base . 'slash-command/list';

// After (full-path approach - matches shortcode pattern)
$endpoint = rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/slash-command/list' );
```

**Benefits**:
- Consistent with other endpoints (filesEndpoint, toolsEndpoint, etc.)
- More reliable across different WordPress configurations
- Eliminates potential filter-related issues

### 2. Initialization Retry Logic
**File**: `assets/js/slash-commands.js`

Added retry mechanism to wait for `mcpAiData`:
- Checks if `mcpAiData` is available before proceeding
- Retries every 100ms if not found (max 50 attempts = 5 seconds)
- Prevents initialization errors on pages with multiple chat widgets

**Benefits**:
- Handles timing issues with multiple widgets on same page
- More resilient initialization process
- Better error reporting if data never becomes available

### 3. Enhanced Debugging
Added logging to help diagnose future issues:
- PHP: Can enable WordPress debug logging to see URL construction steps
- JavaScript: Always logs endpoint URLs during initialization (visible in browser console)

## How to Test

### 1. Clear All Caches
```bash
# Browser cache: Ctrl+F5 (Windows) or Cmd+Shift+R (Mac)
# WordPress cache: Clear via caching plugin
# CDN cache: Purge via CDN dashboard (if applicable)
```

### 2. Check Browser Console
Open browser DevTools (F12) and look for:
```javascript
[SlashCommands] Endpoint URLs: {
  restUrl: "https://your-site.com/wp-json/mcp-ai/v1/",
  slashCommandEndpoint: "https://your-site.com/wp-json/mcp-ai/v1/slash-command",
  slashCommandListEndpoint: "https://your-site.com/wp-json/mcp-ai/v1/slash-command/list"
}
```

Verify:
- ✅ Namespace `mcp-ai/v1` appears exactly once in each URL
- ✅ No double slashes (`//`) except in protocol (`https://`)

### 3. Test Slash Commands
1. Navigate to a page with a chat widget
2. Type `/` in the chat input
3. Autocomplete should appear with available commands
4. Select a command and press Enter
5. Command should execute without 404 errors

## Expected Behavior After Fix

### Network Tab
```
✅ GET /wp-json/mcp-ai/v1/slash-command/list → 200 OK
```

### Console Logs
```
[SlashCommands] ✅ Initialized successfully
[SlashCommands] Endpoint URLs: { ... }  // All URLs correct
```

### Autocomplete
- Appears when typing `/`
- Shows available commands
- No errors in console

## If Issues Persist

### 1. Check WordPress Version
- Requires WordPress 6.0+
- Verify: Dashboard → Updates

### 2. Check for Conflicting Plugins
Some plugins may filter REST URLs. Try disabling:
- Custom REST API plugins
- Security plugins (if they modify REST endpoints)
- Caching plugins (temporarily)

### 3. Check Server Configuration
- Ensure pretty permalinks are enabled
- Verify `.htaccess` is writable
- Check for nginx redirect rules

### 4. Enable Debug Logging
In `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Check `wp-content/debug.log` for errors.

### 5. Verify REST API
Visit: `https://your-site.com/wp-json/`

Should return JSON with available namespaces including `mcp-ai/v1`.

## Files Changed
- `includes/slash-commands/slash-commands-init.php` - URL construction
- `assets/js/slash-commands.js` - Retry logic and logging

## Compatibility
- ✅ WordPress 6.0+
- ✅ PHP 7.4+
- ✅ All major browsers (Chrome, Firefox, Safari, Edge)
- ✅ Multisite installations
- ✅ Subdirectory installations

## Related Documentation
- [Slash Commands Guide](docs/slash-commands-guide.md)
- [REST API Reference](docs/rest-api.md)
- [Deployment Troubleshooting](docs/deployment-troubleshooting.md)

## Support
If you continue to experience issues after applying this fix:
1. Check browser console for error messages
2. Check WordPress debug log
3. Note your WordPress version and active plugins
4. Create an issue at: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
