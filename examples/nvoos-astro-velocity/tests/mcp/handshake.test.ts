// handshake.test.ts — MCP Streamable HTTP conformance over a real node:http
// socket (Proposal 095 §4.2 exit criteria), covering BOTH wire eras the
// endpoint serves:
//
//   - modern (2026-07-28): server/discover negotiation probe, per-request
//     params._meta envelope, SEP-2243 MCP-Protocol-Version / Mcp-Method /
//     Mcp-Name headers, JSON responses stamped with resultType.
//   - legacy (2025-era): claim-less initialize handshake served by the
//     stateless fallback over SSE.
//
// plus the write-auth denial gate.
import { createServer, type Server } from 'node:http';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { createAstroMcpNodeHandler } from '../../mcp/bff';

const PORT = 4387;
let server: Server;

beforeAll(async () => {
	const handler = createAstroMcpNodeHandler({});
	server = createServer((req, res) => {
		void handler(req, res);
	});
	await new Promise<void>((resolve) => server.listen(PORT, '127.0.0.1', resolve));
});

afterAll(async () => {
	await new Promise<void>((resolve) => server.close(() => resolve()));
});

interface JsonRpcResult {
	jsonrpc: string;
	id: number | null;
	result?: {
		protocolVersion?: string;
		supportedVersions?: string[];
		resultType?: string;
		tools?: Array<{
			name: string;
			title?: string;
			annotations?: {
				readOnlyHint?: boolean;
				destructiveHint?: boolean;
				idempotentHint?: boolean;
				openWorldHint?: boolean;
			};
		}>;
		content?: Array<{ type: string; text: string }>;
		structuredContent?: { applied?: boolean; diff?: unknown };
	};
	error?: { code: number; message: string };
}

async function rpc(body: unknown, headers: Record<string, string> = {}): Promise<{ status: number; message: JsonRpcResult; contentType: string }> {
	const response = await fetch(`http://127.0.0.1:${PORT}/mcp`, {
		method: 'POST',
		headers: {
			accept: 'application/json, text/event-stream',
			'content-type': 'application/json',
			...headers,
		},
		body: JSON.stringify(body),
	});
	const contentType = response.headers.get('content-type') ?? '';
	const raw = await response.text();
	let message: JsonRpcResult;
	if (contentType.includes('text/event-stream')) {
		// Extract the JSON-RPC response with a matching id from the SSE stream.
		const lines = raw.split('\n').filter((line) => line.startsWith('data: '));
		message = JSON.parse(lines.map((line) => line.slice(6)).join('')) as JsonRpcResult;
	} else {
		message = JSON.parse(raw) as JsonRpcResult;
	}
	return { status: response.status, message, contentType };
}

/** The per-request `_meta` envelope every modern (2026-07-28) request carries in params. */
function modernEnvelope(): Record<string, unknown> {
	return {
		_meta: {
			'io.modelcontextprotocol/protocolVersion': '2026-07-28',
			'io.modelcontextprotocol/clientCapabilities': {},
		},
	};
}

/** SEP-2243 standard headers required on every modern request POST. */
function modernHeaders(method: string, name?: string): Record<string, string> {
	return {
		'MCP-Protocol-Version': '2026-07-28',
		'Mcp-Method': method,
		...(name !== undefined && { 'Mcp-Name': name }),
	};
}

const EXPECTED_TOOLS = [
	'tokens_get',
	'tokens_set',
	'tokens_import_dtcg',
	'tokens_rollback',
	'theme_list',
	'theme_get',
	'theme_create',
	'theme_apply',
	'theme_delete',
	'contrast_check',
	'design_analyze',
	'design_compare',
	'design_plan',
	'design_theme',
	'cms_list',
	'cms_get',
	'cms_create',
	'cms_update',
	'cms_delete',
	'media_upload',
	'site_status',
	'preview_url',
	'rebuild_trigger',
	'screenshot',
	'nds_export',
];

