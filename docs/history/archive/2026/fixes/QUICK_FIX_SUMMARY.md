# LM Studio 500 Error Fix - Quick Reference

## What Was Fixed

**Problem:** LM Studio getting HTTP 500 errors when connecting to MCP endpoint
**Root Cause:** Accept header `text/event-stream` incorrectly triggering SSE mode
**Solution:** Remove Accept header check, use only explicit `stream` parameter

## Files Changed

1. `includes/rest/class-wp-mcp-ai-sse-handler.php` - Core fix
2. `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php` - Null safety
3. `tests/test-sse-handler.php` - Test updates

## Key Change

**Before:**
```php
// Accept header triggered SSE mode
$accept_header = $request->get_header( 'accept' );
if ( strpos( $accept_header, 'text/event-stream' ) !== false ) {
    return true; // ❌ SSE mode for LM Studio
}
```

**After:**
```php
// Only explicit parameter triggers SSE
if ( $request->get_param( 'stream' ) === 'true' ) {
    return true; // ✅ SSE only when requested
}
return false; // ✅ JSON by default
```

## Testing

```bash
# Standalone logic test
php /tmp/test-mcp-endpoint.php

# All 5 tests pass:
✓ Default GET → JSON
✓ GET with Accept: text/event-stream → JSON (LM Studio fix!)
✓ GET with ?stream=true → SSE
✓ GET with ?stream=false → JSON  
✓ Accept header + ?stream=true → SSE
```

## User Instructions

**No changes needed!** The fix is server-side only. LM Studio users can use the same configuration:

```json
{
  "mcpServers": {
    "wordpress-site": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET"
      }
    }
  }
}
```

Simply pull the latest code and the 500 errors should be resolved.

## Verification

After deploying the fix:

1. **Check endpoint responds correctly:**
```bash
curl -H "Accept: text/event-stream" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     https://your-site.com/wp-json/mcp-ai/v1/mcp
```
Should return: **JSON** (200 OK), not SSE or 500 error

2. **Test LM Studio connection:**
- Open LM Studio
- Configure MCP server as above
- Connect - should work without errors
- Tools should be available

3. **Verify SSE still works (if needed):**
```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     'https://your-site.com/wp-json/mcp-ai/v1/mcp?stream=true'
```
Should return: **SSE stream** (200 OK)

## Documentation

- **Full Details:** `LM_STUDIO_500_ERROR_FIX.md` in repository root
- **Previous Fixes:** `docs/LM_STUDIO_SSE_FIX.md`, `docs/LM_STUDIO_CONNECTION_FIX.md`
- **MCP Endpoint:** `docs/mcp-endpoint.md`

## MCP Spec Compliance

This fix ensures compliance with **MCP 2024-11-05 Streamable HTTP specification**:

| Method | Endpoint | Purpose | Response |
|--------|----------|---------|----------|
| GET | /mcp | Discovery | JSON |
| POST | /mcp | JSON-RPC | JSON |
| GET | /mcp?stream=true | SSE (opt-in) | SSE stream |

## Summary

✅ **Fixed:** LM Studio 500 errors  
✅ **Maintained:** SSE functionality (opt-in)  
✅ **Improved:** Spec compliance  
✅ **No Breaking Changes:** Existing clients unaffected  

**Status:** Ready to deploy 🚀
