// ---------------------------------------------------------------------------
// context.ts — per-request ambient context for nvoos-astro-mcp tool handlers
// (Proposal 095 security hardening; closes the "tool handlers read the
// request cookie/env" gap). AsyncLocalStorage carries the inbound request
// cookie and the serving env across async boundaries so tool code can read
// them without reaching for globals. The BFF wraps every /mcp request in
// mcpRequestContext.run(); the stdio entry wraps the connect call.
// ---------------------------------------------------------------------------

import { AsyncLocalStorage } from 'node:async_hooks';

export interface McpRequestContext {
	cookie?: string;
	/** The inbound request origin (scheme + Host), set by the BFF; absent in stdio mode. */
	origin?: string;
	env: Record<string, string | undefined>;
}

export const mcpRequestContext = new AsyncLocalStorage<McpRequestContext>();

export function getMcpRequestContext(): McpRequestContext | undefined {
	return mcpRequestContext.getStore();
}
