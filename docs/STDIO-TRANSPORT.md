# STDIO Transport for Local Agent Integration

This document describes how to use the STDIO transport for connecting local MCP clients (like Claude Desktop) to WordPress via WP oOS.

## Overview

The STDIO transport allows MCP clients to communicate with WordPress using stdin/stdout instead of HTTP. This is particularly useful for:

- **Claude Desktop** - Native MCP client that primarily uses STDIO transport
- **Local AI agents** - Any MCP client that communicates via process I/O
- **Development/testing** - Direct command-line interaction with the MCP server

## Transport Comparison

| Transport | Protocol | Use Case | Clients |
|-----------|----------|----------|---------|
| **HTTP/REST** | JSON-RPC 2.0 over HTTP | Remote access, web clients | LM Studio, Cline, Continue.dev |
| **SSE** | Server-Sent Events | Real-time streaming | Browser clients, some MCP apps |
| **STDIO** | JSON-RPC 2.0 over stdin/stdout | Local agent integration | Claude Desktop |

## Quick Start

### Basic Usage

Start the STDIO transport server:

```bash
wp mcp-ai stdio
```

This runs an MCP server that:
- Reads JSON-RPC 2.0 requests from stdin (one JSON object per line)
- Writes JSON-RPC 2.0 responses to stdout
- Logs diagnostic messages to stderr

### With Assistant Scoping

Limit the server to a specific assistant's tools:

```bash
wp mcp-ai stdio --assistant-id=123
```

## Claude Desktop Configuration

Add WP oOS as an MCP server in Claude Desktop's configuration file.

### Configuration File Locations

| Platform | Path |
|----------|------|
| macOS | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Windows | `%APPDATA%\Claude\claude_desktop_config.json` |
| Linux | `~/.config/Claude/claude_desktop_config.json` |

### Example Configuration

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "wp",
      "args": ["mcp-ai", "stdio", "--path=/var/www/html/wordpress"]
    }
  }
}
```

### With Assistant Scoping

```json
{
  "mcpServers": {
    "wordpress-content": {
      "command": "wp",
      "args": ["mcp-ai", "stdio", "--path=/var/www/html/wordpress", "--assistant-id=123"]
    }
  }
}
```

### Multiple WordPress Sites

```json
{
  "mcpServers": {
    "blog": {
      "command": "wp",
      "args": ["mcp-ai", "stdio", "--path=/var/www/blog"]
    },
    "shop": {
      "command": "wp",
      "args": ["mcp-ai", "stdio", "--path=/var/www/shop", "--assistant-id=45"]
    }
  }
}
```

## Supported MCP Methods

The STDIO transport supports all standard MCP 2024-11-05 methods:

| Method | Description |
|--------|-------------|
| `initialize` | Initialize connection, returns server capabilities and tools |
| `tools/list` | List available tools |
| `tools/call` | Execute a tool |
| `resources/list` | List available resources (memory files) |
| `prompts/list` | List available prompts (assistants) |
| `shutdown` | Gracefully stop the server |

## JSON-RPC 2.0 Message Format

### Request Format

Each request must be a single line of valid JSON:

```json
{"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}}
```

### Response Format

```json
{"jsonrpc": "2.0", "id": 1, "result": {"protocolVersion": "2024-11-05", "capabilities": {...}}}
```

### Error Response

```json
{"jsonrpc": "2.0", "id": 1, "error": {"code": -32601, "message": "Method not found: unknown_method"}}
```

## Command-Line Testing

You can test the STDIO transport directly from the command line:

### Initialize Connection

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | wp mcp-ai stdio
```

### List Tools

```bash
echo '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' | wp mcp-ai stdio
```

### Call a Tool

```bash
echo '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_posts","arguments":{"post_type":"post","limit":5}}}' | wp mcp-ai stdio
```

## Debug Mode

Enable debug logging to stderr:

```bash
WP_MCP_AI_DEBUG=1 wp mcp-ai stdio
```

Or define in `wp-config.php`:

```php
define( 'WP_MCP_AI_DEBUG', true );
```

## Error Codes

| Code | Meaning |
|------|---------|
| -32700 | Parse error - Invalid JSON |
| -32600 | Invalid Request - Missing required fields |
| -32601 | Method not found |
| -32603 | Internal error - Tool execution failed |

## Security Considerations

### Authentication

The STDIO transport runs within the WordPress environment as the WP-CLI user. This means:

- **No bearer tokens required** - Authentication is handled by WP-CLI's user context
- **User capabilities apply** - Tool access respects WordPress capabilities
- **Assistant scoping works** - Use `--assistant-id` to limit available tools

### Best Practices

1. **Use assistant scoping** - Limit tools to only what's needed
2. **Run as appropriate user** - Use `--user` flag with WP-CLI if needed
3. **Restrict WordPress access** - Standard WordPress security applies

### Example with User Context

```bash
wp mcp-ai stdio --user=editor --assistant-id=123
```

## Troubleshooting

### Common Issues

#### "Assistant not found" Error

The specified assistant ID doesn't exist or isn't published:

```bash
# List available assistants
wp post list --post_type=mcp_ai_assistant --post_status=publish
```

#### "Tool not found" Error

The tool isn't registered or isn't allowed for the assistant:

```bash
# List all available tools
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' | wp mcp-ai stdio
```

#### No Response

Check that:
1. WordPress is properly configured
2. WP-CLI can connect to the database
3. The plugin is active

```bash
# Test WP-CLI connection
wp plugin list | grep wp-mcp-ai
```

### Debug Output

Enable verbose logging:

```bash
WP_MCP_AI_DEBUG=1 wp mcp-ai stdio 2>debug.log
```

Check `debug.log` for diagnostic messages.

## Integration with Agentic Workflows

The STDIO transport integrates seamlessly with WP oOS's agentic workflow capabilities:

1. Claude Desktop sends tool calls via STDIO
2. WP oOS executes tools and returns results
3. Claude Desktop processes results and may issue follow-up calls
4. Multi-step workflows complete automatically

This enables complex WordPress operations like:
- Content creation and publishing
- Media management
- User management
- Custom data manipulation

## Related Documentation

- [MCP Client Configurations](mcp-client-configurations.md) - HTTP/SSE transport setup
- [MCP Server Authentication](mcp-server-authentication.md) - Token-based authentication
- [Tool Reference](tool-reference.md) - Available tools and parameters

---

**Last Updated:** November 2025
