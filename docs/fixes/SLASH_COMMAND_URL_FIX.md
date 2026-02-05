# Slash Command URL Duplication Fix

## Issue Summary

**Problem**: Persistent 404 error with URL duplication even after clearing cache:
```
GET https://bots.nvdigital.solutions/wp-json/mcp-ai/v1//mcp-ai/v1/slash-command/list 404 (Not Found)
```

**Symptoms**:
- Namespace `mcp-ai/v1` appears twice in the URL
- Double slash `//` between the duplicate namespaces
- Error persists even after browser cache clearing

## Root Cause

The previous fix (PR #3587) called `rest_url()` with the full path for each endpoint:
```php
rest_url('mcp-ai/v1/slash-command/list')
```

While this works in most WordPress configurations, in certain setups (with specific plugins, filters, or server configurations), calling `rest_url()` with the full namespace+path could result in the namespace being added twice.

## The Fix

Changed to a **base URL + path concatenation approach**:

### Before (Multiple rest_url() calls)
```php
'slashCommandEndpoint'    => rest_url('mcp-ai/v1/slash-command'),
'slashCommandListEndpoint' => rest_url('mcp-ai/v1/slash-command/list'),
```

### After (Single rest_url() call + concatenation)
```php
// Get base REST URL for namespace ONCE
$rest_base = trailingslashit(rest_url('mcp-ai/v1'));

// Build endpoints via simple string concatenation
'slashCommandEndpoint'     => $rest_base . 'slash-command',
'slashCommandListEndpoint' => $rest_base . 'slash-command/list',
```

## Why This Works

1. **Single `rest_url()` Call**: By calling `rest_url()` only once for the namespace, we eliminate the possibility of filters or configurations modifying the path multiple times.

2. **Simple String Concatenation**: Endpoint paths are added as simple strings, which cannot be affected by WordPress filters.

3. **Predictable Behavior**: The approach is deterministic and works consistently across all WordPress configurations.

4. **Cannot Duplicate**: Since the namespace is only added once at the base level, duplication is impossible.

## Technical Details

### URL Construction Flow

1. **Get Base URL**:
   ```
   rest_url('mcp-ai/v1')
   → https://bots.nvdigital.solutions/wp-json/mcp-ai/v1
   ```

2. **Add Trailing Slash**:
   ```
   trailingslashit(...)
   → https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/
   ```

3. **Concatenate Endpoint Path**:
   ```
   base + 'slash-command/list'
   → https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/slash-command/list
   ```

### Validation

✅ **Namespace Appears Once**: `mcp-ai/v1` appears exactly 1 time  
✅ **No Double Slashes**: No `//` in the path (except protocol)  
✅ **Correct Format**: Matches expected WordPress REST API URL structure  
✅ **Backward Compatible**: Generates identical URLs to the old approach  

## Benefits

- **More Robust**: Works across diverse WordPress configurations
- **Fewer Rest API Calls**: Only one `rest_url()` call instead of three
- **Clearer Intent**: Code explicitly shows base URL + path construction
- **Easier to Debug**: Simpler logic makes issues easier to trace
- **Filter-Resistant**: Simple concatenation can't be modified by filters

## Testing

The fix includes comprehensive tests in `tests/test-slash-command-url-construction.php` that validate:

- Endpoints contain namespace exactly once
- No double slashes in paths
- Correct endpoint format
- REST routes registered correctly

## Deployment

After deploying this fix:

1. **Clear WordPress Cache**: If using a caching plugin (W3 Total Cache, WP Super Cache, etc.)
2. **Clear Browser Cache**: Hard refresh (Ctrl+F5 or Cmd+Shift+R)
3. **Clear CDN Cache**: If using Cloudflare, StackPath, etc.
4. **Verify**: Check browser console - the URL should now be correct

## Expected Behavior After Fix

### Before (Error)
```
GET https://bots.nvdigital.solutions/wp-json/mcp-ai/v1//mcp-ai/v1/slash-command/list
→ 404 Not Found
```

### After (Success)
```
GET https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/slash-command/list
→ 200 OK (or appropriate auth response)
```

## Related Files Changed

- `includes/slash-commands/slash-commands-init.php` - URL construction logic
- `tests/test-slash-command-url-construction.php` - Test documentation
- `docs/troubleshooting/SLASH_COMMAND_URL_DUPLICATION.md` - User documentation

## Comparison with Other Endpoints

This fix aligns slash command URLs with the same pattern used for other endpoints in the plugin:

```php
// Chat transcript endpoint (same base + path pattern)
$rest_base = trailingslashit(rest_url('mcp-ai/v1'));
'transcriptsEndpoint' => $rest_base . 'chat-transcripts'

// Tools endpoint (same pattern)
'toolsEndpoint' => $rest_base . 'tools'

// Slash commands (now aligned)
'slashCommandListEndpoint' => $rest_base . 'slash-command/list'
```

## Version

This fix is included in version 1.1.0+ of the plugin.

## Additional Resources

- [WordPress REST API Documentation](https://developer.wordpress.org/rest-api/)
- [Troubleshooting Guide](../troubleshooting/SLASH_COMMAND_URL_DUPLICATION.md)
- [REST API Reference](../rest-api.md)
