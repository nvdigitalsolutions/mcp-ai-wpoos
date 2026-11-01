// ---------------------------------------------------------------------------
// design-import.spec.ts — Proposal 098 end-to-end: the complete
// inspired-by recreation loop driven from a real browser over /mcp.
//
// analyze → plan → compare → emit theme → apply → verify → rollback →
// cleanup, against a local design-source server (private host, allowed on
// the e2e mount via NVOOS_MCP_FETCH_ALLOW_PRIVATE=1 — a dev-only mount).
// ---------------------------------------------------------------------------
import { createServer, type Server } from 'node:http';
import { expect, test } from '@playwright/test';

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:4321';
let designServer: Server;
let designUrl = '';

test.beforeAll(async () => {
	const server = createServer((req, res) => {
		if (req.url === '/') {
			res.writeHead(200, { 'content-type': 'text/html' });
			res.end(
				'<html><head><link rel="stylesheet" href="/demo.css"></head><body class="page"><nav class="nav">Nav</nav><div class="card">Card</div><button class="btn">Buy</button></body></html>'
			);
			return;
		}
		if (req.url === '/demo.css') {
			res.writeHead(200, { 'content-type': 'text/css' });
			res.end(':root { --color-accent: #0f6b4f; --color-background: #faf6f1; --color-text: #2b2b2b; } .card { border-radius: 8px; padding: 16px; }');
			return;
		}
		res.writeHead(404);
		res.end();
	});
	await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
	const address = server.address();
	const port = typeof address === 'object' && address ? address.port : 0;
	designUrl = `http://127.0.0.1:${port}/`;
	designServer = server;
});

test.afterAll(async () => {
	await new Promise<void>((resolve) => designServer.close(() => resolve()));
});

function mcpBody(name: string, args: Record<string, unknown> = {}): string {
	return JSON.stringify({
		jsonrpc: '2.0',
		id: 'e2e-design',
		method: 'tools/call',
		params: {
			name,
			arguments: args,
			_meta: {
				'io.modelcontextprotocol/protocolVersion': '2026-07-28',
				'io.modelcontextprotocol/clientCapabilities': {},
			},
		},
	});
}

const MCP_HEADERS = {
	accept: 'application/json',
	'content-type': 'application/json',
	'MCP-Protocol-Version': '2026-07-28',
	'Mcp-Method': 'tools/call',
};

test('inspired-by recreation loop over /mcp from a real browser', async ({ page }) => {
	await page.goto(`${BASE}/`);
	await page.context().addCookies([
		{ name: 'nvoos_fixture_session', value: '1', url: BASE },
		// Write tools require the manager session — the fixture grants
		// canManage when this flag cookie rides alongside the session.
		{ name: 'nvoos_fixture_manager', value: '1', url: BASE },
	]);

	const call = async (name: string, args: Record<string, unknown> = {}) => {
		const result = await page.evaluate(
			async ({ name: tool, body, headers }) => {
				const res = await fetch('/mcp', {
					method: 'POST',
					headers: { ...headers, 'Mcp-Name': tool },
					body,
				});
				if (!res.ok) {
					return { http: res.status, text: await res.text() };
				}
				return res.json();
			},
			{ name, args, body: mcpBody(name, args), headers: MCP_HEADERS }
		);
		return result as {
			http?: number;
			error?: { message?: string };
			result?: {
				isError?: boolean;
				structuredContent?: Record<string, unknown>;
				content?: Array<{ text?: string }>;
			};
		};
	};

	// 1. Analyze the local design source (private host, dev-mount opt-in).
	const analyzed = await call('design_analyze', { url: designUrl });
	expect(analyzed.error).toBeUndefined();
	expect(analyzed.result?.isError, 'design_analyze returned a typed tool error').toBeUndefined();
	expect(analyzed.result?.structuredContent, 'design_analyze returned no structured content').toBeDefined();
	const analysis = analyzed.result?.structuredContent as
		| {
				source?: { mode?: string };
				designLanguage?: {
					palette?: { roles?: Record<string, { value: string; confidence: string }> };
				};
		  }
		| undefined;
	expect(analysis?.source?.mode).toBe('generic');
	expect(analysis?.designLanguage?.palette?.roles?.accent).toMatchObject({
		value: '#0f6b4f',
		confidence: 'high',
	});

	// 2. Plan the content-first adaptation (no fetch — the analysis rides along).
	const plan = await call('design_plan', { analysis, content_hint: 'storefront' });
	expect(plan.error).toBeUndefined();
	expect(plan.result?.structuredContent?.contentHint).toBe('storefront');
	expect((plan.result?.structuredContent?.steps as unknown[] | undefined)?.length).toBeGreaterThan(0);

	// 3. Compare against the same source — no drift expected.
	const compare = await call('design_compare', { url: designUrl, snapshot: analysis });
	expect(compare.error).toBeUndefined();
	expect(compare.result?.structuredContent?.summary).toBe('no changes');

	// 4. Emit + create the runtime theme (manager write) with a passing audit.
	const theme = await call('design_theme', { url: designUrl, name: 'imported-e2e-demo' });
	expect(theme.error).toBeUndefined();
	expect(theme.result?.structuredContent?.applied).toBe(true);
	expect((theme.result?.structuredContent?.audit as { passed?: boolean } | undefined)?.passed).toBe(true);

	// 5. Verify the theme exists with the imported tokens (theme_get reads the
	// descriptor — no shared active-theme state to race the parallel specs).
	const descriptor = await call('theme_get', { name: 'imported-e2e-demo' });
	expect(descriptor.error).toBeUndefined();
	expect((descriptor.result?.structuredContent as { tokens?: Record<string, string> } | undefined)?.tokens?.accent).toBe(
		'#0f6b4f'
	);

	// 6. Apply, roll back via the explicit snapshot_id (parallel-safe), clean up.
	const applied = await call('theme_apply', { name: 'imported-e2e-demo' });
	expect(applied.error).toBeUndefined();
	const snapshotId = (applied.result?.structuredContent as { snapshot_id?: string } | undefined)?.snapshot_id;
	expect(snapshotId).toBeDefined();

	const rollback = await call('tokens_rollback', { snapshot_id: snapshotId });
	expect(rollback.error).toBeUndefined();

	const cleanup = await call('theme_delete', { name: 'imported-e2e-demo' });
	expect(cleanup.error).toBeUndefined();
});
