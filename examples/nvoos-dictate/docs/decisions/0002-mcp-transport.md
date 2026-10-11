# ADR 0002 — MCP transport: streamable HTTP first, legacy SSE bridge conditional

**Status:** Proposed (pending spike)
**Date:** 2026-10-11

## Context

rmcp (official MCP Rust SDK, 3.5.x) implements streamable HTTP
(2025-03-26 / 2025-11-25 / 2026-07-28) and **deliberately** ships no legacy
2024-11-05 HTTP+SSE transport. The NV oOS Pro plugin's MCP clients
(JetEngine MCP client, MCP Apps) historically consumed SSE responses (see
proposal 066's client-cache-freshness work). Before building the Remote
Sites bridge, we must know which revision those clients negotiate.

## Decision

1. Implement the local MCP server with rmcp's stdio transport (desktop AI
   clients) and — pending the spike — its streamable-HTTP server transport
   for the plugin's Remote Sites surface.
2. Only if the spike shows the Pro clients require legacy HTTP+SSE do we
   implement `mcp/bridge_legacy_sse.rs` (oxml-mcp PR #57 pattern), enabled by
   a settings switch.
3. Until then, the Remote Sites recipe documents the pending state honestly.

## Spike steps

- Inspect `addons/pro/includes/class-wp-mcp-ai-jetengine-mcp-client.php` +
  `addons/pro/includes/mcp-apps/` for transport negotiation and SSE parsing.
- Record the finding here and flip this ADR to Accepted/Rejected.
