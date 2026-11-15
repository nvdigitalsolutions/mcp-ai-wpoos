// ---------------------------------------------------------------------------
// server.ts — nvoos-astro-mcp registry factory (Proposal 095 §3.3).
// One McpServer per connection (the SDK's per-request model for HTTP); the
// token state lives in the process-wide designService singleton.
// ---------------------------------------------------------------------------

import { McpServer } from '@modelcontextprotocol/server';
import { registerDesignTools, designService } from './tools/design';
import { registerCmsTools } from './tools/cms';
import { registerContentTools } from './tools/content';
import { registerShopTools } from './tools/shop';
import { registerOpsTools } from './tools/ops';
import { APP_VERSION } from '../src/lib/version';
import type { TokenService } from '../src/lib/tokens/service';

export const SERVER_NAME = 'nvoos-astro-mcp';
export const SERVER_VERSION = APP_VERSION;

export function buildServer(service: TokenService = designService): McpServer {
	const server = new McpServer({ name: SERVER_NAME, version: SERVER_VERSION });
	registerDesignTools(server, service);
	registerCmsTools(server);
	registerContentTools(server);
	registerShopTools(server);
	registerOpsTools(server, service);
	return server;
}
