// ---------------------------------------------------------------------------
// server.ts — nvoos-astro-mcp registry factory (Proposal 095 §3.3).
// One McpServer per connection (the SDK's per-request model for HTTP); the
// token state lives in the process-wide designService singleton.
// ---------------------------------------------------------------------------

import { McpServer } from '@modelcontextprotocol/server';
import { registerDesignTools, designService } from './tools/design';
import type { TokenService } from '../src/lib/tokens/service';

export const SERVER_NAME = 'nvoos-astro-mcp';
export const SERVER_VERSION = '0.1.0';

export function buildServer(service: TokenService = designService): McpServer {
	const server = new McpServer({ name: SERVER_NAME, version: SERVER_VERSION });
	registerDesignTools(server, service);
	return server;
}
