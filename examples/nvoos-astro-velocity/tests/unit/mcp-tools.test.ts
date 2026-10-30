// ---------------------------------------------------------------------------
// mcp-tools.test.ts — behavioral coverage for the CMS + ops tool slices
// (Proposal 095 Phase 3/4.3). A local stub backend serves the commerce
// contract while a real createAstroMcpNodeHandler serves /mcp over
// node:http — the same wire path as the handshake tests.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { createAstroMcpNodeHandler } from '../../mcp/bff';

const TOKEN = 'tool-test-token';

interface RecordedRequest {
	method: string;
	path: string;
	body: string;
	cookie?: string;
	contentType?: string;
}

const recorded: RecordedRequest[] = [];
let stub: Server;
let stubBase = '';
let mainPort = 0;
let deadPort = 0;
const servers: Server[] = [];

async function freePort(): Promise<number> {
	const probe = createServer();
	await new Promise<void>((resolve) => probe.listen(0, '127.0.0.1', resolve));
	const address = probe.address();
	const port = typeof address === 'object' && address ? address.port : 0;
	await new Promise<void>((resolve) => probe.close(() => resolve()));
	return port;
}

async function listen(env: Record<string, string | undefined>): Promise<number> {
	const handler = createAstroMcpNodeHandler(env);
	const server = createServer((req, res) => {
		void handler(req, res);
	});
	servers.push(server);
	const port = await freePort();
	await new Promise<void>((resolve) => server.listen(port, '127.0.0.1', resolve));
	return port;
}

beforeAll(async () => {
	// Stub backend serving the commerce contract collections.
	const stubPort = await freePort();
	stubBase = `http://127.0.0.1:${stubPort}`;
	stub = createServer(async (req, res) => {
		const url = new URL(req.url ?? '/', 'http://localhost');
		const chunks: Buffer[] = [];
		for await (const chunk of req) {
			chunks.push(chunk as Buffer);
		}
		const rawBody = Buffer.concat(chunks).toString('utf8');
		recorded.push({
			method: req.method ?? '',
			path: url.pathname,
			body: rawBody,
			cookie: req.headers.cookie,
			contentType: req.headers['content-type'],
		});
		const json = (status: number, payload: unknown) => {
			res.writeHead(status, { 'content-type': 'application/json' });
			res.end(JSON.stringify(payload));
		};
		if (req.method === 'GET' && url.pathname === '/products') {
			return json(200, {
				docs: [{ id: 1, name: 'Stub Product' }],
				totalDocs: 1,
				limit: 100,
				totalPages: 1,
				page: 1,
				hasNextPage: false,
				hasPrevPage: false,
			});
		}
		if (req.method === 'GET' && url.pathname === '/products/1') {
			return json(200, { id: 1, name: 'Stub Product' });
		}
		if (req.method === 'GET' && /^\/products\/\d+$/.test(url.pathname)) {
			return json(404, { code: 'not_found' });
		}
		if (req.method === 'POST' && url.pathname === '/products') {
			return json(201, { id: 2, ...reqBody(rawBody) });
		}
		if (req.method === 'PATCH' && url.pathname === '/products/1') {
			return json(200, { id: 1, ...reqBody(rawBody) });
		}
		if (req.method === 'DELETE' && url.pathname === '/products/1') {
			return json(200, { ok: true });
		}
		if (req.method === 'GET' && url.pathname === '/media') {
			return json(200, {
				docs: [],
				totalDocs: 0,
				limit: 100,
				totalPages: 0,
				page: 1,
				hasNextPage: false,
				hasPrevPage: false,
			});
		}
		return json(404, { code: 'not_found' });
	});
	await new Promise<void>((resolve) => stub.listen(stubPort, '127.0.0.1', resolve));

	// Main MCP mount — stub backend, explicit env overrides so ambient
	// process env can never leak into these assertions.
	mainPort = await listen({
		NVOOS_API_BASE: stubBase,
		NVOOS_SSR_API_ORIGIN: undefined,
		NVOOS_MCP_TOKEN: TOKEN,
		NVOOS_REBUILD_WEBHOOK_URL: undefined,
		NVOOS_MCP_SCREENSHOT: undefined,
	});

	// Second MCP mount pointing at a port nothing listens on.
	const unused = await freePort();
	deadPort = await listen({
		NVOOS_API_BASE: `http://127.0.0.1:${unused}`,
		NVOOS_SSR_API_ORIGIN: undefined,
	});
});

afterAll(async () => {
	for (const server of servers) {
		await new Promise<void>((resolve) => server.close(() => resolve()));
	}
	await new Promise<void>((resolve) => stub.close(() => resolve()));
});

