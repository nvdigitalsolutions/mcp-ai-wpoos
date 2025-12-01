# MCP Client Configuration Examples

This directory contains example configuration files for connecting various MCP clients to your WordPress WP oOS server.

## Important: Two Connection Methods

WordPress WP oOS supports **TWO ways** to connect MCP clients:

### Method 1: JSON-RPC 2.0 (Recommended - No SSE Required)
**Endpoint:** `/wp-json/mcp-ai/v1/mcp`
- Uses standard JSON-RPC 2.0 protocol
- **Does NOT require SSE streaming**
- Works with all MCP clients
- Best for: LM Studio, Claude Desktop, Cursor, Continue.dev

### Method 2: SSE Streaming (Optional)
**Endpoint:** `/wp-json/mcp-ai/v1/sse` or `/wp-json/mcp-ai/v1/assistants`
- Uses Server-Sent Events for real-time updates
- Requires `text/event-stream` support
- Best for: Real-time streaming scenarios

**If you're getting SSE errors with LM Studio, use Method 1 (JSON-RPC) instead!**

## Configuration Files

### Claude Desktop

**`claude-desktop-config.json`** - Single server configuration
- Basic example showing how to connect Claude Desktop to one WordPress MCP server
- Uses assistant-issued bearer credential
- Enables Server-Sent Events (SSE) for streaming

**`claude-desktop-multi-config.json`** - Multiple servers configuration
- Example of connecting Claude Desktop to multiple WordPress MCP servers
- Each server has its own assistant-scoped credential
- Useful for managing different sites or environments (e.g., editorial, support, analytics)

### LM Studio

**`lmstudio-config.json`** - LM Studio with SSE (original)
- Configuration for LM Studio's MCP server integration with SSE
- Includes authentication, SSE, and timeout settings
- **May cause SSE content-type errors**

**`lmstudio-mcp-without-sse.json`** - ⭐ **RECOMMENDED for LM Studio**
- Uses JSON-RPC 2.0 protocol via `/mcp` endpoint
- **No SSE streaming required** - Fixes the "Invalid content type" error
- Simpler configuration, better compatibility

**`lmstudio-assistants-endpoint.json`** - Alternative approach
- Uses `/assistants` endpoint for directory discovery
- SSE is optional
- Good for browsing available assistants first

### Cursor IDE

**`cursor-config.json`** - Cursor MCP integration
- Configuration for Cursor IDE's MCP support
- Uses HTTP transport with MCP server adapter

### Continue.dev

**`continue-config.json`** - Continue.dev extension
- Configuration for Continue.dev VS Code extension
- SSE transport for real-time updates

### Cline

**`cline-config.json`** - Cline AI assistant
- Configuration for Cline MCP integration
- Simple bearer token authentication

### OpenAI/ChatGPT

**`openai-gpt-config.json`** - OpenAI custom actions
- Configuration for ChatGPT custom actions/plugins
- Bearer token authentication for MCP access

## Usage Instructions

### For Claude Desktop

1. Locate your Claude Desktop config file:
   - **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
   - **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`
   - **Linux**: `~/.config/Claude/claude_desktop_config.json`

2. Copy the contents from one of the Claude Desktop examples
3. Replace placeholder values:
   - `https://your-site.com/wp-json/mcp-ai/v1` with your actual MCP server URL
   - `cred_xxxxx.SECRET` with your generated assistant credential
4. Save the file and restart Claude Desktop

### For LM Studio

**If you're getting "Invalid content type" SSE errors, use the JSON-RPC configuration:**

1. Use `lmstudio-mcp-without-sse.json` (recommended)
2. In LM Studio, navigate to **Settings** → **MCP Servers**
3. Add a new server with:
   - **URL**: `https://your-site.com/wp-json/mcp-ai/v1/mcp`
   - **Auth Type**: Bearer Token
   - **Token**: `cred_xxxxx.SECRET`
   - **No SSE configuration needed**
4. Test the connection

**Alternative configurations:**
- `lmstudio-config.json` - Original with SSE (may have compatibility issues)
- `lmstudio-assistants-endpoint.json` - Uses assistants directory

