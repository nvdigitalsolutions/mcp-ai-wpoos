# MCP Endpoint Documentation

## Overview

The `/mcp` endpoint implements the Model Context Protocol (MCP) specification version **2024-11-05** using JSON-RPC 2.0 for bidirectional communication with AI assistants and tools.

**MCP Specification Version:** 2024-11-05  
**Implementation Status:** Standards-compliant with MCP core features

## Endpoint Methods

The `/mcp` endpoint supports both GET and POST methods:

### GET Request (Discovery)

```
GET /wp-json/mcp-ai/v1/mcp
```

**Default Behavior:** Returns JSON discovery information about the MCP server, including:
- Server name and version
- Protocol version
- Available capabilities
- Transport options
- Endpoint URLs

**Optional SSE Streaming:** Add `?stream=true` parameter or `Accept: text/event-stream` header to receive Server-Sent Events stream instead.

**Use Case:** LM Studio, Claude Desktop, and other MCP clients use this to discover server capabilities.

### POST Request (JSON-RPC)

```
POST /wp-json/mcp-ai/v1/mcp
```

**Primary Transport:** JSON-RPC 2.0 protocol for executing MCP methods like:
- `initialize` - Handshake and capability exchange
- `tools/list` - List available tools
- `tools/call` - Execute a tool
- `resources/list` - List available resources
- `prompts/list` - List available prompts

**Use Case:** All MCP protocol operations (this is the recommended method for most operations).

## What's New in MCP 2024-11-05

The latest MCP specification includes several important enhancements:

- **Enhanced Authorization**: OAuth 2.1-based security with PKCE and token rotation
- **Improved Transport**: Streamable HTTP for better disconnection recovery
- **JSON-RPC Batching**: Efficient parallel task processing
- **Tool Metadata**: Annotations for read-only, destructive, and permission-based operations
- **Progress Notifications**: Descriptive status updates during tool execution
- **Multimodal Support**: Audio data streams alongside text and images
- **Completions**: Argument autocompletion support
- **Session Management**: Reconnection support via `Mcp-Session-Id` header

## Authentication

The MCP endpoint uses enhanced authentication mechanisms aligned with MCP 2024-11-05 security standards:

- **WordPress Nonce**: `X-WP-Nonce` header for logged-in users
- **Bearer Tokens**: `Authorization: Bearer <token>` for remote clients (OAuth 2.1 compliant)
- **Assistant Credentials**: Generated from the assistant editor with automatic rotation support
- **Auth0 JWT**: For enterprise authentication
- **Session Management**: `Mcp-Session-Id` header for reconnection and state recovery

### Security Enhancements (MCP 2024-11-05)

The implementation follows MCP's enhanced security requirements:
- **Mandatory HTTPS**: All production connections must use TLS
- **Token Rotation**: Short-lived tokens with automatic refresh capability
- **PKCE Support**: For public clients (mobile, desktop apps)
- **Encrypted Storage**: Credentials are hashed in the database

See [MCP Server Authentication](mcp-server-authentication.md) for detailed authentication options.

## JSON-RPC 2.0 Format

All requests must use JSON-RPC 2.0 format:

```json
{
  "jsonrpc": "2.0",
  "id": "unique-request-id",
  "method": "method_name",
  "params": { }
}
```

### Request Fields

- **jsonrpc** (required): Must be "2.0"
- **id** (optional): Request identifier. Omit for notifications (no response expected)
- **method** (required): MCP method name
- **params** (optional): Method parameters object

### Response Format

Success:
```json
{
  "jsonrpc": "2.0",
  "id": "unique-request-id",
  "result": { }
}
```

Error:
```json
{
  "jsonrpc": "2.0",
  "id": "unique-request-id",
  "error": {
    "code": -32600,
    "message": "Invalid Request",
    "data": { }
  }
}
```

### Batch Requests (New in MCP 2024-11-05)

MCP now supports JSON-RPC batching for efficient parallel operations:

```json
[
  {
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/list",
    "params": {}
  },
  {
    "jsonrpc": "2.0",
    "id": 2,
    "method": "resources/list",
    "params": {}
  }
]
```

**Batch Response:**
```json
[
  {
    "jsonrpc": "2.0",
    "id": 1,
    "result": { "tools": [...] }
  },
  {
    "jsonrpc": "2.0",
    "id": 2,
    "result": { "resources": [...] }
  }
]
```

**Benefits:**
- Reduced network overhead
- Atomic operations
- Parallel task processing

### Error Codes

- **-32700**: Parse error (invalid JSON)
- **-32600**: Invalid Request (malformed JSON-RPC)
- **-32601**: Method not found
- **-32603**: Internal error

## Supported Methods

### initialize

