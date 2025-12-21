# MCP Client Configuration Guide

## Overview

This guide provides comprehensive configuration examples for connecting various MCP (Model Context Protocol) clients to WordPress WP oOS.

## ⚠️ Getting 404 Errors?

If you're seeing `SSE error: Non-200 status code (404)` or similar errors, see the comprehensive **[404 Troubleshooting Guide](lmstudio-404-troubleshooting.md)** which covers:
- REST API activation
- Permalink configuration
- Security plugin issues
- LM Studio-specific fixes

## Three Connection Methods

WordPress WP oOS supports **three distinct methods** for MCP connectivity:

### Method 1: JSON-RPC 2.0 over HTTP (Recommended for Remote)

**Endpoint:** `/wp-json/mcp-ai/v1/mcp`

**Characteristics:**
- Standard JSON-RPC 2.0 protocol
- **No SSE (Server-Sent Events) required**
- Bidirectional request/response communication
- Works with all MCP clients
- Better compatibility and reliability

**When to use:**
- Default choice for most remote clients
- When experiencing SSE content-type errors
- For maximum compatibility
- For LM Studio, Cursor, Cline, Continue.dev

### Method 2: SSE Streaming (Optional)

**Endpoint:** `/wp-json/mcp-ai/v1/sse` or `/wp-json/mcp-ai/v1/assistants`

**Characteristics:**
- Server-Sent Events for real-time updates
- Requires `Content-Type: text/event-stream` support
- Unidirectional server→client streaming
- Better for real-time scenarios

**When to use:**
- When you need real-time streaming updates
- When client explicitly supports and requests SSE

### Method 3: STDIO Transport (Local Agents)

**Command:** `wp mcp-ai stdio`

**Characteristics:**
- JSON-RPC 2.0 over stdin/stdout
- No network required (local only)
- Ideal for local MCP clients
- Native support in Claude Desktop

**When to use:**
- For Claude Desktop
- For local AI agents
- When WordPress is on the same machine as the MCP client

See [STDIO Transport Documentation](STDIO-TRANSPORT.md) for complete details.

## Client-Specific Configurations

### LM Studio

**Recommended Configuration:**

```json
{
  "mcpServers": {
    "wordpress-mcp": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET"
      },
      "timeout": 30000
    }
  }
}
```

**Configuration Steps:**
1. Open LM Studio
2. Navigate to **Settings** → **MCP Servers**
3. Click **Add Server** or edit your `mcp.json` file
4. Use the configuration above, replacing:
   - `your-site.com` with your WordPress site URL
   - `cred_xxxxx.SECRET` with your actual credential token
5. **Do NOT** add `?stream=true` to the URL
6. Save and LM Studio will automatically connect

**Important Notes:**
- LM Studio uses `mcpServers` object format (same as Claude Desktop), not an array
- LM Studio uses **Streamable HTTP transport** (MCP 2024-11-05 spec)
- The `/mcp` endpoint returns JSON by default (NOT SSE)
- LM Studio sends `Accept: text/event-stream` header but expects JSON responses

**✅ Fixed - November 2024:**

Previous issue where LM Studio showed `SSE error: undefined` has been resolved!

**What was the problem?**
- LM Studio sends `Accept: text/event-stream` header by default
- Previous code incorrectly interpreted this as a request for SSE streaming
- LM Studio received SSE when it expected JSON, causing "SSE error: undefined"

**What was fixed?**
- `/mcp` endpoint no longer triggers SSE based on Accept header
- GET requests always return JSON discovery information
- POST requests use JSON-RPC 2.0 protocol
- SSE is only used when explicitly requested via `?stream=true` parameter

See [LM Studio SSE Fix Documentation](LM_STUDIO_SSE_FIX.md) for complete details.

---

### Claude Desktop

Claude Desktop supports both STDIO and HTTP transports. **STDIO is recommended for local WordPress installations.**

#### Option 1: STDIO Transport (Recommended for Local)

For WordPress installations on the same machine as Claude Desktop:

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "wp",
      "args": ["mcp-ai", "stdio", "--path=/path/to/wordpress"]
    }
  }
}
```

**Benefits:**
- No authentication tokens needed
- No network configuration required
- Better performance (no HTTP overhead)
- Works offline

See [STDIO Transport Documentation](STDIO-TRANSPORT.md) for complete setup instructions.

#### Option 2: HTTP Transport (For Remote Sites)

For remote WordPress sites or when STDIO isn't available:

```json
{
  "mcpServers": {
    "wordpress": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET"
      },
      "sse": true,
      "timeout": 30000
    }
  }
}
```

**Configuration File Location:**
- **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`
- **Linux**: `~/.config/Claude/claude_desktop_config.json`

**Steps:**
1. Locate the configuration file
2. Add or edit the `mcpServers` section
3. Replace `your-site.com` and credential token
4. Save the file
5. Restart Claude Desktop

**Multi-Site Configuration:**

