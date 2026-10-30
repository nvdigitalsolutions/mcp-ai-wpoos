// ---------------------------------------------------------------------------
// bff.ts — Streamable HTTP mount for the storefront BFF (Proposal 095 §3.3).
// Bundled by scripts/build-mcp.mjs into dist/mcp-entry.mjs; scripts/serve.mjs
// routes /mcp here. Request pipeline (in order): the per-IP rate-limit gate
// (Proposal 095 hardening), Host/Origin validation (MCP spec DNS-rebinding
// guards), and the fail-closed write-tool authorization gate — then the SDK
// handler runs inside mcpRequestContext so tool code can read the request
// cookie/env. Write calls that pass auth are audit-logged.
//
// TODO(095-Phase-4): the /manage Design tab and the NDS round-trip through
// the commerce contract land in a follow-up (issue #7319); durable design
// state itself shipped via NVOOS_MCP_STATE_FILE (src/lib/tokens/persistence.ts).
// ---------------------------------------------------------------------------

import { createMcpHandler } from '@modelcontextprotocol/server';
import {
	toNodeHandler,
	hostHeaderValidation,
	originValidation,
} from '@modelcontextprotocol/node';
import type { IncomingMessage, ServerResponse } from 'node:http';
import { buildServer } from './server';
import { authorizeRequest, firstHeaderValue, isWriteToolCall } from './auth';
import { mcpRequestContext } from './context';
import { createRateLimiter } from './ratelimit';

interface McpEnv {
	NVOOS_MCP_TOKEN?: string;
	NVOOS_MCP_HOSTS?: string;
	NVOOS_MCP_ORIGINS?: string;
	NVOOS_SSR_API_ORIGIN?: string;
	NVOOS_API_BASE?: string;
	NVOOS_MCP_RATE_MAX?: string;
	NVOOS_MCP_RATE_WINDOW_MS?: string;
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

const DEFAULT_RATE_MAX = 120;
const DEFAULT_RATE_WINDOW_MS = 60000;

function positiveInt(value: string | undefined, fallback: number): number {
	const parsed = Number.parseInt(value ?? '', 10);
	return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback;
}

function toolNameFrom(body: unknown): string {
	const name = (body as { params?: { name?: unknown } } | null)?.params?.name;
	return typeof name === 'string' ? name : 'unknown';
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
	const limiter = createRateLimiter({
		max: positiveInt(env.NVOOS_MCP_RATE_MAX, DEFAULT_RATE_MAX),
		windowMs: positiveInt(env.NVOOS_MCP_RATE_WINDOW_MS, DEFAULT_RATE_WINDOW_MS),
	});
	// Tool handlers read this store via getMcpRequestContext(); merge the
	// ambient process env so a caller-supplied override never hides the rest.
	const contextEnv: Record<string, string | undefined> = { ...process.env, ...env };

	return async (req: IncomingMessage, res: ServerResponse): Promise<void> => {
		const ip = req.socket.remoteAddress ?? 'unknown';
		if (!limiter.allow(ip)) {
			if (!res.headersSent) {
				res.writeHead(429, { 'content-type': 'application/json', 'cache-control': 'no-store' });
			}
			res.end(
				JSON.stringify({
					jsonrpc: '2.0',
					error: { code: -32029, message: 'rate limit exceeded' },
					id: null,
				})
			);
			return;
		}
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
		if (isWriteToolCall(body)) {
			const via = auth.bearer ? 'bearer' : auth.manager ? 'manager' : 'unknown';
			console.log(`[mcp] audit tool=${toolNameFrom(body)} via=${via} ip=${ip}`);
		}
		await mcpRequestContext.run(
			{ cookie: firstHeaderValue(req.headers.cookie), env: contextEnv },
			() => nodeHandler(req, res, body)
		);
	};
}
