# MCP Endpoint GET Support - Fix for LM Studio 404 Error

## Issue
LM Studio users were experiencing a 404 error when trying to connect to the MCP endpoint at `/wp-json/mcp-ai/v1/mcp`.

## Root Cause
The `/mcp` endpoint only supported POST requests for JSON-RPC 2.0 protocol. According to the MCP 2024-11-05 specification, the endpoint should also support:
1. GET requests for endpoint discovery
2. GET requests with `Accept: text/event-stream` header for SSE connections
3. Streamable HTTP transport

## Solution
Added GET request support to the `/mcp` endpoint with the following behavior:

### GET Request Handling
- **Without `Accept: text/event-stream`**: Returns JSON discovery information including:
  - Server name, version, and protocol version
  - Available capabilities (tools, resources, prompts, SSE)
  - Supported transports (JSON-RPC POST, SSE GET)
  - Endpoint URLs for all MCP services

- **With `Accept: text/event-stream`**: Establishes Server-Sent Events connection for real-time streaming

### CORS Enhancements
Updated CORS headers to support MCP 2024-11-05 requirements:
- Added `GET` to `Access-Control-Allow-Methods`
- Added `Accept` and `Mcp-Session-Id` to `Access-Control-Allow-Headers`
- Added `Access-Control-Expose-Headers: Mcp-Session-Id` for session management

### Backward Compatibility
- POST requests for JSON-RPC 2.0 continue to work as before
- OPTIONS requests for CORS preflight continue to work
- No breaking changes to existing integrations

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
