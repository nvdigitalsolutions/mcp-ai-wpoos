// ---------------------------------------------------------------------------
// bff.ts — Streamable HTTP mount for the storefront BFF (Proposal 095 §3.3).
// Bundled by scripts/build-mcp.mjs into dist/mcp-entry.mjs; scripts/serve.mjs
// routes /mcp here. Host/Origin validation (MCP spec DNS-rebinding guards)
// + the write-tool authorization gate run before the SDK handler.
//
// TODO(095-Phase-4): durable design state — the /manage Design tab plus the
// NDS round-trip through the commerce contract — lands in a follow-up.
// ---------------------------------------------------------------------------

import { createMcpHandler } from '@modelcontextprotocol/server';
import {
	toNodeHandler,
	hostHeaderValidation,
	originValidation,
} from '@modelcontextprotocol/node';
import type { IncomingMessage, ServerResponse } from 'node:http';
import { buildServer } from './server';
import { authorizeRequest } from './auth';

interface McpEnv {
	NVOOS_MCP_TOKEN?: string;
	NVOOS_MCP_HOSTS?: string;
	NVOOS_MCP_ORIGINS?: string;
	NVOOS_SSR_API_ORIGIN?: string;
	NVOOS_API_BASE?: string;
}

function allowedHosts(env: McpEnv): string[] {
	return (env.NVOOS_MCP_HOSTS ?? 'localhost,127.0.0.1,[::1]')
		.split(',')
		.map((entry) => entry.trim())
		.filter(Boolean);
}

function allowedOrigins(env: McpEnv): string[] {
	return (env.NVOOS_MCP_ORIGINS ?? '')
		.split(',')
		.map((entry) => entry.trim())
		.filter(Boolean);
}

async function readBody(req: IncomingMessage): Promise<unknown> {
	if (req.method !== 'POST' && req.method !== 'PUT') return undefined;
	const chunks: Buffer[] = [];
	for await (const chunk of req) {
		chunks.push(chunk as Buffer);
	}
	const raw = Buffer.concat(chunks).toString('utf8');
	if (!raw) return undefined;
	try {
		return JSON.parse(raw);
	} catch {
		return undefined;
	}
}

/**
 * Build the `(req, res) => Promise<void>` handler served at /mcp.
 */
export function createAstroMcpNodeHandler(env: McpEnv = process.env) {
	const handler = createMcpHandler(() => buildServer(), {
		legacy: 'stateless',
		onerror: (error) => {
			console.error('[mcp] handler error:', error.message);
		},
	});
	const nodeHandler = toNodeHandler(handler);
	const validateHost = hostHeaderValidation(allowedHosts(env));
	const validateOrigin = originValidation(allowedOrigins(env));

	return async (req: IncomingMessage, res: ServerResponse): Promise<void> => {
		if (!validateHost(req, res)) return;
		if (!validateOrigin(req, res)) return;
		const body = await readBody(req);
		const auth = await authorizeRequest(req, body, env);
		if (!auth.ok) {
			if (!res.headersSent) {
				res.writeHead(401, { 'content-type': 'application/json', 'cache-control': 'no-store' });
			}
			res.end(
				JSON.stringify({
					jsonrpc: '2.0',
					error: { code: -32001, message: auth.message ?? 'unauthorized' },
					id: null,
				})
			);
			return;
		}
		await nodeHandler(req, res, body);
	};
}