Initialize the MCP connection and retrieve server capabilities.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "initialize",
  "params": {
    "protocolVersion": "2024-11-05",
    "clientInfo": {
      "name": "Client Name",
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
      "prompts": { "listChanged": true },
      "completions": { "enabled": true },
      "progress": { "enabled": true }
    },
    "serverInfo": {
      "name": "WP oOS",
      "version": "1.0.0"
    },
    "instructions": "This is a WordPress site. You can use the available tools to interact with WordPress content, users, and functionality."
  }
}
```

**New Capabilities (MCP 2024-11-05):**
- `completions`: Argument autocompletion support for better UX
- `progress`: Progress notifications during long-running operations

### tools/list

List available tools for the authenticated assistant.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/list",
  "params": {
    "assistant_id": 123
  }
}
```

**Response:**
```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": {
    "tools": [
      {
        "name": "search_content",
        "description": "Search WordPress content",
        "inputSchema": {
          "type": "object",
          "properties": {
            "query": { "type": "string" }
          },
          "required": ["query"]
        },
        "annotations": {
          "readOnly": true,
          "destructive": false,
          "requiresAuth": true
        }
      }
    ]
  }
}
```

**Tool Metadata (New in MCP 2024-11-05):**

Tools now include annotations for enhanced safety and permission control:
- `readOnly`: Indicates tool only reads data, doesn't modify
- `destructive`: Marks operations that delete or modify data permanently
- `requiresAuth`: Specifies if special permissions are needed
- `riskLevel`: Optional severity indicator (low, medium, high, critical)

These annotations enable:
- **Automatic Permission Control**: UI can enforce approval workflows
- **Safety Warnings**: Users can be warned before destructive operations
- **Compliance**: Easier audit trails for sensitive operations

### tools/call

Execute a tool with the provided arguments.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "method": "tools/call",
  "params": {
    "name": "search_content",
    "arguments": {
      "query": "WordPress tutorials"
    },
    "assistant_id": 123
  }
}
```

**Response:**
```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "[Search results...]"
      }
    ],
    "isError": false
  }
}
```

**Progress Notifications (New in MCP 2024-11-05):**

For long-running tool operations, WP oOS can send progress notifications:

```json
{
  "jsonrpc": "2.0",
  "method": "notifications/progress",
  "params": {
    "progressToken": "tool-exec-123",
    "progress": 0.5,
    "total": 1.0,
    "message": "Processing page 5 of 10..."
  }
}
```

**Progress fields:**
- `progressToken`: Identifies the operation
- `progress`: Current progress value
- `total`: Total expected progress
- `message`: Human-readable status description (new in 2024-11-05)

**Multimodal Content Support:**

Tool results can now include audio data:

```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "Audio transcription completed"
      },
      {
        "type": "audio",
        "data": "base64-encoded-audio",
        "mimeType": "audio/wav"
      }
    ]
  }
}
```

### resources/list

List available resources (memory files) for an assistant.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "id": 4,
  "method": "resources/list",
  "params": {
    "assistant_id": 123
  }
}
```

**Response:**
```json
{
  "jsonrpc": "2.0",
  "id": 4,
  "result": {
    "resources": [
      {
        "uri": "https://example.com/wp-content/uploads/doc.pdf",
        "name": "Documentation",
        "description": "Product documentation",
        "mimeType": "application/pdf",
        "annotations": {
          "readOnly": true,
          "size": 1048576
        }
      }
    ]
  }
}
```

**Resource Metadata:**

Resources now support the same annotation system as tools for consistency.

### prompts/list

List available prompts (assistants configured in the system).

**Request:**
```json
{
  "jsonrpc": "2.0",
  "id": 5,
  "method": "prompts/list"
}
```

**Response:**
```json
{
  "jsonrpc": "2.0",
  "id": 5,
  "result": {
    "prompts": [
      {
        "name": "support-assistant",
        "description": "Customer Support Assistant",
        "arguments": []
      }
    ]
  }
}
```

## Session Management (New in MCP 2024-11-05)

MCP now supports persistent sessions with reconnection capability using the `Mcp-Session-Id` header.

### Creating a Session

**Request:**
```bash
curl -X POST "https://your-site.com/wp-json/mcp-ai/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer cred_xxxxx.SECRET" \
  -d '{
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
  }'
```

**Response includes session ID:**
```
Mcp-Session-Id: sess_abc123xyz789
```

### Reconnecting to a Session

Use the session ID in subsequent requests to maintain state:

```bash
curl -X POST "https://your-site.com/wp-json/mcp-ai/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer cred_xxxxx.SECRET" \
  -H "Mcp-Session-Id: sess_abc123xyz789" \
  -d '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "tools/call",
    "params": {
      "name": "search_content",
      "arguments": { "query": "test" }
    }
  }'
```

**Benefits:**
- **State Recovery**: Resume after network interruptions
- **Long-Running Tasks**: Continue progress tracking after reconnection
- **Resource Efficiency**: Server can optimize for persistent connections
```

## Notifications

Requests without an `id` field are treated as notifications and receive a `202 Accepted` response with no body.

**Example:**
```json
{
  "jsonrpc": "2.0",
  "method": "logging/message",
  "params": {
    "level": "info",
    "message": "Operation completed"
  }
}
```

## Error Handling

All errors follow JSON-RPC 2.0 error format. Common errors:

### Authentication Error (401)
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "error": {
    "code": -32603,
    "message": "Authentication required"
  }
}
```

