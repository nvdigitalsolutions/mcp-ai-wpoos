# MCP Endpoint Implementation Summary

## What's New

This update adds a dedicated `/mcp` endpoint implementing the **Model Context Protocol (MCP)** specification with JSON-RPC 2.0 support, and modernizes the existing `/sse` endpoint to current 2024-2025 standards.

## Quick Start

### For MCP Clients (Claude Desktop, LM Studio, etc.)

Use the new `/mcp` endpoint for JSON-RPC 2.0 communication:

```
POST https://your-site.com/wp-json/mcp-ai/v1/mcp
Authorization: Bearer <your_token>
Content-Type: application/json

{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "initialize"
}
```

### For SSE Streaming

The existing `/sse` endpoint now includes modern features:
- Automatic reconnection with 3-second retry interval
- Event IDs for tracking reconnection state
- HTTP/2 compatibility

## Key Features

### MCP Endpoint (`/mcp`)
- ✅ Full JSON-RPC 2.0 protocol support
- ✅ Bidirectional request/response communication
- ✅ Supports all MCP methods: `initialize`, `tools/list`, `tools/call`, `resources/list`, `prompts/list`
- ✅ Proper error handling with standard JSON-RPC error codes
- ✅ Works with existing authentication (Bearer tokens, WordPress nonces)

### SSE Endpoint Updates (`/sse`)
- ✅ Retry directive for automatic reconnection
- ✅ Event IDs for state tracking
- ✅ Modern headers (Cache-Control, Connection, CORS)
- ✅ HTTP/2 ready

## Use Cases

| Scenario | Use Endpoint |
|----------|--------------|
| Tool execution from AI clients | `/mcp` |
| Query available tools | `/mcp` |
| Initialize MCP connection | `/mcp` |
| Real-time event streaming | `/sse` |
| Server-to-client notifications | `/sse` |

## Example: Initialize MCP Connection

**Request:**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "initialize",
  "params": {
    "protocolVersion": "2024-11-05",
    "clientInfo": {
      "name": "My Client",
      "version": "1.0"
    }
  }
}
```

**Response:**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2024-11-05",
    "capabilities": {
      "tools": { "listChanged": true },
      "resources": { "subscribe": false, "listChanged": true },
      "prompts": { "listChanged": true }
    },
    "serverInfo": {
      "name": "WP oOS",
      "version": "1.0.0"
    }
  }
}
```

## Example: Call a Tool

**Request:**
```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "search_content",
    "arguments": {
      "query": "WordPress tutorials"
    }
  }
}
```

**Response:**
```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "[Search results...]"
      }
    ]
  }
}
```

## Client Configuration Examples

### Claude Desktop
Add to your Claude configuration:
```json
{
  "mcpServers": {
    "wp-oos": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.YOUR_TOKEN"
      }
    }
  }
}
```

### LM Studio
Add to your LM Studio server configuration:
```json
{
  "servers": [
    {
      "name": "WP oOS",
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "auth": {
        "type": "bearer",
        "token": "cred_xxxxx.YOUR_TOKEN"
      }
    }
  ]
}
```

## Authentication

Use any of the existing WP oOS authentication methods:

1. **Assistant Credentials** (recommended for remote clients)
   - Generate from assistant editor → API Credentials
   - Format: `Authorization: Bearer cred_xxxxx.SECRET`

2. **WordPress Nonce** (for same-origin requests)
   - Header: `X-WP-Nonce: <nonce>`

3. **Auth0 JWT** (for enterprise setups)
   - Header: `Authorization: Bearer <jwt_token>`

## Documentation

- **Full API Reference**: [`/docs/mcp-endpoint.md`](docs/mcp-endpoint.md)
- **Authentication Guide**: [`/docs/mcp-server-authentication.md`](docs/mcp-server-authentication.md)
- **Model Context Protocol**: [modelcontextprotocol.io](https://modelcontextprotocol.io/)

## Breaking Changes

**None!** This is a fully backward-compatible addition:
- Existing `/sse` endpoint continues to work
- All existing authentication methods work
- No changes to existing tool execution
- Existing tests pass

## Testing

Run the test suite to validate:
```bash
composer test
```

New test file: `/tests/test-mcp-endpoint.php` with 15+ comprehensive test cases.

## Security

- ✅ CodeQL security scan passed
- ✅ Reuses existing authentication infrastructure
- ✅ No new security surface introduced
- ✅ Input validation on all JSON-RPC messages
- ✅ WordPress coding standards compliant

## Technical Stack

- **Protocol**: JSON-RPC 2.0 (RFC 4627)
- **Transport**: HTTP/HTTPS
- **Authentication**: Bearer tokens, WordPress nonces
- **Response Format**: JSON
- **PHP Version**: 7.4+
- **WordPress**: 6.0+

## Support

For issues or questions:
1. Check the [full documentation](docs/mcp-endpoint.md)
2. Review [authentication guide](docs/mcp-server-authentication.md)
3. See [MCP specification](https://modelcontextprotocol.io/)
4. Open an issue on GitHub

## Changelog

### Version 1.0.0
- ✨ Added `/mcp` endpoint with JSON-RPC 2.0 support
- ✨ Implemented all core MCP methods (initialize, tools/list, tools/call, resources/list, prompts/list)
- 🔧 Updated `/sse` endpoint with modern standards
- 🔧 Added retry directive and event IDs for reconnection
- 📚 Added comprehensive API documentation
- ✅ Added 15+ test cases
- 🔒 Passed security scanning

## License

GPL-2.0-or-later (same as the main plugin)
