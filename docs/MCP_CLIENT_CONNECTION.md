# Connecting MCP Clients to WP oOS

This guide explains how to connect MCP clients (like LM Studio, Claude Desktop, OpenAI Agent Builder, etc.) to the WP oOS MCP server.

## Client Compatibility Matrix

| Client | Transport | Auth Method | Status | Notes |
|--------|-----------|-------------|--------|-------|
| **OpenAI Agent Builder** | HTTP/REST | Bearer Token | ✅ Fully Supported | Tools included in `initialize` response |
| **Claude Desktop** | stdio/SSE† | Bearer Token | ⚠️ Via Proxy | Requires HTTP-to-stdio bridge (see below) |
| **LM Studio** | HTTP/REST | Bearer Token | ✅ Fully Supported | Native HTTP MCP support |
| **Custom HTTP Clients** | HTTP/REST | Bearer Token | ✅ Fully Supported | Standard JSON-RPC 2.0 |
| **Cline (VSCode)** | HTTP/REST | Bearer Token | ✅ Fully Supported | Configure as HTTP MCP server |
| **Continue.dev** | HTTP/REST | Bearer Token | ✅ Fully Supported | Add as MCP server in config |

† Claude Desktop primarily uses stdio transport. For HTTP servers like WP oOS, use `@modelcontextprotocol/server-http` as a bridge.

## MCP Standard Compliance

WP oOS implements the **Model Context Protocol (MCP) 2024-11-05** specification strictly:

- **Protocol**: JSON-RPC 2.0 over HTTP
- **Transport**: POST requests for all operations  
- **Streaming**: Server-Sent Events (SSE) support
- **Methods**: `initialize`, `tools/list`, `tools/call`, `resources/list`, `prompts/list`
- **CORS**: Full cross-origin support with configurable origins
- **Content-Type**: `application/json; charset=utf-8`

## Connection Details

### Endpoint
```
POST https://your-site.com/wp-json/mcp-ai/v1/mcp
```

### Authentication

The MCP endpoint supports multiple authentication methods:

1. **Bearer Token** (Recommended for external clients)
   ```
   Authorization: Bearer cred_xxxxx.SECRET
   ```
   - Issue credentials from WordPress admin: **Assistants → [Assistant] → Credentials**

2. **Mesh API Key** (For mesh network integration)
   ```
   X-WP-MCP-AI-Mesh-Key: your-mesh-key
   ```

3. **WordPress Nonce** (Internal diagnostic testing only)
   - Only works for logged-in admin users making internal requests
   - Not available for external MCP clients

### Initial Handshake

All MCP clients must start with an `initialize` request:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "initialize",
  "params": {}
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
    },
    "instructions": "This is a WordPress site...",
    "tools": [...]
  }
}
```

## Client-Specific Configuration

### Claude Desktop

Add to your Claude Desktop config file (`~/Library/Application Support/Claude/claude_desktop_config.json` on Mac):

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-http"],
      "env": {
        "MCP_SERVER_URL": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
        "MCP_SERVER_AUTH": "Bearer cred_xxxxx.SECRET"
      }
    }
  }
}
```

### LM Studio

LM Studio connects to MCP servers via HTTP transport:

1. Open LM Studio
2. Navigate to MCP Servers settings
3. Add new HTTP server:
   - **URL**: `https://your-site.com/wp-json/mcp-ai/v1/mcp`
   - **Auth Type**: Bearer Token
   - **Token**: `cred_xxxxx.SECRET`
4. Click "Test Connection"
5. Save configuration

### OpenAI Agent Builder

Configure the MCP server connection:

```python
from mcp.client import ClientSession

async with ClientSession() as session:
    await session.initialize(
        server_url="https://your-site.com/wp-json/mcp-ai/v1/mcp",
        headers={
            "Authorization": "Bearer cred_xxxxx.SECRET"
        }
    )
    
    # List available tools
    tools = await session.list_tools()
```

**Features**:
- Tools are automatically included in the `initialize` response
- No separate `tools/list` call needed
- Full WordPress tool catalog available immediately

### Cline (VSCode Extension)

Add to your Cline MCP settings (`.vscode/mcp_config.json` or VSCode settings):

```json
{
  "mcpServers": {
    "wordpress": {
      "type": "http",
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET"
      }
    }
  }
}
```

### Continue.dev (VSCode/JetBrains)

Add to your `~/.continue/config.json`:

```json
{
  "mcpServers": [
    {
      "name": "WordPress",
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET"
      }
    }
  ]
}
```

### Custom MCP Client

Example using Node.js:

```javascript
const response = await fetch('https://your-site.com/wp-json/mcp-ai/v1/mcp', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer cred_xxxxx.SECRET'
  },
  body: JSON.stringify({
    jsonrpc: '2.0',
    id: 1,
    method: 'initialize',
    params: {}
  })
});

const result = await response.json();
console.log('Server capabilities:', result.result);
```

## Available Methods

### 1. `initialize`
Get server capabilities and configuration.

### 2. `tools/list`
List all available WordPress tools.

### 3. `tools/call`
Execute a specific tool.

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "search_content",
    "arguments": {
      "query": "WordPress",
      "post_type": "post"
    }
  }
}
```

### 4. `resources/list`
List available WordPress resources (posts, pages, etc.).

### 5. `prompts/list`
List available prompt templates.

## Streaming Support

For streaming responses, use the SSE endpoint:

```
GET https://your-site.com/wp-json/mcp-ai/v1/sse
```

Set the `Accept: text/event-stream` header for Server-Sent Events.

## Troubleshooting

### Connection Refused
- Verify the site URL is correct
- Check that mod_rewrite is enabled (for pretty permalinks)
- Ensure REST API is not disabled

### 401 Unauthorized
- Verify bearer token is valid and not expired
- Check that credentials were issued for the correct assistant
- Ensure auth header format is correct: `Authorization: Bearer TOKEN`

### 404 Not Found
- Check permalink structure (not set to "Plain")
- Verify REST API is accessible: `https://your-site.com/wp-json/`
- Check `.htaccess` file for REST API blocks

### Tools Not Available
- Verify tools are assigned to the assistant
- Check user/token has required capabilities
- Review tool registry initialization in error logs

## Security Notes

- **Always use HTTPS** for production connections
- **Rotate credentials** regularly
- **Limit tool access** by assigning only necessary tools to assistants
- **Monitor usage** via the Token Manager dashboard
- **Enable logging** for debugging: Settings → Enable Logging

## Testing Your Connection

Use the built-in diagnostic page:

```
https://your-site.com/wp-admin/tools.php?page=wp-mcp-ai-mcp-diagnostic
```

This page provides:
- Protocol information
- Connectivity tests
- Method testing
- Authentication verification
- Tool registry status

## Further Reading

- [MCP Specification](https://spec.modelcontextprotocol.io/)
- [WP oOS Documentation](../README.md)
- [Tool Reference](./tool-reference.md)
- [REST API Reference](./rest-api.md)