describe('nvoos-astro-mcp HTTP conformance', () => {
	it('negotiates the modern protocol revision via server/discover', async () => {
		const { status, message } = await rpc(
			{
				jsonrpc: '2.0',
				id: 1,
				method: 'server/discover',
				params: { ...modernEnvelope() },
			},
			modernHeaders('server/discover')
		);
		expect(status).toBe(200);
		expect(message.error).toBeUndefined();
		expect(message.result?.supportedVersions).toContain('2026-07-28');
		expect(message.result?.resultType).toBe('complete');
	});

	it('serves the legacy initialize handshake through the stateless fallback', async () => {
		const { status, message, contentType } = await rpc({
			jsonrpc: '2.0',
			id: 2,
			method: 'initialize',
			params: {
				protocolVersion: '2025-11-25',
				capabilities: {},
				clientInfo: { name: 'vitest', version: '1.0.0' },
			},
		});
		expect(status).toBe(200);
		expect(contentType).toContain('text/event-stream');
		expect(message.result?.protocolVersion).toBe('2025-11-25');
	});

	it('rejects a legacy initialize sent with a modern protocol header', async () => {
		const { status, message } = await rpc(
			{
				jsonrpc: '2.0',
				id: 3,
				method: 'initialize',
				params: {
					protocolVersion: '2025-11-25',
					capabilities: {},
					clientInfo: { name: 'vitest', version: '1.0.0' },
				},
			},
			{ 'MCP-Protocol-Version': '2026-07-28' }
		);
		expect(status).toBe(400);
		expect(message.error?.code).toBe(-32020);
	});

	it('lists the design tool slice over the modern dialect', async () => {
		const { status, message } = await rpc(
			{
				jsonrpc: '2.0',
				id: 4,
				method: 'tools/list',
				params: { ...modernEnvelope() },
			},
			modernHeaders('tools/list')
		);
		expect(status).toBe(200);
		expect(message.error).toBeUndefined();
		const names = (message.result?.tools ?? []).map((tool) => tool.name);
		for (const expected of EXPECTED_TOOLS) {
			expect(names).toContain(expected);
		}
	});

	it('publishes tool annotations per the MCP spec (2025-06-18)', async () => {
		const { status, message } = await rpc(
			{
				jsonrpc: '2.0',
				id: 41,
				method: 'tools/list',
				params: { ...modernEnvelope() },
			},
			modernHeaders('tools/list')
		);
		expect(status).toBe(200);
		const byName = new Map((message.result?.tools ?? []).map((tool) => [tool.name, tool]));
		// Reads declare themselves read-only and non-destructive.
		expect(byName.get('site_status')?.annotations).toEqual({
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: false,
			openWorldHint: false,
		});
		// Deletes declare the destructive hint.
		expect(byName.get('cms_delete')?.annotations?.destructiveHint).toBe(true);
		expect(byName.get('theme_delete')?.annotations?.destructiveHint).toBe(true);
		// Retry-safe writes declare the idempotent hint.
		expect(byName.get('tokens_set')?.annotations?.idempotentHint).toBe(true);
		expect(byName.get('rebuild_trigger')?.annotations?.idempotentHint).toBe(true);
		// Every tool declares all four hints (spec defaults are pessimistic).
		// The open-world exceptions (Proposal 098): the design-import tools
		// fetch external URLs and declare it; every other tool is
		// closed-domain.
		const OPEN_WORLD_TOOLS = new Set(['design_analyze', 'design_compare', 'design_plan', 'design_theme']);
		for (const tool of message.result?.tools ?? []) {
			expect(tool.annotations, `missing annotations on ${tool.name}`).toBeDefined();
			expect(tool.annotations?.readOnlyHint, `missing readOnlyHint on ${tool.name}`).toBeDefined();
			expect(tool.annotations?.destructiveHint, `missing destructiveHint on ${tool.name}`).toBeDefined();
			expect(tool.annotations?.idempotentHint, `missing idempotentHint on ${tool.name}`).toBeDefined();
			if (OPEN_WORLD_TOOLS.has(tool.name)) {
				expect(tool.annotations?.openWorldHint, `${tool.name} must declare openWorldHint`).toBe(true);
			} else {
				expect(tool.annotations?.openWorldHint, `missing openWorldHint on ${tool.name}`).toBe(false);
			}
		}
		// The open-world write still declares read-only false.
		expect(byName.get('design_theme')?.annotations?.readOnlyHint).toBe(false);
	});

	it('resolves preview_url origins from the request Host (no more localhost-only)', async () => {
		const { status, message } = await rpc(
			{
				jsonrpc: '2.0',
				id: 42,
				method: 'tools/call',
				params: { name: 'preview_url', arguments: { path: '/products' }, ...modernEnvelope() },
			},
			modernHeaders('tools/call', 'preview_url')
		);
		expect(status).toBe(200);
		expect(message.error).toBeUndefined();
		const text = (message.result?.content ?? []).map((entry) => entry.text).join('');
		expect(text).toContain(`http://127.0.0.1:${PORT}/products`);
	});

	it('calls a read tool end-to-end over the modern dialect', async () => {
		const { status, message } = await rpc(
			{
				jsonrpc: '2.0',
				id: 5,
				method: 'tools/call',
				params: { name: 'tokens_get', arguments: { include_groups: true }, ...modernEnvelope() },
			},
			modernHeaders('tools/call', 'tokens_get')
		);
		expect(status).toBe(200);
		expect(message.error).toBeUndefined();
		const text = (message.result?.content ?? []).map((entry) => entry.text).join('');
		expect(text).toContain('active_theme');
		expect(text).toContain('#1a1a1a');
	});

	it('denies write tools without credentials (fail closed)', async () => {
		const { status, message } = await rpc(
			{
				jsonrpc: '2.0',
				id: 6,
				method: 'tools/call',
				params: { name: 'tokens_set', arguments: { changes: { accent: '#ff0000' } }, ...modernEnvelope() },
			},
			modernHeaders('tools/call', 'tokens_set')
		);
		expect(status).toBe(401);
		expect(message.error?.code).toBe(-32001);
	});

	it('allows same-origin browser requests (the /manage Design tab)', async () => {
		const { status } = await rpc(
			{
				jsonrpc: '2.0',
				id: 60,
				method: 'tools/list',
				params: { ...modernEnvelope() },
			},
			{ ...modernHeaders('tools/list'), origin: `http://127.0.0.1:${PORT}` }
		);
		expect(status).toBe(200);
	});

	it('rejects cross-origin browser requests when no origin allowlist is set', async () => {
		const { status } = await rpc(
			{
				jsonrpc: '2.0',
				id: 61,
				method: 'tools/list',
				params: { ...modernEnvelope() },
			},
			{ ...modernHeaders('tools/list'), origin: 'https://evil.example' }
		);
		expect(status).toBeGreaterThanOrEqual(400);
	});

	it('snapshots the env at construction (caller mutations do not leak into auth)', async () => {
		const token = 'snapshot-token';
		const env: Record<string, string | undefined> = {
			NVOOS_MCP_TOKEN: token,
			NVOOS_SSR_API_ORIGIN: 'http://localhost:8123/wp-json/nvoos-commerce/v1',
		};
		const handler = createAstroMcpNodeHandler(env);
		// serve.mjs rewrites NVOOS_SSR_API_ORIGIN after boot (loopback BFF
		// origin for the SSR client) — the handler must keep its snapshot.
		env.NVOOS_MCP_TOKEN = 'mutated';
		const authed = createServer((req, res) => {
			void handler(req, res);
		});
		await new Promise<void>((resolve) => authed.listen(4393, '127.0.0.1', resolve));
		try {
			const response = await fetch('http://127.0.0.1:4393/mcp', {
				method: 'POST',
				headers: {
					accept: 'application/json, text/event-stream',
					'content-type': 'application/json',
					authorization: `Bearer ${token}`,
					...modernHeaders('tools/call', 'tokens_set'),
				},
				body: JSON.stringify({
					jsonrpc: '2.0',
					id: 62,
					method: 'tools/call',
					params: {
						name: 'tokens_set',
						arguments: { changes: { accent: '#0f6b4f' } },
						...modernEnvelope(),
					},
				}),
			});
			expect(response.status).toBe(200);
		} finally {
			await new Promise<void>((resolve) => authed.close(() => resolve()));
		}
	});

	it('allows write tools with a valid bearer token', async () => {
		const token = 'test-secret-token';
		const handler = createAstroMcpNodeHandler({ NVOOS_MCP_TOKEN: token });
		const authed = createServer((req, res) => {
			void handler(req, res);
		});
		await new Promise<void>((resolve) => authed.listen(4388, '127.0.0.1', resolve));
		try {
			const response = await fetch('http://127.0.0.1:4388/mcp', {
				method: 'POST',
				headers: {
					accept: 'application/json, text/event-stream',
					'content-type': 'application/json',
					authorization: `Bearer ${token}`,
					...modernHeaders('tools/call', 'tokens_set'),
				},
				body: JSON.stringify({
					jsonrpc: '2.0',
					id: 7,
					method: 'tools/call',
					params: {
						name: 'tokens_set',
						arguments: { changes: { accent: '#00ff00' }, dry_run: true },
						...modernEnvelope(),
					},
				}),
			});
			const raw = await response.text();
			expect(response.status).toBe(200);
			// The response may be JSON or an SSE stream — parse either.
			let message: JsonRpcResult;
			const contentType = response.headers.get('content-type') ?? '';
			if (contentType.includes('text/event-stream')) {
				const lines = raw.split('\n').filter((line) => line.startsWith('data: '));
				message = JSON.parse(lines.map((line) => line.slice(6)).join('')) as JsonRpcResult;
			} else {
				message = JSON.parse(raw) as JsonRpcResult;
			}
			expect(message.error).toBeUndefined();
			expect(message.result?.structuredContent?.applied).toBe(false); // dry run
			expect(message.result?.structuredContent?.diff).toBeDefined();
		} finally {
			await new Promise<void>((resolve) => authed.close(() => resolve()));
		}
	});
});
