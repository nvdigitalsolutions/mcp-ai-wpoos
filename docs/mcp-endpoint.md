# MCP Endpoint Documentation

## Overview

The `/mcp` endpoint implements the Model Context Protocol (MCP) specification using JSON-RPC 2.0 for bidirectional communication with AI assistants and tools.

## Endpoint URL

```
POST /wp-json/mcp-ai/v1/mcp
```

## Authentication

The MCP endpoint uses the same authentication mechanisms as other WP oOS endpoints:

- **WordPress Nonce**: `X-WP-Nonce` header for logged-in users
- **Bearer Tokens**: `Authorization: Bearer <token>` for remote clients
- **Assistant Credentials**: Generated from the assistant editor
- **Auth0 JWT**: For enterprise authentication

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
      "experimental": {
        "streamableHttp": true,
        "sessionManagement": true
      }
    },
    "serverInfo": {
      "name": "WP oOS",
      "version": "1.0.0"
    }
  }
}
```

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
        }
      }
    ]
  }
}
```

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
        "mimeType": "application/pdf"
      }
    ]
  }
}
```

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

## Streamable Features

WP oOS supports the following MCP streamable features as indicated in the `experimental` capabilities:

### Streamable HTTP

The server supports streaming HTTP transport for real-time communication. This is indicated by:
```json
"experimental": {
  "streamableHttp": true
}
```

This capability enables:
- Server-Sent Events (SSE) for progress notifications
- Streaming responses for long-running operations
- Real-time updates during tool execution

### Session Management

The server supports session management for maintaining state across requests:
```json
"experimental": {
  "sessionManagement": true
}
```

Session management enables:
- Persistent context across multiple MCP requests
- Session-scoped credentials and permissions
- Efficient resource management

### Progress Notifications

Tools can report progress during long-running operations. To enable progress tracking, include a `progressToken` in the request metadata:

**Request with progress token:**
```json
{
  "jsonrpc": "2.0",
  "id": 42,
  "method": "tools/call",
  "params": {
    "name": "run_crawl4ai_job",
    "arguments": {
      "url": "https://example.com"
    },
    "_meta": {
      "progressToken": "abc123"
    }
  }
}
```

**Progress notifications** (when streaming is available):
```json
{
  "jsonrpc": "2.0",
  "method": "notifications/progress",
  "params": {
    "progressToken": "abc123",
    "progress": 50,
    "total": 100,
    "message": "Halfway done crawling"
  }
}
```

**Note:** Progress notifications require streaming transport (SSE). In the current HTTP/REST implementation, progress is tracked internally but not streamed to clients during execution.

### Tool Annotations

Tools can provide annotations to help clients understand their behavior:

**Example tool with annotations:**
```json
{
  "name": "delete_post",
  "description": "Delete a WordPress post",
  "inputSchema": { ... },
  "annotations": {
    "destructive": true,
    "requiresConfirmation": true
  }
}
```

**Supported annotations:**
- `readOnly`: Tool only reads data, doesn't modify (boolean)
- `destructive`: Tool modifies or deletes data (boolean)
- `requiresConfirmation`: Client should confirm before executing (boolean)

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

| Feature | `/mcp` | `/sse` |
|---------|--------|--------|
| Protocol | JSON-RPC 2.0 | Server-Sent Events |
| Direction | Bidirectional (request/response) | Unidirectional (server→client) |
| Use Case | Tool execution, queries | Real-time updates, streaming |
| Connection | Per-request | Persistent |
| Format | JSON | Event stream |

## See Also

- [MCP Server Authentication](mcp-server-authentication.md)
- [REST API Endpoints](rest-api.md)
- [Model Context Protocol Specification](https://modelcontextprotocol.io/)
