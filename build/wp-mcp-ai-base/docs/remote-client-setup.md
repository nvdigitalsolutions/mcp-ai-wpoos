# Remote MCP Client Setup Guide

This guide explains how to connect to your WordPress WP oOS plugin from popular MCP clients including Claude Desktop, LM Studio, and ChatGPT connectors.

## Table of Contents

- [Overview](#overview)
- [Prerequisites](#prerequisites)
- [Claude Desktop Setup](#claude-desktop-setup)
- [LM Studio Setup](#lm-studio-setup)
- [ChatGPT Connector Setup](#chatgpt-connector-setup)
- [Testing Your Connection](#testing-your-connection)
- [Troubleshooting](#troubleshooting)

---

## Overview

WP oOS exposes a Model Context Protocol (MCP) server through its REST API at `/wp-json/mcp-ai/v1`. This allows external AI clients to:

- Access your WordPress assistants
- Execute registered tools
- Query content and data
- Generate images, audio, and other media

The plugin supports multiple authentication methods to accommodate different client types:

- **Assistant-issued credentials** – Bearer tokens generated per-assistant (recommended for Claude Desktop and LM Studio)
- **Auth0 bearer tokens** – OAuth tokens for enterprise deployments (required for ChatGPT connectors)
- **WordPress REST nonces** – For same-origin dashboard usage
- **Guest tokens** – For public chat interfaces with limited capabilities

---

## Prerequisites

Before connecting any MCP client, ensure:

1. **WP oOS is installed and activated** on your WordPress site
2. **At least one assistant is published** under **AI Assistants** in your WordPress admin
3. **Your WordPress site is accessible via HTTPS** (required for secure bearer token transmission)
4. **OpenAI API key is configured** in **Settings → WP oOS**
5. **Your server supports REST API access** (check `/wp-json/mcp-ai/v1/assistants` is reachable)

### Finding Your MCP Server URL

Your MCP server base URL follows this format:

```
https://your-site.com/wp-json/mcp-ai/v1
```

For example:
- `https://example.com/wp-json/mcp-ai/v1`
- `https://blog.mycompany.com/wp-json/mcp-ai/v1`

You'll need this URL when configuring your MCP client.

---

## Claude Desktop Setup

Claude Desktop is Anthropic's desktop application that supports the Model Context Protocol.

### Step 1: Generate Assistant Credentials

1. Log in to your WordPress admin dashboard
2. Navigate to **AI Assistants** → Select or create an assistant
3. Scroll to the **API Credentials** meta box (visible only to administrators)
4. Click **Generate Credential**
5. Copy the generated token (format: `cred_xxxxx.SECRET`)
   
   ⚠️ **Important:** The secret is shown only once. Save it securely!

### Step 2: Configure Claude Desktop

1. Open Claude Desktop
2. Go to **Settings** → **Developer** → **Edit Config**
3. Add your MCP server configuration:

```json
{
  "mcpServers": {
    "wordpress-site": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET"
      },
      "sse": true
    }
  }
}
```

Replace:
- `wordpress-site` with a meaningful name for your server
- `https://your-site.com/wp-json/mcp-ai/v1` with your actual MCP base URL
- `cred_xxxxx.SECRET` with your generated credential

### Step 3: Verify Connection

1. Restart Claude Desktop
2. In a new conversation, you should see your WordPress site listed under available MCP servers
3. Claude can now access your assistant's tools and knowledge base

### Configuration Options

**Custom timeout:**
```json
{
  "mcpServers": {
    "wordpress-site": {
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

**Multiple assistants:**

Generate separate credentials for each assistant:

```json
{
  "mcpServers": {
    "wordpress-editorial": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1",
      "headers": {
        "Authorization": "Bearer cred_aaa.SECRET1"
      },
      "sse": true
    },
    "wordpress-support": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1",
      "headers": {
        "Authorization": "Bearer cred_bbb.SECRET2"
      },
      "sse": true
    }
  }
}
```

---

## LM Studio Setup

LM Studio is a desktop application for running local language models with MCP support.

### Step 1: Generate Assistant Credentials

Follow the same steps as [Claude Desktop Step 1](#step-1-generate-assistant-credentials).

### Step 2: Configure LM Studio

1. Open LM Studio
2. Navigate to **MCP Servers** or **Settings** → **Extensions**
3. Click **Add MCP Server**
4. Enter the configuration:

**Server Name:** `WordPress Site` (or your preferred name)

**Base URL:** `https://your-site.com/wp-json/mcp-ai/v1`

**Authentication Type:** `Bearer Token`

**Token:** `cred_xxxxx.SECRET`

**Enable SSE:** ✓ (checked)

### Step 3: Test Connection

1. Click **Test Connection** to verify connectivity
2. LM Studio should report success and display available assistants
3. Start a conversation and access your WordPress tools through the MCP interface

### Advanced Configuration

If using LM Studio's JSON config file (usually `~/.lmstudio/mcp.json` or similar):

```json
{
  "servers": [
    {
      "id": "wordpress-mcp",
      "name": "WordPress Site",
      "baseUrl": "https://your-site.com/wp-json/mcp-ai/v1",
      "auth": {
        "type": "bearer",
        "token": "cred_xxxxx.SECRET"
      },
      "sse": {
        "enabled": true,
        "endpoint": "/sse"
      },
      "timeout": 30000
    }
  ]
}
```

---

## ChatGPT Connector Setup

⚠️ **Note:** OpenAI's ChatGPT connector currently requires Auth0 authentication. Assistant-issued credentials are not supported by ChatGPT at this time.

### Prerequisites

- An Auth0 account and tenant
- Auth0 configured in **Settings → WP oOS** on your WordPress site
- Machine-to-Machine application created in Auth0 for the MCP API

### Step 1: Configure Auth0 in WordPress

1. Navigate to **Settings → WP oOS**
2. Scroll to **Auth0 Configuration**
3. Enter:
   - **Auth0 Domain:** `your-tenant.auth0.com`
   - **Auth0 Audience:** Your API identifier (e.g., `https://your-site.com/mcp-api`)
   - **Auth0 Required Scope:** (optional, e.g., `read:assistants write:chat`)

4. Save changes

### Step 2: Generate Auth0 Access Token

Using Auth0's dashboard or API:

1. Go to **Applications → Machine to Machine**
2. Select your MCP application
3. Request an access token with the configured audience
4. The token will have format: `eyJhbGci...` (JWT)

Or use the Auth0 Management API:

```bash
curl -X POST https://your-tenant.auth0.com/oauth/token \
  -H 'Content-Type: application/json' \
  -d '{
    "client_id": "YOUR_CLIENT_ID",
    "client_secret": "YOUR_CLIENT_SECRET",
    "audience": "https://your-site.com/mcp-api",
    "grant_type": "client_credentials"
  }'
```

### Step 3: Configure ChatGPT Connector

1. Log in to OpenAI's ChatGPT interface
2. Navigate to **Custom GPTs** or **Connectors** (if available in your account)
3. Create a new connector with:
   - **Name:** WordPress MCP
   - **Base URL:** `https://your-site.com/wp-json/mcp-ai/v1`
   - **Authentication:** OAuth 2.0 / Bearer Token
   - **Token:** `eyJhbGci...` (your Auth0 access token)

4. Save and test the connection

### Limitations

- ChatGPT connectors are currently in beta and may have limited availability
- Requires Auth0 infrastructure (not suitable for simple deployments)
- For simpler setups, use Claude Desktop or LM Studio instead

---

## Testing Your Connection

### Using WP-CLI (Recommended)

If you have WP-CLI installed on your server:

```bash
# Test with assistant credential
wp mcp-ai remote https://your-site.com/wp-json/mcp-ai/v1 \
  --token=cred_xxxxx.SECRET

# Test with Auth0 token
wp mcp-ai remote https://your-site.com/wp-json/mcp-ai/v1 \
  --token=eyJhbGci...

# Test with specific assistant ID
wp mcp-ai remote https://your-site.com/wp-json/mcp-ai/v1 \
  --token=cred_xxxxx.SECRET \
  --assistant-id=123
```

Expected output:
```
+-----------------+---------+------+--------------------------------------------------+
| step            | status  | http | message                                          |
+-----------------+---------+------+--------------------------------------------------+
| GET /assistants | success | 200  | Received 3 assistants. Token scope: local_token. |
| POST /chat      | success | 200  | Chat endpoint reachable. Status: success.        |
+-----------------+---------+------+--------------------------------------------------+
Token scope: local_token
Success: Remote MCP API reachable (3 assistants).
```

### Using cURL

Test the assistants endpoint:

```bash
curl -H "Authorization: Bearer cred_xxxxx.SECRET" \
  https://your-site.com/wp-json/mcp-ai/v1/assistants
```

Expected response:
```json
{
  "assistants": [
    {
      "id": 123,
      "title": "Editorial Assistant",
      "status": "publish",
      "provider": "openai",
      "model": "gpt-4o-mini",
      "tool_count": 5
    }
  ],
  "token_scope": {
    "type": "local_token",
    "assistant_id": 123
  }
}
```

### Using the Probe Tool

From within a WordPress assistant conversation, you can use the `probe_remote_mcp` tool:

1. Open a chat with any assistant
2. Ask: "Probe the remote MCP connection to https://your-site.com/wp-json/mcp-ai/v1 with this token: cred_xxxxx.SECRET"
3. The assistant will execute the probe and report results

---

## Troubleshooting

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

Example error:
```json
{
  "code": "wp_mcp_ai_invalid_token",
  "message": "The provided credential is invalid or has been revoked.",
  "data": {
    "status": 401
  }
}
```

### Assistant Scope Mismatch / 403 Forbidden

**Problem:** Trying to access different assistant than credential allows

**Solutions:**
1. Each assistant credential only works for that specific assistant
2. Generate separate credentials for each assistant you need to access
3. Don't override `assistant_id` in requests when using scoped credentials

Example error:
```json
{
  "code": "wp_mcp_ai_assistant_scope_mismatch",
  "message": "This credential is scoped to assistant 123. Cannot access assistant 456.",
  "data": {
    "status": 403
  }
}
```

### SSE/Streaming Not Working

**Problem:** Client expects Server-Sent Events but gets regular JSON

**Solutions:**
1. Ensure your client sends `Accept: text/event-stream` header
2. Try the dedicated `/sse` endpoint instead of `/assistants`
3. Check if WAF or CDN is blocking streaming responses
4. Add exception for `text/event-stream` content type

### CloudFlare / WAF Challenges

**Problem:** Edge network blocking MCP client requests

**Solutions:**
1. Configure WAF exception for `/wp-json/mcp-ai/v1/*` paths
2. Allow `Accept: text/event-stream` headers
3. Whitelist common MCP client user agents:
   - `Claude-Desktop/*`
   - `LMStudio/*`
   - `MCP-Client/*`

### Certificate / SSL Errors

**Problem:** SSL verification fails

**Solutions:**
1. Ensure your WordPress site has valid SSL certificate
2. Check certificate is not self-signed or expired
3. For testing only, disable SSL verification:
   - WP-CLI: `--verify-ssl=false`
   - Most clients have SSL verification options

### Rate Limiting

**Problem:** Too many requests returning 429 errors

**Solutions:**
1. Check **Settings → WP oOS → Rate Limiting** configuration
2. Increase limits for trusted clients
3. Use rate limit filters to exempt specific tokens
4. Consider caching frequently-accessed data

### Tools Not Available

**Problem:** Expected tools don't appear in client

**Solutions:**
1. Verify tools are enabled for the assistant in WordPress admin
2. Check tool dependencies are installed (WooCommerce, JetEngine, etc.)
3. Ensure user has required capabilities for tools
4. Review tool capability requirements in documentation

### Memory/Knowledge Files Not Loading

**Problem:** Assistant doesn't have access to knowledge base

**Solutions:**
1. Verify memory files are attached in **Base Knowledge** meta box
2. Check file sizes don't exceed limits (5MB default)
3. Ensure MIME types are allowed in **Settings → WP oOS → Attachments**
4. Review file permissions and accessibility

---

## Next Steps

Once connected successfully:

1. **Explore available tools** – Each assistant exposes different tool sets
2. **Review tool documentation** – See [`docs/tool-reference.md`](tool-reference.md)
3. **Test common workflows** – Try content search, post creation, etc.
4. **Monitor usage** – Track consumption in **Settings → WP oOS**
5. **Secure your deployment** – Review [deployment troubleshooting](deployment-troubleshooting.md)

## Additional Resources

- [MCP Server Authentication Guide](mcp-server-authentication.md)
- [REST API Reference](rest-api.md)
- [Tool Reference](tool-reference.md)
- [Deployment Troubleshooting](deployment-troubleshooting.md)
- [Assistant Tool Shortcuts](assistant-tool-shortcuts.md)
