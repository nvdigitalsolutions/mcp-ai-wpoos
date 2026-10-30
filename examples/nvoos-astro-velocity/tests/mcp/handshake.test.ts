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
		tools?: Array<{ name: string }>;
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
