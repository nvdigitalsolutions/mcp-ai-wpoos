# MCP tools/call Synchronous Dispatch — Fix Details

## Problem Description

Direct MCP clients (Hermes, Zed bridges, Claude Desktop) consume the
JSON-RPC response inline and have no channel to poll background jobs. Tools
flagged async-capable were queued, and `mcp_wait_for_async_tool()` polled for
the result — but on hosts with an unreachable WP-Cron loopback, the
shutdown-kick fallback only completes jobs *after* the response is flushed.
The ~45s poll budget was therefore always exhausted, and `tools/call`
returned a timeout error for tools that would have completed fine inline.

## Root Cause

`WP_MCP_AI_REST::execute_tool_call_internal()` forced
`$context['agentic_loop'] = false` for direct `tools/call` requests ("the
async orchestrator shouldn't force-sync, Priority 5"). With no reliable
background runner, async dispatch is structurally unsafe for inline clients.

## Solution Implemented

Files: `includes/class-wp-mcp-ai-rest-mcp-methods.php`,
`includes/class-wp-mcp-ai-rest.php`

1. `mcp_tools_call()` sets the `agentic_loop` request param to `true` —
   non-background tools complete synchronously in the JSON-RPC response,
   mirroring the chat client.
2. `execute_tool_call_internal()` now honours the `agentic_loop` request
   param instead of forcing `false`.
3. Background-only tools (Priority 1) still run async regardless.

## Test Coverage

Covered by the existing MCP endpoint and async-tool suites
(`tests/test-mcp-endpoint.php`, `tests/test-mcp-client-compatibility.php`,
`tests/test-async-tool-execution-flow.php`). Dispatch parity with the chat
client path is the regression guard.

## Related

- [PR #5884](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5884)
- [`.context/rest-api.md`](../../../.context/rest-api.md) — rule 5 (synchronous `tools/call`)
- [`docs/developer/implementation-plan-mcp-agent-compat.md`](../../developer/implementation-plan-mcp-agent-compat.md) — WS-6 update note
