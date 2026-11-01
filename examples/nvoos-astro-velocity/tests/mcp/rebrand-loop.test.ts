// ---------------------------------------------------------------------------
// rebrand-loop.test.ts — the assistant-style rebrand loop (Proposal 095
// Phase 4.3), hermetic: the real fixture backend serves the commerce
// contract on :8131 while a real createAstroMcpNodeHandler serves /mcp over
// node:http. tokens_get → dry-run set → apply → verify → contrast AA →
// rollback → cms_list. The fixture server MUST start or the test fails
// loudly (never a silent skip).
// ---------------------------------------------------------------------------

import { spawn, type ChildProcess } from 'node:child_process';
import { createServer, type Server } from 'node:http';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { createAstroMcpNodeHandler } from '../../mcp/bff';

const APP_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const FIXTURE_PORT = 8131;
const FIXTURE_BASE = `http://localhost:${FIXTURE_PORT}/wp-json/nvoos-commerce/v1`;
const TOKEN = 'loop-token';

let fixture: ChildProcess | undefined;
let fixtureStderr = '';
let server: Server;
let port = 0;

async function freePort(): Promise<number> {
	const probe = createServer();
	await new Promise<void>((resolve) => probe.listen(0, '127.0.0.1', resolve));
	const address = probe.address();
	const opened = typeof address === 'object' && address ? address.port : 0;
	await new Promise<void>((resolve) => probe.close(() => resolve()));
	return opened;
}

async function waitForFixture(timeoutMs = 15000): Promise<void> {
	const deadline = Date.now() + timeoutMs;
	let lastError = 'not polled yet';
	while (Date.now() < deadline) {
		try {
			const res = await fetch(`${FIXTURE_BASE}/products?limit=1`);
			if (res.ok) return;
			lastError = `status ${res.status}`;
		} catch (error) {
			lastError = (error as Error).message;
		}
		await new Promise((resolve) => setTimeout(resolve, 200));
	}
	throw new Error(
		`fixture server did not become ready on :${FIXTURE_PORT} (last: ${lastError}; stderr: ${fixtureStderr || '(empty)'})`
	);
}

beforeAll(async () => {
	fixture = spawn(process.execPath, ['scripts/fixture-server.mjs'], {
		cwd: APP_DIR,
		env: { ...process.env, PORT: String(FIXTURE_PORT) },
		stdio: ['ignore', 'pipe', 'pipe'],
	});
	fixture.stderr?.on('data', (chunk: Buffer) => {
		fixtureStderr += chunk.toString();
	});
	await waitForFixture();

	const handler = createAstroMcpNodeHandler({
		NVOOS_API_BASE: FIXTURE_BASE,
		NVOOS_SSR_API_ORIGIN: undefined,
		NVOOS_MCP_TOKEN: TOKEN,
	});
	server = createServer((req, res) => {
		void handler(req, res);
	});
	port = await freePort();
	await new Promise<void>((resolve) => server.listen(port, '127.0.0.1', resolve));
});

afterAll(async () => {
	await new Promise<void>((resolve) => server.close(() => resolve()));
	if (fixture && !fixture.killed) {
		fixture.kill();
	}
});

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

async function call(
	name: string,
	args: Record<string, unknown>,
	headers: Record<string, string> = {}
): Promise<JsonRpcResult> {
	const response = await fetch(`http://127.0.0.1:${port}/mcp`, {
		method: 'POST',
		headers: {
			accept: 'application/json, text/event-stream',
			'content-type': 'application/json',
			'MCP-Protocol-Version': '2026-07-28',
			'Mcp-Method': 'tools/call',
			'Mcp-Name': name,
			...headers,
		},
		body: JSON.stringify({
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
		}),
	});
	const contentType = response.headers.get('content-type') ?? '';
	const raw = await response.text();
	if (contentType.includes('text/event-stream')) {
		const lines = raw.split('\n').filter((line) => line.startsWith('data: '));
		return JSON.parse(lines.map((line) => line.slice(6)).join('')) as JsonRpcResult;
	}
	return JSON.parse(raw) as JsonRpcResult;
}

const bearer = () => ({ authorization: `Bearer ${TOKEN}` });

const semanticOf = (message: JsonRpcResult) =>
	message.result?.structuredContent as { semantic?: Record<string, string> } | undefined;

describe('rebrand loop against the fixture backend', () => {
	it('runs tokens_get → dry-run → apply → verify → contrast AA → rollback → cms_list', async () => {
		// 1. Baseline tokens.
		const baseline = await call('tokens_get', {});
		expect(baseline.error).toBeUndefined();
		expect(semanticOf(baseline)?.semantic?.accent).toBe('#0f6b4f');

		// 2. Dry run — diff previewed, nothing applied.
		const dry = await call('tokens_set', { changes: { accent: '#0a3d62' }, dry_run: true }, bearer());
		expect(dry.error).toBeUndefined();
		expect(dry.result?.structuredContent?.applied).toBe(false);
		expect(JSON.stringify(dry.result?.structuredContent?.diff)).toContain('accent');

		// 3. Apply the accent change.
		const apply = await call('tokens_set', { changes: { accent: '#0a3d62' } }, bearer());
		expect(apply.error).toBeUndefined();
		expect(apply.result?.structuredContent?.applied).toBe(true);

		// 4. Verify the change is live.
		const verify = await call('tokens_get', {});
		expect(semanticOf(verify)?.semantic?.accent).toBe('#0a3d62');

		// 5. Contrast on the new accent passes WCAG AA.
		const contrast = await call('contrast_check', { fg: '#ffffff', bg: '#0a3d62' });
		expect(contrast.error).toBeUndefined();
		expect(contrast.result?.structuredContent?.aaNormal).toBe(true);

		// 6. Roll back to the snapshot.
		const rollback = await call('tokens_rollback', {}, bearer());
		expect(rollback.error).toBeUndefined();
		expect(rollback.result?.structuredContent?.ok).toBe(true);
		const after = await call('tokens_get', {});
		expect(semanticOf(after)?.semantic?.accent).toBe('#0f6b4f');

		// 7. The CMS slice reads the fixture catalog.
		const list = await call('cms_list', { collection: 'products', limit: 10 });
		expect(list.error).toBeUndefined();
		const payload = list.result?.structuredContent as
			| { docs?: unknown[]; totalDocs?: number }
			| undefined;
		expect((payload?.docs ?? []).length).toBeGreaterThan(0);
		expect(payload?.totalDocs).toBeGreaterThan(0);
	});
});