### For Cursor IDE

1. Open Cursor settings (Cmd/Ctrl + ,)
2. Go to **Extensions** → **MCP**
3. Add configuration from `cursor-config.json`
4. Replace `your-site.com` and `cred_xxxxx.SECRET`
5. Restart Cursor

### For Continue.dev

1. Install Continue.dev extension in VS Code
2. Open Continue settings (`.continue/config.json`)
3. Add the MCP server configuration from `continue-config.json`
4. Replace placeholder values
5. Reload VS Code window

### For Cline

1. Open Cline extension settings
2. Navigate to **MCP Servers**
3. Add configuration from `cline-config.json`
4. Update URL and token
5. Save and test connection

### For OpenAI/ChatGPT

1. Go to OpenAI Agent Builder platform (https://platform.openai.com)
2. Navigate to **Agent Builder** or **Custom Actions**
3. Add new MCP connector using configuration from `openai-gpt-config.json`
4. Configure:
   - **URL**: `https://your-site.com/wp-json/mcp-ai/v1/mcp`
   - **Auth Type**: Bearer Token
   - **Token**: Your generated assistant credential (e.g., `cred_xxxxx.SECRET`)
5. Save and test the connection

**Important**: Make sure your WordPress site:
- Has HTTPS enabled (required for OpenAI)
- REST API is accessible (test `/wp-json` endpoint)
- No security plugins blocking REST API access
- CORS is working (fixed in latest version)

## Generating Credentials

Before using these configurations, you need to generate assistant credentials:

1. Log in to your WordPress admin
2. Go to **AI Assistants** and select an assistant
3. Scroll to **API Credentials** meta box (admin only)
4. Click **Generate Credential**
5. Copy the token (format: `cred_xxxxx.SECRET`) - shown only once!

## Complete Documentation

For detailed setup instructions, troubleshooting, and advanced configurations:

- [Remote Client Setup Guide](../../docs/remote-client-setup.md)
- [MCP Server Authentication](../../docs/mcp-server-authentication.md)
- [REST API Reference](../../docs/rest-api.md)

## Testing Your Configuration

After configuring your client, verify the connection:

### Method 1: Test JSON-RPC Endpoint (Recommended)

```bash
# Test the /mcp endpoint (no SSE required)
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

Expected response:
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2024-11-05",
    "capabilities": {...},
    "serverInfo": {
      "name": "WP oOS",
      "version": "1.0.0"
    }
  }
}
```

### Method 2: Test Assistants Endpoint

```bash
# Using the test script
./bin/test-remote-connection.sh \
  -u https://your-site.com/wp-json/mcp-ai/v1 \
  -t cred_xxxxx.SECRET

# Using WP-CLI
wp mcp-ai remote https://your-site.com/wp-json/mcp-ai/v1 \
  --token=cred_xxxxx.SECRET

# Using curl
curl -H "Authorization: Bearer cred_xxxxx.SECRET" \
  https://your-site.com/wp-json/mcp-ai/v1/assistants
```

## Troubleshooting

### OpenAI Agent Builder: "Unable to load tools for this server"

This error was caused by critical bugs that have been fixed in the latest version:

**Fixed Issues**:
1. ✅ **Fatal PHP Error**: MCP code was calling non-existent `get_json_schema()` method
2. ✅ **CORS Blocking**: OpenAI servers were blocked by missing CORS headers
3. ✅ **Missing Fields**: Added required `instructions` and proper initialization
4. ✅ **Tool Polling**: Tools are now automatically included in the `initialize` response for immediate discovery

**Solution**: Update to the latest version of WP oOS plugin

**What Changed**:
- The `initialize` method now includes tool definitions by default
- This allows OpenAI Agent Builder and similar clients to immediately discover available tools
- The `tools/list` method remains available for clients that prefer explicit calls
- Developers can disable this behavior using the `wp_mcp_ai_initialize_include_tools` filter (see `assets/examples/filter-initialize-tools.php`)

**If still having issues after updating**:

1. **Verify WordPress REST API is accessible**:
   ```bash
   curl https://your-site.com/wp-json
   ```
   Should return JSON with API routes.

2. **Test MCP endpoint directly**:
   ```bash
   curl -X POST "https://your-site.com/wp-json/mcp-ai/v1/mcp" \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer cred_xxxxx.SECRET" \
     -d '{
       "jsonrpc": "2.0",
       "id": 1,
       "method": "initialize",
       "params": {
         "assistant_id": YOUR_ASSISTANT_ID
       }
     }'
   ```
   Should return initialization response with tools array included.

3. **Verify tools are returned in initialize response**:
   The response should include a `tools` array with tool definitions:
   ```json
   {
     "jsonrpc": "2.0",
     "id": 1,
     "result": {
       "protocolVersion": "2024-11-05",
       "capabilities": {...},
       "serverInfo": {...},
       "instructions": "...",
       "tools": [
         {
           "name": "search_content",
           "description": "Search WordPress content",
           "inputSchema": {...}
         }
       ]
     }
   }
   ```

4. **Check for security plugins blocking REST API**:
   - Wordfence: Whitelist `/wp-json/mcp-ai/` in firewall
   - Sucuri: Allow REST API access
   - iThemes Security: Disable REST API restrictions for `/mcp-ai/`

5. **Verify CORS headers** (use browser dev tools):
   - Should see `Access-Control-Allow-Origin: *`
   - Should see `Access-Control-Allow-Methods: GET, POST, OPTIONS`

6. **Check credential is valid**:
   - Go to WordPress Admin → AI Assistants
   - Verify credential hasn't been revoked
   - Generate a new one if needed

7. **Ensure HTTPS is enabled**:
   - OpenAI requires HTTPS connections
   - Test with `https://` not `http://`

8. **Ensure assistant has tools configured**:
   - Edit your assistant in WordPress admin
   - Verify tools are enabled in the assistant configuration
   - At least one tool must be selected for the assistant

### LM Studio: "Invalid content type, expected text/event-stream"

**Problem:** LM Studio is trying to use SSE but the server response has wrong content-type.

**Solution:** 
1. Use `lmstudio-mcp-without-sse.json` configuration instead
2. Point to `/mcp` endpoint: `https://your-site.com/wp-json/mcp-ai/v1/mcp`
3. Remove SSE configuration entirely
4. This uses JSON-RPC 2.0 protocol which doesn't require SSE

### Connection Refused / Timeout

**Problem:** Client cannot reach the MCP server

**Solutions:**
1. Verify your WordPress site is accessible via HTTPS
2. Check that REST API is enabled (visit `/wp-json` in browser)
3. Ensure no firewall or security plugin is blocking `/wp-json/mcp-ai/v1`
4. Try accessing the URL directly in a browser first

### Invalid Token / 401 Unauthorized

**Problem:** Authentication fails with credential error

**Solutions:**
1. Verify you copied the complete token including `cred_` prefix
2. Check the credential hasn't been revoked in WordPress admin
3. Generate a new credential if unsure
4. Ensure no extra spaces or line breaks in the token

### Method Not Found

**Problem:** MCP client sends a request but method is not recognized

**Solutions:**
1. Verify you're using the correct endpoint (`/mcp` for JSON-RPC)
2. Check the method name matches MCP specification
3. Update to latest version of WP oOS plugin
4. Review [MCP Endpoint Documentation](../../docs/mcp-endpoint.md)

## Security Notes

- **Never commit real credentials** to version control
- Each credential is scoped to a specific assistant
- Credentials can be revoked in WordPress admin at any time
- Use different credentials for different clients/users for better audit trails

## Example Values Reference

| Placeholder | Replace With | Example |
|------------|--------------|---------|
| `https://your-site.com` | Your WordPress site URL | `https://blog.example.com` |
| `cred_xxxxx.SECRET` | Generated assistant credential | `cred_abc123.a1b2c3d4e5f6...` |
| `wordpress-site` | Meaningful server name | `production-editorial`, `staging-support` |
| `30000` | Timeout in milliseconds | `30000` (30 seconds), `60000` (1 minute) |
