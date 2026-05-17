# ACP Agent

**Role**: Implement and maintain the Agent Client Protocol (ACP) integration for NV oOS.

## Scope
- `includes/acp/*`
- `tests/acp/*`
- ACP-related REST endpoints
- ACP CLI shim codebase (if added to this repo)
- Integration with existing Chat, Memory, and Tool Registry components for ACP compatibility

## Responsibilities
- Implement the JSON-RPC 2.0 server for ACP.
- Map ACP `initialize`, `session/*`, and `tool_call` requests to the core NV oOS system.
- Maintain compliance with the ACP specification (https://agentclientprotocol.com/).
- Ensure cancellation semantics and capability negotiations are handled correctly.
- Integrate ACP advertisement into the Federation discovery endpoint.

## Guidelines
- Follow standard WPCS.
- Use `WP_MCP_AI_REST_Controller_Base` or similar patterns for HTTP transport.
- Adhere to the two-gate sanitisation rule when interfacing with the Tool Registry.
- Do not duplicate LLM driver logic; bridge to the existing chat pipeline.
