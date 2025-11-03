# MCP Client Configuration Examples

This directory contains example configuration files for connecting various MCP clients to your WordPress MCP AI server.

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

**`lmstudio-config.json`** - LM Studio server configuration
- Configuration for LM Studio's MCP server integration
- Includes authentication, SSE, and timeout settings
- Compatible with LM Studio's JSON config format

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

1. In LM Studio, navigate to **Settings** → **MCP Servers** or **Extensions**
2. Click **Add MCP Server** or **Import Configuration**
3. Either:
   - Fill in the form fields with values from the example
   - Import the JSON directly if your version supports it
4. Test the connection

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

```bash
# Using the test script
./bin/test-remote-connection.sh \
  -u https://your-site.com/wp-json/mcp-ai/v1 \
  -t cred_xxxxx.SECRET

# Using WP-CLI
wp mcp-ai remote https://your-site.com/wp-json/mcp-ai/v1 \
  --token=cred_xxxxx.SECRET
```

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
