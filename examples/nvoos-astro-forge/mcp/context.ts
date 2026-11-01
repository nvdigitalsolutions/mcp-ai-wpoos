// ---------------------------------------------------------------------------
// context.ts — per-request ambient context for nvoos-astro-forge MCP tool
// handlers (Proposal 096 Phase 4; the 095 hardening pattern). Tool code
// reads the serving env through this store, never through process globals.
// The BFF wraps every /mcp request; the stdio entry wraps the connection.
// ---------------------------------------------------------------------------

import { AsyncLocalStorage } from 'node:async_hooks';

export interface McpRequestContext {
	/** The inbound request origin (scheme + Host); absent in stdio mode. */
	origin?: string;
	env: Record<string, string | undefined>;
}

export const mcpRequestContext = new AsyncLocalStorage<McpRequestContext>();

export function getMcpRequestContext(): McpRequestContext | undefined {
	return mcpRequestContext.getStore();
}
