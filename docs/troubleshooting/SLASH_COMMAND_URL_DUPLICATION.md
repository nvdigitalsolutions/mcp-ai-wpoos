# Slash Command URL Duplication Issue - Troubleshooting Guide

## Problem Description

Users may encounter a 404 error when executing slash commands with a URL like:
```
GET https://example.com/wp-json/mcp-ai/v1//mcp-ai/v1/slash-command/list 404 (Not Found)
```

Notice the duplicate `/mcp-ai/v1` namespace and double slash `//`.

## Root Cause

This issue was caused by JavaScript string concatenation of the REST API base URL with the endpoint path. When the path accidentally started with `/` or included the full namespace, it resulted in URL malformation.

## Solution

**Fixed in PR #3587** (included in version 1.1.0+)

The fix provides complete endpoint URLs directly from PHP (similar to how chat.js handles endpoints), eliminating the need for JavaScript concatenation.

### Changes Made

**PHP** (`includes/slash-commands/slash-commands-init.php`):
```php
'slashCommandEndpoint'    => rest_url('mcp-ai/v1/slash-command'),
'slashCommandListEndpoint' => rest_url('mcp-ai/v1/slash-command/list'),
```

**JavaScript** (`assets/js/slash-commands.js`, `assets/js/command-autocomplete.js`):
```javascript
// Before (caused duplication)
const endpoint = window.mcpAiData?.restUrl + 'slash-command/list';

// After (correct)
const endpoint = window.mcpAiData?.slashCommandListEndpoint;
```

## If You Still See This Issue

If you continue to experience this error after upgrading to version 1.1.0 or later:

### 1. Clear Browser Cache

The old JavaScript may be cached in your browser:
- **Chrome/Edge**: Press `Ctrl + F5` (Windows) or `Cmd + Shift + R` (Mac)
- **Firefox**: Press `Ctrl + Shift + R` (Windows) or `Cmd + Shift + R` (Mac)
- **Safari**: Press `Cmd + Option + R`

Alternatively, open DevTools (F12) → Network tab → Check "Disable cache"

### 2. Clear WordPress Cache

If using a caching plugin (W3 Total Cache, WP Super Cache, etc.):
1. Go to WordPress admin
2. Find your caching plugin settings
3. Clear/purge all caches
4. Test again

### 3. Clear CDN Cache

If using a CDN (Cloudflare, StackPath, etc.):
1. Log into your CDN dashboard
2. Purge/clear the cache for your domain
3. Wait a few minutes for propagation
4. Test again

### 4. Verify Plugin Version

Ensure you're running version 1.1.0 or later:
1. Go to WordPress admin → Plugins
2. Find "NV oOS" (mcp-ai-wpoos)
3. Check version number
4. Update if needed

### 5. Check JavaScript Loading

Open browser DevTools (F12) → Console and check:
```javascript
// Check if the endpoint is defined correctly
console.log(window.mcpAiData?.slashCommandListEndpoint);
// Should output: https://yourdomain.com/wp-json/mcp-ai/v1/slash-command/list
// Should NOT contain duplicate /mcp-ai/v1 or double //
```

### 6. Verify REST Route Registration

Visit your WordPress REST API root to verify routes are registered correctly:
```
https://yourdomain.com/wp-json/
```

Look for these routes in the `namespaces` array:
- `/mcp-ai/v1/slash-command`
- `/mcp-ai/v1/slash-command/list`

### 7. Test in Incognito/Private Mode

Test in a private browsing session to rule out browser extensions or persistent cache:
1. Open incognito/private window
2. Log into WordPress admin
3. Test slash commands
4. Check if error persists

## Expected Behavior

After the fix, the URL should be:
```
GET https://example.com/wp-json/mcp-ai/v1/slash-command/list
```

✅ Single `/mcp-ai/v1` namespace
✅ No double slashes
✅ Returns 200 OK (or appropriate auth error if not logged in)

## Technical Details

### URL Construction Pattern

The plugin now follows the same pattern used by chat.js:

```php
// Pattern used for ALL endpoint URLs
'endpointName' => esc_url_raw( 
    WP_MCP_AI_Request_Context::normalise_rest_url( 
        rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/endpoint-path' ) 
    ) 
),
```

This ensures:
1. Consistent URL generation across all features
2. No manual JavaScript concatenation needed
3. Proper handling of different WordPress configurations (subdirectory, multisite, etc.)

### Test Coverage

The fix is validated by automated tests in `tests/test-slash-command-url-construction.php`:
- Verifies namespace appears exactly once
- Checks for absence of double slashes
- Validates route registration
- Ensures backward compatibility

## Still Need Help?

If none of the above steps resolve the issue:

1. Check WordPress debug log:
   - Enable `WP_DEBUG` and `WP_DEBUG_LOG` in `wp-config.php`
   - Check `wp-content/debug.log` for errors

2. Check browser console:
   - Open DevTools (F12)
   - Look for errors in Console tab
   - Check Network tab for failed requests

3. Report the issue:
   - Include plugin version
   - Include WordPress version  
   - Include browser console errors
   - Include failed network request details
   - Create issue at: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

## Related Documentation

- [REST API Reference](../rest-api.md)
- [Slash Commands Guide](../slash-commands.md)
- [Deployment Troubleshooting](../deployment-troubleshooting.md)
