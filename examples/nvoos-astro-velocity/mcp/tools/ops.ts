// ---------------------------------------------------------------------------
// ops.ts — nvoos-astro-mcp operational tool slice (Proposal 095 §3.3,
// Phase 3/4.3): storefront status, preview URLs, the mirror rebuild trigger
// and the opt-in screenshot tool. Write-gated tools are double-checked here
// too (defense in depth behind the BFF auth gate).
// ---------------------------------------------------------------------------

import type { McpServer } from '@modelcontextprotocol/server';
import { z } from 'zod';
import { renderOrigin } from '../../src/lib/images';
import type { TokenService } from '../../src/lib/tokens/service';
import { designService } from './design';
import { envValue, errorResult, image, resolveApiBase, text } from './util';

/** Storefront origin used by preview/screenshot URLs (no request URL here). */
function storefrontOrigin(): string {
	return renderOrigin(undefined, undefined);
}

/** Runtime (non-static, non-mode) theme count from the token service. */
function runtimeThemeCount(service: TokenService): number {
	const staticThemes = new Set(
		Object.keys(service.themes()).filter((name) => name !== 'default')
	);
	return service
		.listThemes()
		.filter(
			(name) => name !== 'light' && name !== 'dark' && !staticThemes.has(name)
		).length;
}

export function registerOpsTools(server: McpServer, service: TokenService = designService): void {
	server.registerTool(
		'site_status',
		{
			description:
				'Storefront operational status: commerce-contract reachability, active theme, runtime theme count, and opt-in capability flags. Read-only.',
			inputSchema: z.object({}),
		},
		async () => {
			const base = resolveApiBase();
			let contract: { ok: boolean; status: number | null; message?: string };
			if (!base) {
				contract = {
					ok: false,
					status: null,
					message: 'no commerce base configured (NVOOS_API_BASE or NVOOS_SSR_API_ORIGIN)',
				};
			} else {
				try {
					const res = await fetch(`${base}/products?limit=1`, {
						headers: { accept: 'application/json' },
					});
					contract = { ok: res.ok, status: res.status };
				} catch (error) {
					contract = { ok: false, status: null, message: (error as Error).message };
				}
			}
			const payload = {
				contract_reachable: contract,
				active_theme: service.getActiveTheme(),
				runtime_themes: runtimeThemeCount(service),
				screenshot_enabled: envValue('NVOOS_MCP_SCREENSHOT') === '1',
				rebuild_webhook_configured: Boolean(envValue('NVOOS_REBUILD_WEBHOOK_URL')),
			};
			return text(JSON.stringify(payload, null, 2), payload);
		}
	);

	server.registerTool(
		'preview_url',
		{
			description:
				'Render a storefront preview URL for a path (path-only input, must start with /). Read-only.',
			inputSchema: z.object({ path: z.string() }),
		},
		async (args) => {
			if (!args.path.startsWith('/')) {
				return errorResult('preview_url requires an absolute path starting with /', {
					error: 'invalid_path',
					path: args.path,
				});
			}
			const url = storefrontOrigin() + args.path;
			return text(JSON.stringify({ url }, null, 2), { url });
		}
	);

	server.registerTool(
		'rebuild_trigger',
		{
			description:
				'Trigger the mirror rebuild webhook (POST {} to NVOOS_REBUILD_WEBHOOK_URL, optional NVOOS_REBUILD_WEBHOOK_TOKEN as a bearer credential). Write-gated.',
			inputSchema: z.object({}),
		},
		async () => {
			const webhook = envValue('NVOOS_REBUILD_WEBHOOK_URL');
			if (!webhook) {
				return errorResult(
					'rebuild webhook is not configured (set NVOOS_REBUILD_WEBHOOK_URL)',
					{ error: 'not_configured' }
				);
			}
			const token = envValue('NVOOS_REBUILD_WEBHOOK_TOKEN');
			let res: Response;
			try {
				res = await fetch(webhook, {
					method: 'POST',
					headers: {
						accept: 'application/json',
						'content-type': 'application/json',
						...(token ? { authorization: `Bearer ${token}` } : {}),
					},
					body: '{}',
				});
			} catch (error) {
				return errorResult(`rebuild webhook failed: ${(error as Error).message}`, {
					error: 'webhook_error',
				});
			}
			const payload = { ok: res.ok, status: res.status };
			if (!res.ok) {
				return errorResult(`rebuild webhook responded ${res.status}`, {
					...payload,
					error: 'webhook_error',
				});
			}
			return text(JSON.stringify(payload, null, 2), payload);
		}
	);

	server.registerTool(
		'screenshot',
		{
			description:
				'Capture a PNG screenshot of a storefront path (opt-in via NVOOS_MCP_SCREENSHOT=1; path-only SSRF-safe input, must start with /). Write-gated.',
			inputSchema: z.object({
				path: z.string(),
				width: z.number().int().min(320).max(2560).optional(),
			}),
		},
		async (args) => {
			if (envValue('NVOOS_MCP_SCREENSHOT') !== '1') {
				return errorResult(
					'screenshot tool is disabled (set NVOOS_MCP_SCREENSHOT=1 to opt in)',
					{ error: 'disabled' }
				);
			}
			if (!args.path.startsWith('/')) {
				return errorResult('screenshot requires an absolute path starting with /', {
					error: 'invalid_path',
					path: args.path,
				});
			}
			const url = storefrontOrigin() + args.path;
			const width = args.width ?? 1280;
			// Lazy import — playwright must never load for tools/list or unit tests.
			const { chromium } = await import('playwright');
			const browser = await chromium.launch({ headless: true });
			try {
				const page = await browser.newPage();
				await page.setViewportSize({ width, height: 720 });
				await page.goto(url, { waitUntil: 'networkidle', timeout: 15000 });
				const shot = await page.screenshot({ fullPage: false });
				return image(shot.toString('base64'), 'image/png');
			} finally {
				await browser.close();
			}
		}
	);
}
