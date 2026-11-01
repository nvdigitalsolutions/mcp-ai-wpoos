// ---------------------------------------------------------------------------
// rate-limit.test.ts — unit + HTTP matrix for the BFF rate-limit gate
// (Proposal 095 security hardening): fixed-window allow/deny with an
// injectable clock (recovery exactly at the window boundary), per-key
// isolation, high-cardinality sweeps, and the HTTP 429 (-32029) gate over a
// real node:http socket with NVOOS_MCP_RATE_MAX=2.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { createAstroMcpNodeHandler } from '../../mcp/bff';
import { createRateLimiter } from '../../mcp/ratelimit';

describe('createRateLimiter', () => {
	it('allows up to max requests per key within the window, then denies', () => {
		let tick = 0;
		const limiter = createRateLimiter({ max: 2, windowMs: 1000, now: () => tick });
		expect(limiter.allow('client-a')).toBe(true);
		expect(limiter.allow('client-a')).toBe(true);
		expect(limiter.allow('client-a')).toBe(false);
		expect(limiter.allow('client-a')).toBe(false);
	});

	it('recovers exactly when the window elapses', () => {
		let tick = 0;
		const limiter = createRateLimiter({ max: 1, windowMs: 1000, now: () => tick });
		expect(limiter.allow('client-a')).toBe(true);
		expect(limiter.allow('client-a')).toBe(false);
		tick = 999; // still inside the window
		expect(limiter.allow('client-a')).toBe(false);
		tick = 1000; // boundary: the oldest hit has expired
		expect(limiter.allow('client-a')).toBe(true);
	});

	it('slides the window off expired hits without unblocking early', () => {
		let tick = 0;
		const limiter = createRateLimiter({ max: 2, windowMs: 1000, now: () => tick });
		limiter.allow('client-a'); // t=0
		tick = 500;
		limiter.allow('client-a'); // t=500
		tick = 900;
		expect(limiter.allow('client-a')).toBe(false); // both hits still live
		tick = 1000; // the t=0 hit expires, the t=500 hit is live
		expect(limiter.allow('client-a')).toBe(true);
	});

	it('tracks keys independently', () => {
		let tick = 0;
		const limiter = createRateLimiter({ max: 1, windowMs: 1000, now: () => tick });
		expect(limiter.allow('a')).toBe(true);
		expect(limiter.allow('b')).toBe(true);
		expect(limiter.allow('a')).toBe(false);
		expect(limiter.allow('b')).toBe(false);
	});

	it('stays healthy under a high-cardinality key space', () => {
		let tick = 0;
		const limiter = createRateLimiter({ max: 1, windowMs: 1000, now: () => tick });
		for (let i = 0; i < 2000; i += 1) {
			expect(limiter.allow(`key-${i}`)).toBe(true);
		}
		// The sweep cadence keeps counting correct after the size cap passed.
		expect(limiter.allow('key-1')).toBe(false);
		tick = 1000;
		expect(limiter.allow('key-1')).toBe(true);
	});
});

const PORT = 4389;
let server: Server;

beforeAll(async () => {
	const handler = createAstroMcpNodeHandler({
		NVOOS_MCP_RATE_MAX: '2',
		NVOOS_MCP_RATE_WINDOW_MS: '60000',
	});
	server = createServer((req, res) => {
		void handler(req, res);
	});
	await new Promise<void>((resolve) => server.listen(PORT, '127.0.0.1', resolve));
});

afterAll(async () => {
	await new Promise<void>((resolve) => server.close(() => resolve()));
});

function modernHeaders(method: string): Record<string, string> {
	return {
		'MCP-Protocol-Version': '2026-07-28',
		'Mcp-Method': method,
	};
}

function toolsListBody(id: number): Record<string, unknown> {
	return {
		jsonrpc: '2.0',
		id,
		method: 'tools/list',
		params: {
			_meta: {
				'io.modelcontextprotocol/protocolVersion': '2026-07-28',
				'io.modelcontextprotocol/clientCapabilities': {},
			},
		},
	};
}

interface RateLimitError {
	jsonrpc: string;
	id: number | null;
	error?: { code: number; message: string };
}

describe('BFF rate-limit gate (HTTP)', () => {
	it('serves the first two requests and 429s the third with -32029', async () => {
		const post = () =>
			fetch(`http://127.0.0.1:${PORT}/mcp`, {
				method: 'POST',
				headers: {
					accept: 'application/json, text/event-stream',
					'content-type': 'application/json',
					...modernHeaders('tools/list'),
				},
				body: JSON.stringify(toolsListBody(1)),
			});

		const first = await post();
		expect(first.status).toBe(200);
		await first.text();

		const second = await post();
		expect(second.status).toBe(200);
		await second.text();

		const third = await post();
		expect(third.status).toBe(429);
		expect(third.headers.get('content-type')).toContain('application/json');
		const message = (await third.json()) as RateLimitError;
		expect(message.jsonrpc).toBe('2.0');
		expect(message.id).toBeNull();
		expect(message.error?.code).toBe(-32029);
		expect(message.error?.message).toBe('rate limit exceeded');
	});
});
