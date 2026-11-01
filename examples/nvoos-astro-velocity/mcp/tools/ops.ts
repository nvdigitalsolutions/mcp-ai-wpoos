// ---------------------------------------------------------------------------
// ops.ts — nvoos-astro-mcp operational tool slice (Proposal 095 §3.3,
// Phase 3/4.3): storefront status, preview URLs, the mirror rebuild trigger
// and the opt-in screenshot tool. Write-gated tools are double-checked here
// too (defense in depth behind the BFF auth gate).
// ---------------------------------------------------------------------------

import type { McpServer } from '@modelcontextprotocol/server';
import { z } from 'zod';
import type { TokenService } from '../../src/lib/tokens/service';
import { getMcpRequestContext } from '../context';
import { designService } from './design';
import {
	IDEMPOTENT_WRITE_TOOL,
	READ_ONLY_TOOL,
	envValue,
	errorResult,
	image,
	resolveApiBase,
	text,
} from './util';

/**
 * ADS semantic token → NDS token mapping (Proposal 095 §4.2 round-trip).
 * Emits the NDS DTCG exporter shape: group → token-id → { $type, $value },
 * so the output feeds the NDS importer (addons/nvoos-design-system,
 * class-nvoos-nds-dtcg-importer.php) with no transformation.
 */
const ADS_TO_NDS: Array<{ ads: string; group: string; id: string; dtcgType: string }> = [
	{ ads: 'surface', group: 'colors', id: 'color_surface', dtcgType: 'color' },
	{ ads: 'surface-alt', group: 'colors', id: 'color_surface_hover', dtcgType: 'color' },
	{ ads: 'ink', group: 'colors', id: 'color_text_primary', dtcgType: 'color' },
	{ ads: 'muted', group: 'colors', id: 'color_text_secondary', dtcgType: 'color' },
	{ ads: 'line', group: 'colors', id: 'color_border', dtcgType: 'color' },
	{ ads: 'accent', group: 'colors', id: 'color_accent', dtcgType: 'color' },
	{ ads: 'radius', group: 'borders', id: 'radius_md', dtcgType: 'dimension' },
	{ ads: 'font', group: 'typography', id: 'font_family', dtcgType: 'fontFamily' },
	{ ads: 'space', group: 'spacing', id: 'space_md', dtcgType: 'dimension' },
];

/** ADS semantic tokens with no NDS equivalent (reported, never dropped). */
const ADS_UNMAPPED: Array<{ ads: string; reason: string }> = [
	{ ads: 'accent-ink', reason: 'NDS has no on-accent text token' },
	{ ads: 'danger', reason: 'NDS has no error/danger token (color_warning is not an error)' },
	{ ads: 'focus-ring', reason: 'NDS has no composite shadow token' },
];

/**
 * The storefront's own origin for preview/screenshot URLs. Precedence:
 * an explicit NVOOS_STOREFRONT_ORIGIN env (http/https only, normalised),
 * else the inbound request origin carried in the MCP request context
 * (scheme + Host — set by the BFF, absent in stdio mode), else the local
 * dev default. Exported for unit tests.
 */
export function storefrontOrigin(): string {
	const configured = envValue('NVOOS_STOREFRONT_ORIGIN');
	if (configured) {
		try {
			const url = new URL(configured.trim());
			if (url.protocol === 'http:' || url.protocol === 'https:') {
				return url.origin;
			}
		} catch {
			// Fall through to the request origin / default.
		}
	}
	return getMcpRequestContext()?.origin ?? 'http://localhost:4321';
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
			title: 'Storefront status',
			description:
				'Storefront operational status: commerce-contract reachability, active theme, runtime theme count, and opt-in capability flags. Read-only.',
			annotations: READ_ONLY_TOOL,
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
			title: 'Storefront preview URL',
			description:
				'Render a storefront preview URL for a path (path-only input, must start with /). Read-only.',
			annotations: READ_ONLY_TOOL,
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
		'nds_export',
		{
			title: 'Export NDS tokens',
			description:
				'Export the active ADS semantic tokens as an NV oOS Design System (NDS) DTCG document (group to token-id to $type/$value — the NDS exporter shape) for the Proposal 095 4.2 round-trip. Tokens without an NDS equivalent are reported, never silently dropped. Read-only.',
			annotations: READ_ONLY_TOOL,
			inputSchema: z.object({}),
		},
		async () => {
			const resolved = service.resolveActiveSemantic();
			const doc: Record<string, Record<string, { $type: string; $value: string }>> = {};
			const imported: string[] = [];
			const unmapped: Array<{ ads: string; reason: string }> = [];
			for (const { ads, group, id, dtcgType } of ADS_TO_NDS) {
				const value = resolved[ads];
				if (value === undefined) {
					unmapped.push({ ads, reason: 'not present in the active semantic set' });
					continue;
				}
				doc[group] ??= {};
				doc[group][id] = { $type: dtcgType, $value: value };
				imported.push(`${ads} -> ${group}.${id}`);
			}
			for (const entry of ADS_UNMAPPED) {
				unmapped.push(entry);
			}
			const payload = { nds_version: '0.2.0', doc, imported, unmapped };
			return text(JSON.stringify(payload, null, 2), payload);
		}
	);

	server.registerTool(
		'rebuild_trigger',
		{
			title: 'Trigger mirror rebuild',
			description:
				'Trigger the mirror rebuild webhook (POST {} to NVOOS_REBUILD_WEBHOOK_URL, optional NVOOS_REBUILD_WEBHOOK_TOKEN as a bearer credential). Write-gated.',
			annotations: IDEMPOTENT_WRITE_TOOL,
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
			title: 'Storefront screenshot',
			description:
				'Capture a PNG screenshot of a storefront path (opt-in via NVOOS_MCP_SCREENSHOT=1; path-only SSRF-safe input, must start with /). Write-gated.',
			annotations: READ_ONLY_TOOL,
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
