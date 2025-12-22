# LM Studio MCP Connection Fix - Summary

## ⚠️ UPDATED - November 2024

**This document describes a previous fix. A new issue and fix have been identified.**

**Please see [LM_STUDIO_SSE_FIX.md](LM_STUDIO_SSE_FIX.md) for the latest fix addressing "SSE error: undefined" issues.**

---

## Previous Issue Description (2024)

LM Studio clients were unable to connect to the WP oOS MCP server, receiving SSE errors with undefined content type:

```javascript
{
  code: undefined,
  event: {
    type: 'error',
    message: undefined,
    code: undefined,
    defaultPrevented: false,
    cancelable: false,
    timeStamp: 1726.3048
  }
}
```

## Previous Root Cause

The `/mcp` endpoint was defaulting to SSE (Server-Sent Events) streaming for GET requests, but LM Studio expected JSON discovery information instead. This caused a content-type mismatch and connection failures.

## Previous Solution Implemented (Superseded)

### 1. Changed GET /mcp Behavior

**Before:**
- GET `/mcp` → Returns SSE stream (Content-Type: text/event-stream)
- GET `/mcp?discovery=true` → Returns JSON discovery

**After:**
- GET `/mcp` → Returns JSON discovery (Content-Type: application/json) **[DEFAULT]**
- GET `/mcp?stream=true` → Returns SSE stream
- GET `/mcp` with `Accept: text/event-stream` → Returns SSE stream ⚠️ **THIS WAS THE PROBLEM!**

## November 2024 Update

**The previous fix introduced a new issue:**

The fix added Accept header detection for SSE, but LM Studio's MCP client sends `Accept: text/event-stream` by default while expecting JSON responses (Streamable HTTP transport). This caused the "SSE error: undefined" to return.

**New Fix:**
- Removed Accept header check completely
- SSE only triggered by explicit `?stream=true` parameter
- `/mcp` endpoint always returns JSON for GET requests
- Aligns with MCP 2024-11-05 Streamable HTTP specification

See [LM_STUDIO_SSE_FIX.md](LM_STUDIO_SSE_FIX.md) for complete details.

---

## Previous Documentation (Historical Reference)

The JSON discovery now clearly indicates:
- **JSON-RPC as primary transport** (POST /mcp)
- **SSE as optional transport** (GET /mcp?stream=true)

```json
{
  "transports": {
    "jsonrpc": {
      "endpoint": "/wp-json/mcp-ai/v1/mcp",
      "methods": ["POST"],
      "default": true,
      "note": "Primary transport - POST with JSON-RPC 2.0 payload"
    },
    "sse": {
      "endpoint": "/wp-json/mcp-ai/v1/mcp",
      "methods": ["GET"],
      "default": false,
      "note": "Optional streaming - GET /mcp?stream=true"
    }
  }
}
```

### 3. Updated Documentation

- `docs/mcp-client-configurations.md` - Updated LM Studio troubleshooting
- `docs/mcp-endpoint.md` - Added detailed GET vs POST usage guide

### 4. Added Test Coverage

Created `tests/test-mcp-endpoint-get-request.php` with comprehensive tests:
- ✅ GET /mcp returns JSON by default
- ✅ GET /mcp?stream=true returns SSE
- ✅ Accept header detection works correctly
- ✅ Discovery response structure is valid

## Files Modified

1. `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php`
   - Modified `handle_mcp_get_request()` method
   - Modified `return_discovery_info()` method

2. `docs/mcp-client-configurations.md`
   - Updated LM Studio configuration section

3. `docs/mcp-endpoint.md`
   - Added endpoint methods section
   - Updated transport comparison

4. `tests/test-mcp-endpoint-get-request.php` (new)
   - Complete test coverage for new behavior

## Impact

### Positive
- ✅ LM Studio can now connect successfully
- ✅ Better MCP spec compliance (JSON-RPC as primary)
- ✅ Improved client compatibility
- ✅ Clearer documentation
- ✅ Backwards compatible (SSE still available)

### No Breaking Changes
- ✅ POST /mcp continues to work exactly as before
- ✅ SSE still available via explicit opt-in
- ✅ Existing clients unaffected

## Testing Results

### Logic Tests
All 5 test scenarios passed:
1. Default GET → JSON ✅
2. GET with stream=true → SSE ✅
3. GET with Accept: text/event-stream → SSE ✅
4. GET with Accept: application/json → JSON ✅
5. LM Studio scenario → JSON ✅

### Syntax Check
All modified files pass PHP syntax validation ✅

## LM Studio Configuration

This configuration now works without errors:

```json
{
  "mcpServers": {
    "wordpress-site": {
      "url": "https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/mcp",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET"
      },
      "timeout": 30000
    }
  }
}
```

## Recommendations

1. **For Users**: Update to this version to fix LM Studio connectivity
2. **For Developers**: Use POST /mcp for JSON-RPC operations
3. **For SSE Users**: Add `?stream=true` or `Accept: text/event-stream` header

## Related Issues

This fix resolves the issue where LM Studio and similar MCP clients could not properly discover and connect to the WP oOS MCP server.

## Future Considerations

- Monitor for any clients that may have been explicitly expecting SSE on GET
- Consider adding capability negotiation in the future
- Track MCP spec updates for any transport changes

## References

- MCP Specification: https://spec.modelcontextprotocol.io/
- LM Studio MCP Documentation: https://lmstudio.ai/docs/app/mcp
- WP oOS MCP Endpoint Docs: docs/mcp-endpoint.md