```json
{
  "mcpServers": {
    "wordpress-editorial": {
      "url": "https://editorial.example.com/wp-json/mcp-ai/v1",
      "headers": {
        "Authorization": "Bearer cred_aaa.SECRET1"
      },
      "sse": true
    },
    "wordpress-support": {
      "url": "https://support.example.com/wp-json/mcp-ai/v1",
      "headers": {
        "Authorization": "Bearer cred_bbb.SECRET2"
      },
      "sse": true
    }
  }
}
```

---

### Cursor IDE

**Configuration:**

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": [
        "-y",
        "@modelcontextprotocol/server-http",
        "https://your-site.com/wp-json/mcp-ai/v1/mcp"
      ],
      "env": {
        "AUTHORIZATION": "Bearer cred_xxxxx.SECRET"
      }
    }
  }
}
```

**Steps:**
1. Open Cursor settings (Cmd/Ctrl + ,)
2. Navigate to **Extensions** → **MCP**
3. Add the configuration
4. Replace URL and token
5. Restart Cursor

---

### Continue.dev (VS Code Extension)

**Configuration:**

```json
{
  "mcpServers": [
    {
      "name": "WordPress MCP",
      "transport": {
        "type": "sse",
        "url": "https://your-site.com/wp-json/mcp-ai/v1/sse",
        "headers": {
          "Authorization": "Bearer cred_xxxxx.SECRET"
        }
      }
    }
  ]
}
```

**Configuration File:** `.continue/config.json`

**Steps:**
1. Install Continue.dev extension
2. Open `.continue/config.json`
3. Add the MCP server configuration
4. Replace URL and token
5. Reload VS Code window

---

### Cline

**Configuration:**

```json
{
  "mcpServers": {
    "wordpress": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "apiKey": "cred_xxxxx.SECRET",
      "transport": "stdio"
    }
  }
}
```

**Steps:**
1. Open Cline extension settings
2. Navigate to **MCP Servers**
3. Add configuration
4. Update URL and token
5. Save and test

---

### OpenAI GPT Actions

**Configuration:**

```json
{
  "actions": [
    {
      "type": "mcp",
      "name": "WordPress Tools",
      "description": "Access WordPress site tools via MCP",
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "auth": {
        "type": "bearer",
        "token": "cred_xxxxx.SECRET"
      }
    }
  ]
}
```

**Steps:**
1. Go to OpenAI platform
2. Navigate to **Actions** or **Custom GPTs**
3. Add new action
4. Configure authentication
5. Test the action

---

## Authentication

All configurations require a bearer token credential generated from WordPress:

### Generating Credentials

1. Log in to WordPress admin
2. Go to **AI Assistants**
3. Select or create an assistant
4. Scroll to **API Credentials** meta box (admin only)
5. Click **Generate Credential**
6. Copy the token (format: `cred_xxxxx.SECRET`)
7. **Important:** Token is shown only once!

### Credential Properties

- **Format:** `cred_[identifier].[secret]`
- **Scope:** Tied to specific assistant
- **Revocation:** Can be revoked in WordPress admin
- **Multiple:** Generate separate credentials for different clients

### WordPress Settings - What You Need

#### ✅ Required: None (MCP works by default)

The MCP endpoints work out of the box once you:
- Have the plugin active
- Have at least one published assistant
- Have generated an API credential

#### ❌ NOT Required: "Enable REST Assistant Creation"

**This setting is ONLY for creating new assistants via API.**

The setting "Enable REST Assistant Creation" controls:
- POST requests to `/assistants` (creating assistants remotely)
- Only needed if you want API clients to create assistants

**For normal MCP use (connecting LM Studio, Claude, etc.), this setting can be OFF.**

MCP clients can still:
- ✅ List assistants (GET `/assistants`)
- ✅ Send chat messages (POST `/chat`)
- ✅ Execute tools (POST `/tools`)
- ✅ Use JSON-RPC (POST `/mcp`)
- ✅ Stream via SSE (GET `/sse`)

---

## Testing Connections

### Test JSON-RPC Endpoint

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
        "name": "Test Client",
        "version": "1.0"
      }
    }
  }'
```

**Expected Response:**
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

### Test Assistants Directory

```bash
curl -H "Authorization: Bearer cred_xxxxx.SECRET" \
  https://your-site.com/wp-json/mcp-ai/v1/assistants
```

### Test Tools List

```bash
curl -X POST "https://your-site.com/wp-json/mcp-ai/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer cred_xxxxx.SECRET" \
  -d '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "tools/list",
    "params": {}
  }'
```

---

## Common Issues and Solutions

### Issue: "Empty request body" Error

**Error:**
```json
{ "jsonrpc": "2.0", "id": null, "error": { "code": -32700, "message": "Parse error: Empty request body" } }
```

**Cause:** POST request reached endpoint but JSON payload wasn't received.

