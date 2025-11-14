# MCP Endpoint Updates - SSE as Default

## Changes Summary

### 1. SSE is Now the Default Transport
**GET `/wp-json/mcp-ai/v1/mcp`** now defaults to establishing an SSE (Server-Sent Events) connection.

**Previous Behavior:**
- GET /mcp → Returns JSON discovery info
- GET /mcp with Accept: text/event-stream → Establishes SSE
- GET /sse → Establishes SSE

**New Behavior:**
- GET /mcp → **Establishes SSE (DEFAULT)**
- GET /mcp?discovery=true → Returns JSON discovery info
- GET /mcp with Accept: application/json → Returns JSON discovery info
- GET /no-sse → Returns assistant directory without SSE

### 2. Endpoint Renamed: `/sse` → `/no-sse`
The `/sse` endpoint has been renamed to `/no-sse` because SSE is now the default behavior on `/mcp`.

- **Old:** `/wp-json/mcp-ai/v1/sse` (for SSE connections)
- **New:** `/wp-json/mcp-ai/v1/no-sse` (for non-SSE assistant directory)

## Rationale

Per the MCP 2024-11-05 specification:
1. **Streamable HTTP** is the recommended transport method
2. SSE provides real-time updates and better client experience
3. Making SSE the default aligns with modern protocol expectations
4. Clients that need JSON can explicitly request it

## Use Cases

### LM Studio Configuration (Works as-is)
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

**What happens:**
1. LM Studio sends GET to `/mcp`
2. Server establishes SSE connection (default)
3. Real-time updates stream to client
4. POST requests for JSON-RPC still work

### Getting Discovery Info
```bash
# Explicit discovery request
curl https://your-site.com/wp-json/mcp-ai/v1/mcp?discovery=true

# Or with Accept header
curl -H "Accept: application/json" https://your-site.com/wp-json/mcp-ai/v1/mcp
```

### Non-SSE Assistant Directory
```bash
# For clients that don't support SSE
curl https://your-site.com/wp-json/mcp-ai/v1/no-sse
```

## Migration Guide

### For Existing Integrations

**If you were using GET /mcp:**
- **Before:** Returned JSON discovery
- **After:** Establishes SSE connection
- **Fix:** Add `?discovery=true` parameter to get JSON

**If you were using GET /sse:**
- **Before:** Established SSE
- **After:** Endpoint renamed to `/no-sse` (returns JSON)
- **Fix:** Use GET `/mcp` instead (SSE is now default)

### Code Examples

**Before:**
```javascript
// Old way - SSE required specific endpoint
const sseUrl = 'https://site.com/wp-json/mcp-ai/v1/sse';
const evtSource = new EventSource(sseUrl);
```

**After:**
```javascript
// New way - SSE is default on /mcp
const sseUrl = 'https://site.com/wp-json/mcp-ai/v1/mcp';
const evtSource = new EventSource(sseUrl);
```

## Benefits

1. **✅ Simpler Configuration:** No need to specify SSE headers
2. **✅ Better Performance:** SSE provides real-time updates
3. **✅ MCP 2024-11-05 Compliant:** Follows latest specification
4. **✅ Backward Compatible:** JSON-RPC POST still works
5. **✅ Explicit Fallback:** Discovery available via parameter

## Technical Details

### Request Flow

| Request | Response |
|---------|----------|
| `GET /mcp` | SSE stream (default) |
| `GET /mcp?discovery=true` | JSON discovery |
| `GET /mcp` + `Accept: application/json` | JSON discovery |
| `GET /no-sse` | JSON assistant directory |
| `POST /mcp` + JSON-RPC | JSON-RPC response |

### Discovery Response Format
```json
{
  "name": "WP oOS MCP Server",
  "protocolVersion": "2024-11-05",
  "capabilities": {
    "sse": {
      "enabled": true,
      "default": true,
      "note": "GET /mcp defaults to SSE. Add ?discovery=true for this JSON response."
    }
  },
  "transports": {
    "sse": {
      "endpoint": "https://site.com/wp-json/mcp-ai/v1/mcp",
      "methods": ["GET"],
      "default": true
    },
    "jsonrpc": {
      "endpoint": "https://site.com/wp-json/mcp-ai/v1/mcp",
      "methods": ["POST"]
    }
  },
  "usage": {
    "sse_default": "GET /mcp (default - establishes SSE stream)",
    "discovery": "GET /mcp?discovery=true (returns this JSON)",
    "no_sse": "GET /no-sse (assistant directory without SSE)"
  }
}
```

## Files Changed

1. `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php`
   - Renamed `/sse` route to `/no-sse`
   - Updated `handle_mcp_get_request()` to default to SSE
   - Added `handle_no_sse_request()` method
   - Updated discovery response format

2. `tests/test-mcp-endpoint-get-support.php`
   - Updated tests for SSE default behavior
   - Added test for discovery parameter
   - Added test for Accept header handling

3. `bin/test-mcp-get-support.sh`
   - Updated test script for new default behavior
   - Added tests for `/no-sse` endpoint

## Testing

### Automated Tests
```bash
composer test tests/test-mcp-endpoint-get-support.php
```

### Manual Tests
```bash
SITE_URL=https://your-site.com bash bin/test-mcp-get-support.sh
```

## Date
November 14, 2025

## Version
To be included in next release (1.1.0+)


## Files Changed
1. `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php`
   - Added GET handler registration
   - Implemented `handle_mcp_get_request()` method
   - Added `add_cors_headers()` helper method

2. `includes/class-wp-mcp-ai-rest-mcp-methods.php`
   - Updated `add_cors_headers()` to include GET, Accept, and Mcp-Session-Id

3. `tests/test-mcp-endpoint-get-support.php`
   - Comprehensive test suite for GET support
   - Tests for endpoint discovery
   - Tests for SSE establishment
   - Tests for CORS headers
   - Tests for backward compatibility

4. `bin/test-mcp-get-support.sh`
   - Manual testing script for verification

5. `assets/examples/lmstudio-mcp-without-sse.json`
   - Updated with comment explaining the fix

## Testing
### Automated Tests
Run the test suite:
```bash
composer test tests/test-mcp-endpoint-get-support.php
```

### Manual Testing
Use the provided script:
```bash
SITE_URL=https://your-site.com bash bin/test-mcp-get-support.sh
```

### Test with LM Studio
1. Configure LM Studio with:
```json
{
  "mcpServers": {
    "wordpress-site": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "headers": {
        "Authorization": "Bearer your_token_here"
      },
      "timeout": 30000
    }
  }
}
```

2. LM Studio will now:
   - Send GET request for discovery (receives endpoint info)
   - Send POST request for JSON-RPC calls (works as before)
   - Optionally establish SSE connection if needed

## Benefits
1. **LM Studio Compatibility**: Fixes 404 error, enables proper MCP connections
2. **MCP 2024-11-05 Compliance**: Implements latest protocol features
3. **Better Client Experience**: Clients can discover endpoint capabilities automatically
4. **Streamable HTTP**: Supports modern MCP transport method
5. **Session Management**: Ready for Mcp-Session-Id reconnection features

## Migration Notes
No migration needed. This is a backward-compatible enhancement that adds new functionality without breaking existing integrations.

## Related Issues
- Fixes: LM Studio 404 error on MCP connection
- Implements: MCP 2024-11-05 Streamable HTTP transport
- Adds: SSE support to /mcp endpoint
- Enhances: CORS headers for modern MCP clients

## Date
November 14, 2025

## Version
To be included in next release (1.1.0+)
