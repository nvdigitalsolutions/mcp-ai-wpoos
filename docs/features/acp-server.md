# Agent Client Protocol (ACP) Server

**Introduced:** v1.1.19  
**Status:** GA  
**Transport:** HTTP + SSE (Server-Sent Events)  
**Protocol:** JSON-RPC 2.0  

The NV oOS ACP Server implements the [Agent Client Protocol](https://agentclientprotocol.org/) standard, enabling external AI clients and IDE integrations to natively drive NV oOS assistants over a standard JSON-RPC 2.0 / HTTP + SSE transport.

## Architecture

| Class | Role |
|-------|------|
| `WP_MCP_AI_ACP_Server` | Bootstrap, DI wiring, WordPress hook registration |
| `WP_MCP_AI_ACP_JSONRPC_Dispatcher` | JSON-RPC 2.0 method routing + error framing |
| `WP_MCP_AI_ACP_Session_Manager` | Session lifecycle: create, resume, expire (WordPress transients) |
| `WP_MCP_AI_ACP_Session_Bridge` | Bridges an ACP session to the existing NV oOS agentic loop |
| `WP_MCP_AI_ACP_Transport_HTTP` | HTTP request handling + SSE stream framing |

## Enabling the ACP Server

1. Navigate to **Orchestration → Settings** in the WordPress admin.
2. Toggle **Enable ACP Server** (`enable_acp_server`) on.
3. Optionally enable **Require approval for all tool calls** (`acp_require_approval`) for strict human-in-the-loop mode.

## Discovery

The `/.well-known/ai-peer` endpoint advertises ACP capabilities:

```json
{
  "protocols": ["acp"],
  "acp": {
    "version": "1.0",
    "transports": ["http+sse"],
    "endpoint": "https://yoursite.com/wp-json/mcp-ai/v1/acp",
    "auth_methods": ["bearer", "nonce"]
  }
}
```

## Connecting External Clients

### Zed Editor

Add to `~/.config/zed/settings.json`:

```json
{
  "assistant": {
    "type": "acp",
    "endpoint": "https://yoursite.com/wp-json/mcp-ai/v1/acp",
    "auth": { "type": "bearer", "token": "YOUR_ASSISTANT_CREDENTIAL" }
  }
}
```

### Claude Desktop / MCP stdio clients

Use `bin/mcp-bridge.js` to bridge the MCP stdio transport to the ACP HTTP endpoint:

```bash
node bin/mcp-bridge.js --url https://yoursite.com/wp-json/mcp-ai/v1 --token YOUR_TOKEN
```

## Authentication

The ACP server accepts the same authentication methods as the main REST API:

| Method | Header |
|--------|--------|
| WordPress Nonce | `X-WP-Nonce` |
| Assistant Bearer Token | `Authorization: Bearer cred_xxxxx.SECRET` |
| Auth0 Token (Pro) | `Authorization: Bearer <Auth0-token>` |

## REST Endpoint

```
POST /wp-json/mcp-ai/v1/acp
```

Standard JSON-RPC 2.0 request body. SSE streaming responses use `Content-Type: text/event-stream`.

## Testing

PHPUnit scaffolding is in `tests/acp/`. Run:

```bash
vendor/bin/phpunit tests/acp/
```

## Filters & Actions

| Hook | Type | Description |
|------|------|-------------|
| `wp_mcp_ai_acp_session_created` | action | Fires when a new ACP session is created. Args: `$session_id`, `$assistant_id`. |
| `wp_mcp_ai_acp_session_closed` | action | Fires when an ACP session ends. Args: `$session_id`. |
| `wp_mcp_ai_acp_require_approval` | filter | Override whether tool calls require HITL approval. Return `bool`. |

## Related

- [MCP Bridge (`bin/mcp-bridge.js`)](../../bin/README.md)
- [REST API Reference](../rest-api.md)
- [Orchestration Phases 1–7](../ORCHESTRATION_REFERENCE.md)
- [EXTERNAL_SERVICES.md](../EXTERNAL_SERVICES.md)