**Solutions:**
1. **Include Content-Type header**: `Content-Type: application/json`
2. **Use POST method** (not GET)
3. **Check security plugins** - They may strip POST data
4. **Verify JSON is in request body** - Not in URL parameters

**Correct curl command:**
```bash
curl -X POST "https://your-site.com/wp-json/mcp-ai/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer cred_xxxxx.SECRET" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","clientInfo":{"name":"Test","version":"1.0"}}}'
```

### Issue: "Invalid content type, expected text/event-stream"

**Cause:** Client is configured for SSE but server is returning JSON.

**Solution:**
1. Use JSON-RPC endpoint: `/wp-json/mcp-ai/v1/mcp`
2. Remove SSE configuration
3. Use `lmstudio-mcp-without-sse.json` example

### Issue: Connection Timeout

**Cause:** Network/firewall issues or wrong URL.

**Solutions:**
1. Verify site is accessible via HTTPS
2. Check REST API is enabled (`/wp-json`)
3. Verify no security plugin blocks endpoint
4. Test URL in browser first

### Issue: 401 Unauthorized

**Cause:** Invalid or expired token.

**Solutions:**
1. Verify complete token (including `cred_` prefix)
2. Check credential not revoked in admin
3. Generate new credential
4. Remove spaces/line breaks from token

### Issue: Method Not Found

**Cause:** Wrong endpoint or unsupported method.

**Solutions:**
1. Verify using `/mcp` endpoint for JSON-RPC
2. Check method name matches MCP spec
3. Update to latest WP oOS version
4. Review [MCP Endpoint Documentation](reference/api/mcp-endpoint.md)

### Issue: CORS Errors

**Cause:** Cross-origin request blocked.

**Solutions:**
1. MCP endpoints include CORS headers by default
2. Check no CDN/WAF strips headers
3. Verify origin is allowed
4. Test with same-origin request first

---

## Endpoint Comparison

| Feature | `/mcp` (JSON-RPC) | `/sse` (SSE) | `/assistants` (REST) |
|---------|-------------------|--------------|----------------------|
| Protocol | JSON-RPC 2.0 | Server-Sent Events | REST/JSON |
| Direction | Bidirectional | Server→Client | Client→Server |
| Streaming | No | Yes | Optional |
| Content-Type | `application/json` | `text/event-stream` | `application/json` |
| Use Case | Tool execution | Real-time updates | Directory listing |
| Compatibility | Excellent | Good (requires SSE support) | Excellent |
| **Recommended for** | LM Studio, Cursor, Cline | Claude Desktop (optional) | All clients |

---

## Best Practices

### Security

1. **Never commit credentials** to version control
2. **Use HTTPS** for all connections
3. **Rotate credentials** regularly
4. **Separate credentials** per client/user
5. **Revoke unused credentials** immediately

### Performance

1. **Set appropriate timeouts** (30-60 seconds)
2. **Use JSON-RPC** for better reliability
3. **Enable caching** when possible
4. **Monitor connection** health

### Troubleshooting

1. **Start with JSON-RPC** endpoint
2. **Test with curl** before client
3. **Check WordPress logs** for errors
4. **Verify credentials** in admin
5. **Test connectivity** step by step

---

## Additional Resources

- [MCP Endpoint Documentation](reference/api/mcp-endpoint.md)
- [MCP Server Authentication](reference/api/mcp-server-authentication.md)
- [REST API Reference](reference/api/rest-api.md)
- [Remote Client Setup Guide](getting-started/quick-starts/remote-client-setup.md)
- [Model Context Protocol Specification](https://modelcontextprotocol.io/)

---

## Quick Reference

### Configuration Files Location

| Client | Config Path |
|--------|-------------|
| Claude Desktop (macOS) | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Claude Desktop (Windows) | `%APPDATA%\Claude\claude_desktop_config.json` |
| Claude Desktop (Linux) | `~/.config/Claude/claude_desktop_config.json` |
| Continue.dev | `.continue/config.json` |
| Cursor | Settings → Extensions → MCP |
| LM Studio | Settings → MCP Servers |

### Endpoint URLs

| Purpose | Endpoint |
|---------|----------|
| JSON-RPC (Recommended) | `https://your-site.com/wp-json/mcp-ai/v1/mcp` |
| SSE Streaming | `https://your-site.com/wp-json/mcp-ai/v1/sse` |
| Assistants Directory | `https://your-site.com/wp-json/mcp-ai/v1/assistants` |
| Tools | `https://your-site.com/wp-json/mcp-ai/v1/tools` |
| Chat | `https://your-site.com/wp-json/mcp-ai/v1/chat` |

### Key Methods (JSON-RPC)

| Method | Purpose |
|--------|---------|
| `initialize` | Initialize connection, get capabilities |
| `tools/list` | List available tools |
| `tools/call` | Execute a tool |
| `resources/list` | List resources (memory files) |
| `prompts/list` | List prompts (assistants) |

---

**Last Updated:** November 7, 2025
