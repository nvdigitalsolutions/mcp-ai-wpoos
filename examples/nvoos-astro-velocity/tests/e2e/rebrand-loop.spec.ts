// ---------------------------------------------------------------------------
// rebrand-loop.spec.ts — the assistant-style rebrand loop driven from a real
// browser (Proposal 095 §3.3 / issue #7319). Two tests:
//   1. The /manage Design System tab: manager sign-in against the fixture
//      auth surface, the gate mounts the admin bundle, the tab renders the
//      token table and applies a theme — every call goes through /mcp with
//      the manager cookie (the real auth path, same-origin Origin).
//   2. The raw browser loop over /mcp: tokens_get → dry-run → apply →
//      verify → contrast_check → rollback → cms_list — the exact assistant
//      sequence, plus the same-origin Origin pass-through the browser sends.
// ---------------------------------------------------------------------------
import { expect, test } from '@playwright/test';

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:4321';

/** The Design tab's wire shape — mirrors addons/nvoos-astro-bridge/src/lib/mcp-client.ts. */
function mcpBody(name: string, args: Record<string, unknown> = {}): string {
	return JSON.stringify({
		jsonrpc: '2.0',
		id: 'e2e-1',
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

test('manager session drives the /manage Design System tab over /mcp', async ({ page }) => {
	// Sign in through the contract auth surface (sets the session cookie in
	// the shared browser context) and grant the manager flag.
	const login = await page.request.post(`${BASE}/api/auth/login`, { data: {} });
	expect(login.ok()).toBe(true);
	await page.context().addCookies([{ name: 'nvoos_fixture_manager', value: '1', url: BASE }]);

	// The gate's probe must see a manager before the bundle mounts.
	const me = await page.request.get(`${BASE}/api/auth/me`);
	expect((await me.json()).canManage).toBe(true);

	await page.goto(`${BASE}/manage/`);
	await expect(page.locator('nav[aria-label="Astro CMS"]')).toBeVisible();
	await page.getByRole('button', { name: 'Design System' }).click();

	// Token table renders from tokens_get (exact cell — 'accent-ink' must not
	// satisfy the 'ink' substring match).
	await expect(page.locator('td', { hasText: /^ink$/ })).toBeVisible();

	// Apply the brand theme via the tab (manager-cookie write over /mcp).
	await page.getByRole('button', { name: /example-brand/ }).click();
	await expect(page.locator('text=active theme is now example-brand')).toBeVisible();
});

test('assistant rebrand loop over /mcp from a real browser', async ({ page }) => {
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
				structuredContent?: {
					active_theme?: string;
					semantic?: Record<string, string>;
					applied?: boolean;
					ratio?: number;
					aaNormal?: boolean;
					totalDocs?: number;
				};
				content?: Array<{ text?: string }>;
			};
		};
	};

	// Read → dry-run → apply → verify (order-independent: the parallel
	// /manage test may have switched the active theme).
	const before = await call('tokens_get', {});
	expect(before.error).toBeUndefined();
	const beforeTheme = before.result?.structuredContent?.active_theme;
	// Any valid theme name — the parallel design-import spec creates runtime
	// themes too, so the active theme is shared state.
	expect(beforeTheme).toMatch(/^[a-z0-9-]+$/);
	const beforeAccent = before.result?.structuredContent?.semantic?.accent;
	expect(beforeAccent).toMatch(/^#/);

	const dryRun = await call('tokens_set', { changes: { accent: '#1d4ed8' }, dry_run: true });
	expect(dryRun.result?.structuredContent?.applied).toBe(false);

	const applied = await call('tokens_set', { changes: { accent: '#1d4ed8' } });
	expect(applied.result?.structuredContent?.applied).toBe(true);
	const snapshotId = (applied.result?.structuredContent as { snapshot_id?: string } | undefined)?.snapshot_id;
	expect(snapshotId).toBeDefined();

	const after = await call('tokens_get', {});
	expect(after.result?.structuredContent?.semantic?.accent).toBe('#1d4ed8');

	// Contrast must hold AA on the proposed pair.
	const contrast = await call('contrast_check', { fg: '#ffffff', bg: '#1d4ed8' });
	expect(contrast.result?.structuredContent?.aaNormal).toBe(true);

	// Rollback restores the value captured before the apply — the explicit
	// snapshot_id makes this immune to parallel specs interleaving writes.
	const rollback = await call('tokens_rollback', { snapshot_id: snapshotId });
	expect(rollback.error).toBeUndefined();
	const restored = await call('tokens_get', {});
	expect(restored.result?.structuredContent?.semantic?.accent).toBe(beforeAccent);

	// The CMS slice reads the catalog through the same endpoint.
	const products = await call('cms_list', { collection: 'products', limit: 5 });
	expect(products.result?.structuredContent?.totalDocs).toBeGreaterThan(0);
});
