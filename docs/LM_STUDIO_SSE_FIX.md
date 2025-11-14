# LM Studio MCP Connection Fix - SSE Error Resolution

## Issue Description

LM Studio users were experiencing connection errors when trying to connect to the WP oOS MCP server:

```javascript
[Error]: SSE error: undefined
  code: undefined,
  event: {
    type: 'error',
    message: undefined,
    code: undefined,
    defaultPrevented: false,
    cancelable: false,
    timeStamp: 1933.0909
  }
```

## Root Cause

### The Problem

LM Studio's MCP client implementation sends `Accept: text/event-stream` HTTP header by default when connecting to MCP servers. This is documented in LM Studio's official documentation and MCP implementation examples.

**However**, LM Studio actually uses **Streamable HTTP transport** (MCP 2024-11-05 specification), which expects:
- **GET requests** to return JSON discovery information
- **POST requests** to use JSON-RPC 2.0 protocol

It does **NOT** expect Server-Sent Events (SSE) responses for standard operations.

### What Was Happening

1. LM Studio connected to `/wp-json/mcp-ai/v1/mcp`
2. Sent `Accept: text/event-stream` header (as part of its default MCP client behavior)
3. WordPress endpoint detected the Accept header and triggered SSE mode
4. LM Studio received an SSE stream when it expected JSON
5. Result: "SSE error: undefined" because LM Studio couldn't parse the response

## Solution

### Changes Made

Modified `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php`:

**Before:**
```php
public function handle_mcp_get_request( WP_REST_Request $request ) {
    $wants_streaming = $request->get_param( 'stream' ) === 'true';
    
    // Check Accept header - triggers SSE
    $accept_header = $request->get_header( 'Accept' );
    if ( $accept_header && strpos( $accept_header, 'text/event-stream' ) !== false ) {
        $wants_streaming = true; // ❌ This was the problem!
    }
    
    if ( $wants_streaming ) {
        return $this->handle_sse_handshake( $request );
    }
    
    return $this->return_discovery_info( $request );
}
```

**After:**
```php
public function handle_mcp_get_request( WP_REST_Request $request ) {
    // Only check explicit stream parameter, NOT Accept header
    $wants_streaming = $request->get_param( 'stream' ) === 'true';
    
    if ( $wants_streaming ) {
        return $this->handle_sse_handshake( $request );
    }
    
    // Always return JSON by default ✅
    return $this->return_discovery_info( $request );
}
```

### Key Changes

1. **Removed Accept header check** - Accept header no longer triggers SSE mode
2. **SSE is opt-in only** - Must explicitly use `?stream=true` parameter
3. **Updated discovery response** - Changed transport name from `jsonrpc` to `streamable_http` to match MCP 2024-11-05 spec
4. **Updated documentation** - Clarified when SSE is used vs JSON-RPC

## Correct LM Studio Configuration

This configuration now works without errors:

```json
{
  "mcpServers": {
    "wordpress-site": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET"
      },
      "timeout": 30000
    }
  }
}
```

**Important:** 
- Do **NOT** add `?stream=true` to the URL
- Do **NOT** add SSE-specific configuration
- The `url` should point to `/mcp` endpoint (not `/sse`)

## How It Works Now

### GET Request to `/mcp` (Discovery)

**Request:**
```http
GET /wp-json/mcp-ai/v1/mcp HTTP/1.1
Host: your-site.com
Accept: text/event-stream
Authorization: Bearer cred_xxxxx.SECRET
```

**Response (JSON, not SSE):**
```json
{
  "name": "WP oOS MCP Server",
  "version": "1.0.0",
  "protocolVersion": "2024-11-05",
  "capabilities": {
    "tools": { "listChanged": true },
    "resources": { "subscribe": true, "listChanged": true },
    "prompts": { "listChanged": true }
  },
  "transports": {
    "streamable_http": {
      "endpoint": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "methods": ["GET", "POST"],
      "default": true
    },
    "sse": {
      "endpoint": "https://your-site.com/wp-json/mcp-ai/v1/sse",
      "methods": ["GET"],
      "default": false
    }
  }
}
```

### POST Request to `/mcp` (Tool Execution)

**Request:**
```http
POST /wp-json/mcp-ai/v1/mcp HTTP/1.1
Host: your-site.com
Content-Type: application/json
Authorization: Bearer cred_xxxxx.SECRET

{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/list",
  "params": {}
}
```

**Response:**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "tools": [...]
  }
}
```

## SSE Still Available

SSE streaming is still fully supported for clients that need it:

### Option 1: Dedicated SSE Endpoint
```
GET /wp-json/mcp-ai/v1/sse
```

### Option 2: Explicit Parameter
```
GET /wp-json/mcp-ai/v1/mcp?stream=true
```

## MCP 2024-11-05 Specification Compliance

This fix aligns with the MCP 2024-11-05 specification:

- **Streamable HTTP** is the primary transport (GET for discovery, POST for JSON-RPC)
- **SSE is deprecated** in favor of Streamable HTTP
- **Transport negotiation** happens via discovery endpoint, not Accept headers
- **JSON-RPC 2.0** is used for all tool operations

## Impact

### ✅ Fixed
- LM Studio can now connect successfully
- Other MCP clients using Streamable HTTP work correctly
- Proper MCP 2024-11-05 spec compliance

### ✅ Preserved
- SSE still available for legacy clients
- No breaking changes to existing integrations
- All existing endpoints work as before

### ✅ Improved
- Better alignment with MCP specification
- Clearer separation between transports
- More accurate discovery information

## Testing

To verify the fix works:

```bash
# Test 1: GET request with Accept: text/event-stream (LM Studio scenario)
curl -H "Accept: text/event-stream" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     https://your-site.com/wp-json/mcp-ai/v1/mcp

# Expected: JSON response (not SSE)

# Test 2: POST request for JSON-RPC
curl -X POST \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' \
     https://your-site.com/wp-json/mcp-ai/v1/mcp

# Expected: JSON-RPC response

# Test 3: SSE with explicit parameter
curl -H "Authorization: Bearer YOUR_TOKEN" \
     https://your-site.com/wp-json/mcp-ai/v1/mcp?stream=true

# Expected: text/event-stream response
```

## References

- [LM Studio MCP Documentation](https://lmstudio.ai/docs/app/mcp)
- [MCP 2024-11-05 Specification](https://spec.modelcontextprotocol.io/)
- [Streamable HTTP Transport](https://modelcontextprotocol.io/specification/2025-06-18/basic/transports)
- [WP oOS MCP Endpoint Documentation](mcp-endpoint.md)

## Related Issues

This fix resolves:
- LM Studio "SSE error: undefined" connection errors
- Accept header incorrectly triggering SSE mode
- Misalignment with MCP 2024-11-05 transport specification

---

**Last Updated:** November 14, 2024
