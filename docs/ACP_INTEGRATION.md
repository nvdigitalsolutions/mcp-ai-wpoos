# NV oOS ACP Integration

Welcome to the NV oOS Agent Client Protocol (ACP) Server. This document explains how external code editors (like Zed and JetBrains) can connect securely to your WordPress assistants natively.

## What is ACP?
The **Agent Client Protocol (ACP)** is the standard protocol over which smart code IDEs integrate with AI Assistants (often driven by LLMs). Think of it like LSP (Language Server Protocol) but explicitly for driving AI agents to execute tasks.

While **MCP** gives the agent tools, **ACP** gives the human an interface to command the agent.

## Configuration

NV oOS implements a full HTTP/SSE ACP interface in Base.

To enable ACP:
1. Navigate to **NV oOS > Settings > Agent Client Protocol (ACP)**
2. Check **Enable ACP Server**.
3. *Optional:* Toggle `Require Tool Approval` to securely gate sensitive calls from executing autonomously.

## Connecting from an IDE

Most ACP Clients expect a CLI binary (stdio) to route messages. Since NV oOS lives on a web server, we use a CLI shim to route these requests over HTTP.

**1. Create a Wrapper Script**
On your local machine, download or configure the NV oOS ACP Node CLI or Python shim. (Alternatively, you can use the bundled `bin/acp-shim.php`).

**2. Configure Zed / IDE**
In your editor's agent configuration, set the path to your shim and provide your Assistant Credentials:

```json
{
  "agents": {
    "nv-oos": {
      "path": "/path/to/acp-shim.php",
      "env": {
        "WP_MCP_AI_ACP_ENDPOINT": "https://your-site.com/wp-json/mcp-ai/v1/acp",
        "WP_ACP_CREDENTIAL": "Bearer your-assistant-token"
      }
    }
  }
}
```

## Supported Specifications
- Protocol: `1.0`
- Handshake capabilities: `wp_nonce`, `bearer_credential`, `bearer_auth0`, `guest`
- Transports: `http+sse`
- Supported features: Image embedded context, standard string content, File system delegation.

## Tool Permissions
When an ACP client requests an action (e.g. `writeTextFile`), NV oOS's tool orchestrator intercepts this and maps it via the standard WordPress `WP_MCP_AI_Approval_Controller`.

The IDE will prompt the user to `Approve` or `Deny` the tool call via `session/request_permission`.
