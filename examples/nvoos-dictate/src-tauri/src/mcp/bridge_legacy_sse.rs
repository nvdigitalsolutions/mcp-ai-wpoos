//! Legacy HTTP+SSE (2024-11-05) bridge — PENDING THE TRANSPORT SPIKE.
//!
//! rmcp deliberately ships no legacy HTTP+SSE transport (it targets
//! 2025-11-25 / 2026-07-28). The NV oOS Pro plugin's MCP clients
//! (JetEngine MCP client, MCP Apps) historically consumed SSE responses;
//! whether Remote Sites negotiates modern streamable HTTP must be verified
//! against `addons/pro/includes/class-wp-mcp-ai-jetengine-mcp-client.php`
//! and `addons/pro/includes/mcp-apps/` (see decision record 0002).
//!
//! If a bridge is required, follow the oxml-mcp pattern (PR #57): a small
//! `GET /sse` endpoint emitting `endpoint` events + a `POST /messages`
//! JSON-RPC handler, both delegating to the same rmcp `ServerHandler`.
//! That bridge belongs here and is enabled by a settings switch.

// Intentionally empty until the spike lands — the modern path (streamable
// HTTP via rmcp + Remote Sites) is the primary target.
