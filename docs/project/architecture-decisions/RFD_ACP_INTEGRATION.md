# RFD: Agent Client Protocol (ACP) Integration

## 1. Introduction

This Request for Dialog (RFD) outlines the architecture and implementation plan for integrating the **Agent Client Protocol (ACP)** into the Open Operator System (NV oOS).

ACP is a standardized protocol (JSON-RPC 2.0 based) for communication between code editors/IDEs (like Zed, JetBrains, Neovim) and AI agents. By implementing ACP, NV oOS will allow external IDEs to connect to and drive WordPress-hosted assistants natively.

## 2. Background and Motivation

Currently, NV oOS supports:
* **MCP (Model Context Protocol)**: For connecting to external tools (Plugin acts as Client).
* **A2A (Agent-to-Agent)**: For peer-to-peer agent task delegation.
* **Federation Discovery**: For publishing site capabilities (`.well-known/ai-peer`).
* **REST Chat API**: For the local UI.

Adding ACP completes the protocol suite by adding a standardized "Client-to-Agent" surface. 

**Benefits:**
* **IDE Integration**: Users can use Zed or JetBrains to interact with NV oOS assistants.
* **Registry Listing**: NV oOS can be listed on the official ACP Registry.
* **Standardized Sessions**: ACP provides standardized session management and tool permission gating.

## 3. Architecture

### 3.1. Layering

ACP will be implemented as a new protocol surface alongside the existing A2A and Chat REST controllers.

```
┌─────────────────────────────────────────────────────────────────┐
│  External ACP Clients (Zed, JetBrains, Neovim, custom UIs)      │
└─────────────────────────────────────────────────────────────────┘
                              │
                  ┌───────────┴────────────┐
                  │                        │
            stdio (CLI shim)        Streamable HTTP / SSE
                  │                        │
                  ▼                        ▼
┌─────────────────────────────────────────────────────────────────┐
│  WP_MCP_AI_ACP_Server (includes/acp/)                           │
│   • JSON-RPC 2.0 Dispatcher                                     │
│   • Capability negotiation (initialize)                         │
│   • Session Lifecycle (session/new, /load, /list, /cancel)      │
│   • Prompt execution (session/prompt)                           │
│   • Updates (session/update)                                    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Existing NV oOS Core                                           │
│  (Chat Pipeline, Tool Registry, Approval Controller)            │
└─────────────────────────────────────────────────────────────────┘
```

### 3.2. Federation Discovery

Federation will not *contain* ACP, but it will *advertise* it. The `.well-known/ai-peer` endpoint will be extended to include an `acp` capability block.

## 4. Implementation Plan

### Phase 1: Core ACP Server over HTTP/SSE (Base Version)
Implement the core JSON-RPC 2.0 server over HTTP POST and SSE (Server-Sent Events).
* `POST /wp-json/mcp-ai/v1/acp`
* `GET /wp-json/mcp-ai/v1/acp/sse?sessionId=...`
* Scaffold classes in `includes/acp/`.
* Bridge ACP `session/prompt` to the existing chat controller pipeline.

### Phase 2: CLI stdio Shim
Create a lightweight Node.js/Python CLI wrapper (`@nvdigital/nv-oos-acp`) that translates stdio JSON-RPC to HTTP/SSE to support clients that only run subprocesses.

### Phase 3: Registry Submission
Submit the agent configuration to `agentclientprotocol/registry`.

### Phase 4: Federation Discovery
Add the `acp` block to the existing `class-wp-mcp-ai-federation-wellknown.php`.

### Phase 5: UI & Admin
Add settings, credentials management, and diagnostic tools for ACP.

### Phase 6: Testing & Compliance
Full test coverage of the ACP spec, including cancellation semantics and tool permission flows.

## 5. Mapping ACP to NV oOS

| ACP Concept | NV oOS Concept |
|-------------|----------------|
| `initialize` `authMethods` | Nonce, Bearer Credentials, Auth0, Guest Tokens |
| `session/prompt` | Chat pipeline (`WP_MCP_AI_REST_Chat_Controller`) |
| `session/request_permission`| Approval Controller |
| `tool_call` | Tool Registry (`WP_MCP_AI_Tool_Registry`) |
| `session/list` | Assistant memory/transcripts |

## 6. Security Considerations
* **Authentication**: ACP requests must be fully authenticated using existing NV oOS auth methods.
* **Permissions**: Tool calls requiring capabilities must trigger `session/request_permission` before execution.
* **Isolation**: Sessions must be isolated by user/tenant.

## 7. Open Questions
* Should ACP WebSocket transport be implemented in the Base or Pro version? (Recommendation: HTTP in Base, WS in Pro).
* How will we handle file system capabilities (`fs.readTextFile`, `fs.writeTextFile`) securely? (Recommendation: Delegate to existing tools or restrict to specific virtual paths).