function reqBody(raw: string): Record<string, unknown> {
	try {
		return JSON.parse(raw) as Record<string, unknown>;
	} catch {
		return {};
	}
}

interface JsonRpcResult {
	jsonrpc: string;
	id: number | null;
	result?: {
		content?: Array<{ type: string; text?: string; data?: string; mimeType?: string }>;
		structuredContent?: Record<string, unknown>;
		isError?: boolean;
		tools?: Array<{ name: string }>;
	};
	error?: { code: number; message: string };
}

async function rpc(
	port: number,
	body: unknown,
	headers: Record<string, string> = {}
): Promise<{ status: number; message: JsonRpcResult }> {
	const response = await fetch(`http://127.0.0.1:${port}/mcp`, {
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
		const lines = raw.split('\n').filter((line) => line.startsWith('data: '));
		message = JSON.parse(lines.map((line) => line.slice(6)).join('')) as JsonRpcResult;
	} else {
		message = JSON.parse(raw) as JsonRpcResult;
	}
	return { status: response.status, message };
}

function call(
	port: number,
	name: string,
	args: Record<string, unknown>,
	headers: Record<string, string> = {}
) {
	return rpc(
		port,
		{
			jsonrpc: '2.0',
			id: 1,
			method: 'tools/call',
			params: {
				name,
				arguments: args,
				_meta: {
					'io.modelcontextprotocol/protocolVersion': '2026-07-28',
					'io.modelcontextprotocol/clientCapabilities': {},
				},
			},
		},
		{
			'MCP-Protocol-Version': '2026-07-28',
			'Mcp-Method': 'tools/call',
			'Mcp-Name': name,
			...headers,
		}
	);
}

const textOf = (message: JsonRpcResult) =>
	(message.result?.content ?? []).map((entry) => entry.text ?? '').join('');

describe('cms tools over the stub backend', () => {
	it('cms_list returns docs with the paginated envelope', async () => {
		const { message } = await call(mainPort, 'cms_list', { collection: 'products', limit: 2 });
		expect(message.error).toBeUndefined();
		expect(message.result?.isError).toBeUndefined();
		expect(message.result?.structuredContent?.totalDocs).toBe(1);
		expect(message.result?.structuredContent?.totalPages).toBe(1);
		expect(JSON.stringify(message.result?.structuredContent?.docs)).toContain('Stub Product');
		expect(textOf(message)).toContain('"docs"');
	});

	it('cms_get returns the single document by id', async () => {
		const { message } = await call(mainPort, 'cms_get', { collection: 'products', id: 1 });
		expect(message.error).toBeUndefined();
		expect(message.result?.isError).toBeUndefined();
		expect(message.result?.structuredContent?.name).toBe('Stub Product');
	});

	it('cms_get surfaces a backend 404 as a typed error result', async () => {
		const { message } = await call(mainPort, 'cms_get', { collection: 'products', id: 999 });
		expect(message.error).toBeUndefined();
		expect(message.result?.isError).toBe(true);
		expect(textOf(message)).toContain('not found');
	});

	it('cms_create without a manager cookie fails with the manager-session error', async () => {
		const { message } = await call(
			mainPort,
			'cms_create',
			{ collection: 'products', body: { name: 'No Cookie' } },
			{ authorization: `Bearer ${TOKEN}` }
		);
		expect(message.error).toBeUndefined();
		expect(message.result?.isError).toBe(true);
		expect(textOf(message)).toContain('manager session');
	});

	it('cms_create with a manager cookie forwards the POST with the body', async () => {
		recorded.length = 0;
		const { message } = await call(
			mainPort,
			'cms_create',
			{ collection: 'products', body: { name: 'New Product' } },
			{ authorization: `Bearer ${TOKEN}`, cookie: 'session=manager-cookie' }
		);
		expect(message.error).toBeUndefined();
		expect(message.result?.isError).toBeUndefined();
		expect(message.result?.structuredContent?.name).toBe('New Product');
		const post = recorded.find((entry) => entry.method === 'POST' && entry.path === '/products');
		expect(post).toBeDefined();
		expect(post?.cookie).toContain('session=manager-cookie');
		expect(reqBody(post?.body ?? '{}')).toEqual({ name: 'New Product' });
	});
});

describe('ops tools', () => {
	it('site_status reports contract reachability against the stub', async () => {
		const { message } = await call(mainPort, 'site_status', {});
		expect(message.error).toBeUndefined();
		expect(message.result?.structuredContent?.contract_reachable).toMatchObject({
			ok: true,
			status: 200,
		});
		expect(message.result?.structuredContent?.active_theme).toBe('light');
		expect(message.result?.structuredContent?.screenshot_enabled).toBe(false);
		expect(message.result?.structuredContent?.rebuild_webhook_configured).toBe(false);
	});

	it('site_status reports the contract unreachable on a dead port', async () => {
		const { message } = await call(deadPort, 'site_status', {});
		expect(message.error).toBeUndefined();
		expect(message.result?.structuredContent?.contract_reachable).toMatchObject({
			ok: false,
		});
	});

	it('preview_url renders the storefront origin + path', async () => {
		const { message } = await call(mainPort, 'preview_url', { path: '/collections/new' });
		expect(message.error).toBeUndefined();
		// The origin is derived from the request Host when no
		// NVOOS_STOREFRONT_ORIGIN override is configured.
		expect(message.result?.structuredContent?.url).toBe(
			`http://127.0.0.1:${mainPort}/collections/new`
		);
	});

	it('preview_url honours the NVOOS_STOREFRONT_ORIGIN override', async () => {
		const port = await listen({
			NVOOS_API_BASE: stubBase,
			NVOOS_SSR_API_ORIGIN: undefined,
			NVOOS_STOREFRONT_ORIGIN: 'https://astro.nvoos.cloud',
		});
		const { message } = await call(port, 'preview_url', { path: '/collections/new' });
		expect(message.error).toBeUndefined();
		expect(message.result?.structuredContent?.url).toBe(
			'https://astro.nvoos.cloud/collections/new'
		);
	});

	it('preview_url rejects paths that do not start with /', async () => {
		const { message } = await call(mainPort, 'preview_url', { path: 'relative/path' });
		expect(message.result?.isError).toBe(true);
	});

	it('rebuild_trigger errors when no webhook is configured', async () => {
		const { message } = await call(mainPort, 'rebuild_trigger', {}, { authorization: `Bearer ${TOKEN}` });
		expect(message.result?.isError).toBe(true);
		expect(textOf(message)).toContain('NVOOS_REBUILD_WEBHOOK_URL');
	});

	it('screenshot errors when the opt-in flag is off', async () => {
		const { message } = await call(mainPort, 'screenshot', { path: '/home' }, { authorization: `Bearer ${TOKEN}` });
		expect(message.result?.isError).toBe(true);
		expect(textOf(message)).toContain('NVOOS_MCP_SCREENSHOT');
	});

	it('nds_export emits the NDS DTCG document with the mapping report', async () => {
		const { message } = await call(mainPort, 'nds_export', {});
		expect(message.error).toBeUndefined();
		const sc = message.result?.structuredContent as {
			doc?: { colors?: Record<string, { $type: string; $value: string }> };
			imported?: string[];
			unmapped?: unknown[];
		};
		expect(sc.doc?.colors?.color_accent?.$type).toBe('color');
		expect(sc.doc?.colors?.color_accent?.$value).toMatch(/^#/);
		expect(sc.imported).toContain('accent -> colors.color_accent');
		expect(sc.imported).toContain('radius -> borders.radius_md');
		expect(sc.unmapped?.length).toBeGreaterThanOrEqual(3);
	});
});

describe('design tools', () => {
	it('theme_apply echoes a snapshot_id that tokens_rollback restores', async () => {
		// Fresh mount so design state stays isolated from the other suites.
		const port = await listen({
			NVOOS_API_BASE: stubBase,
			NVOOS_SSR_API_ORIGIN: undefined,
			NVOOS_MCP_TOKEN: TOKEN,
		});

		const apply = await call(
			port,
			'theme_apply',
			{ name: 'dark' },
			{ authorization: `Bearer ${TOKEN}` }
		);
		expect(apply.message.error).toBeUndefined();
		expect(apply.message.result?.structuredContent).toMatchObject({ ok: true });
		const snapshotId = apply.message.result?.structuredContent?.snapshot_id as string;
		expect(snapshotId).toBeTruthy();

		const listed = await call(port, 'theme_list', {});
		expect(listed.message.result?.structuredContent?.active).toBe('dark');

		const rollback = await call(
			port,
			'tokens_rollback',
			{ snapshot_id: snapshotId },
			{ authorization: `Bearer ${TOKEN}` }
		);
		expect(rollback.message.result?.structuredContent).toMatchObject({ ok: true });

		const restored = await call(port, 'theme_list', {});
		expect(restored.message.result?.structuredContent?.active).toBe('light'); // pre-apply theme restored
	});
});