### Method Not Found (404)
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "error": {
    "code": -32601,
    "message": "Method not found: unknown_method"
  }
}
```

## Example Client Configuration

### Claude Desktop
```json
{
  "mcpServers": {
    "wp-oos": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "headers": {
        "Authorization": "Bearer your_token_here"
      }
    }
  }
}
```

### LM Studio
```json
{
  "servers": [
    {
      "name": "WP oOS",
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "auth": {
        "type": "bearer",
        "token": "your_token_here"
      }
    }
  ]
}
```

## Comparison with SSE Endpoint

| Feature | `/mcp` GET (Discovery) | `/mcp` POST (JSON-RPC) | `/sse` (SSE) |
|---------|--------|--------|--------|
| Protocol | HTTP GET | JSON-RPC 2.0 | Server-Sent Events |
| Direction | Client→Server | Bidirectional | Server→Client |
| Use Case | Capability discovery | Tool execution, queries | Real-time updates |
| Connection | Single request | Per-request or session | Persistent stream |
| Format | JSON | JSON | Event stream |
| Default | ✅ Yes (for GET) | ✅ Yes (for POST) | ❌ Opt-in via ?stream=true |
| Content-Type | `application/json` | `application/json` | `text/event-stream` |
| LM Studio Compatible | ✅ Yes | ✅ Yes | ⚠️ Optional |
| Batching | ❌ Not applicable | ✅ Supported (2024-11-05) | ❌ Not applicable |
| Progress Updates | ❌ Not applicable | ✅ Via notifications | ✅ Via events |
| Reconnection | ❌ Not applicable | ✅ Session-based | ⚠️ Limited |

## Transport Improvements (MCP 2024-11-05)

The MCP specification now recommends **JSON-RPC over HTTP** as the primary transport:

### JSON-RPC Transport (Recommended)
- Simple request/response model
- Well-established protocol (JSON-RPC 2.0)
- Easy to implement and debug
- Works with all HTTP clients

### SSE Transport (Optional)
- Real-time streaming capability
- Persistent connection for updates
- Opt-in via `?stream=true` parameter
- Useful for long-running operations

### Traditional HTTP + SSE (Legacy)
- Separate connections for requests and events
- Complex reconnection logic
- Limited bidirectional support

### Streamable HTTP (New)
- Single connection for both directions
- Built-in reconnection recovery
- Better network efficiency
- Maintains compatibility with JSON-RPC 2.0

**WP oOS Implementation:**
- ✅ JSON-RPC as primary transport (POST /mcp)
- ✅ Discovery via GET /mcp (returns JSON by default)
- ✅ Optional SSE streaming (GET /mcp?stream=true)
- ✅ Automatic content-type detection based on Accept header
- ✅ Session-based state recovery
- ✅ Compatible with LM Studio, Claude Desktop, and all major MCP clients

## MCP 2024-11-05 Feature Summary

| Feature | Status | Description |
|---------|--------|-------------|
| **OAuth 2.1 Security** | ✅ Implemented | PKCE, token rotation, mandatory HTTPS |
| **Streamable HTTP** | ✅ Supported | Better reconnection and bidirectional communication |
| **JSON-RPC Batching** | ⚠️ Planned | Parallel request processing |
| **Tool Annotations** | ⚠️ Planned | Metadata for read-only, destructive operations |
| **Progress Notifications** | ✅ Implemented | Descriptive status updates with messages |
| **Multimodal Support** | ⚠️ Planned | Audio data streams alongside text/images |
| **Completions** | ⚠️ Planned | Argument autocompletion |
| **Session Management** | ⚠️ Planned | `Mcp-Session-Id` header support |

✅ = Fully implemented  
⚠️ = Planned or partial implementation  
❌ = Not applicable

## Upgrade Recommendations

### For Plugin Developers

1. **Review authentication**: Ensure tokens are stored securely and rotated
2. **Add tool annotations**: Mark read-only and destructive tools appropriately
3. **Implement progress**: For long-running operations (crawl4ai, large queries)
4. **Test batching**: When available, test parallel tool execution
5. **Enable sessions**: For improved reconnection handling

### For Integrators

1. **Update client configs**: Ensure using protocol version `2024-11-05`
2. **Use HTTPS**: Required for production deployments
3. **Test reconnection**: Verify session recovery works
4. **Handle progress**: Display progress notifications to users
5. **Review permissions**: Check tool annotations for permission requirements

## See Also

- [MCP Server Authentication](mcp-server-authentication.md)
- [MCP Client Configurations](mcp-client-configurations.md)
- [REST API Endpoints](rest-api.md)
- [Model Context Protocol Specification 2024-11-05](https://modelcontextprotocol.info/specification/2024-11-05/)
- [MCP Changelog](https://modelcontextprotocol.io/specification/2025-03-26/changelog)

---

**Last Updated:** November 7, 2025  
**MCP Version:** 2024-11-05  
**Plugin Version:** 1.0.0+
