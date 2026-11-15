// ---------------------------------------------------------------------------
// shop.ts — the shop/checkout configuration tool slice (Proposal 108
// DECISION-1). shop_get reads the shop globals (checkout/cart/return URLs,
// currency, product source, payment-method hints); shop_update PATCHes the
// editable fields through the bridge with the manager-session gate, the
// per-session write quota, and the same-site URL validation the bridge
// enforces server-side (H1 — the backend is the authority).
// ---------------------------------------------------------------------------

import { createHash } from 'node:crypto';
import { McpServer } from '@modelcontextprotocol/server';
import { z } from 'zod';
import { getMcpRequestContext } from '../context';
import {
	envValue,
	errorResult,
	READ_ONLY_TOOL,
	resolveApiBase,
	text,
	WRITE_TOOL,
} from './util';
import { joinPath } from '../../src/lib/commerce/client';

const MANAGER_REQUIRED = 'shop writes require a manager session cookie';
const NO_BASE = 'commerce base is not configured (set NVOOS_API_BASE or NVOOS_SSR_API_ORIGIN)';

/** PATCHable shop fields — the bridge rejects anything else (108 §2). */
const shopPatchSchema = z
	.object({
		checkoutUrl: z.string().url().optional(),
		cartUrl: z.string().url().optional(),
		returnUrl: z.string().url().optional(),
		currency: z.string().regex(/^[A-Za-z]{3}$/).optional(),
		currencySymbol: z.string().min(1).max(8).optional(),
	})
	.refine((data) => Object.keys(data).length > 0, {
		message: 'at least one shop field is required',
	});

function managerCookie(): string | null {
	return getMcpRequestContext()?.cookie ?? null;
}

// Per-session write quota (env-tunable, same pattern as the content slice).
const writeWindows = new Map<string, { count: number; windowStart: number }>();

function sessionKey(cookie: string): string {
	return createHash('sha256').update(cookie).digest('hex');
}

function quotaExceeded(cookie: string): string | null {
	const max = Number(envValue('NVOOS_MCP_SHOP_WRITE_MAX'));
	const windowMs = Number(envValue('NVOOS_MCP_SHOP_WRITE_WINDOW_MS'));
	const limit = Number.isFinite(max) && max > 0 ? max : 20;
	const windowLength = Number.isFinite(windowMs) && windowMs > 0 ? windowMs : 10 * 60 * 1000;
	const now = Date.now();
	const key = sessionKey(cookie);
	const window = writeWindows.get(key);
	if (!window || window.windowStart + windowLength <= now) {
		writeWindows.set(key, { count: 1, windowStart: now });
		return null;
	}
	window.count += 1;
	if (window.count > limit) {
		return `shop write quota exceeded (${limit} writes per ${Math.round(windowLength / 60000)} min)`;
	}
	return null;
}

/** GET /globals/shop — read-only, open (the same shape the app consumes). */
async function shopGet(): Promise<ReturnType<typeof text> | ReturnType<typeof errorResult>> {
	const base = resolveApiBase();
	if (!base) {
		return errorResult(NO_BASE, { error: 'no_commerce_base' });
	}
	let res: Response;
	try {
		res = await fetch(joinPath(base, 'globals/shop'), {
			headers: { accept: 'application/json' },
		});
	} catch (error) {
		return errorResult(`shop_get failed: ${(error as Error).message}`, {
			error: 'request_failed',
		});
	}
	const payload = (await res.json().catch(() => null)) as unknown;
	if (!res.ok) {
		return errorResult(`shop_get failed: backend responded ${res.status}`, {
			error: 'backend_error',
			status: res.status,
			payload,
		});
	}
	return text(JSON.stringify(payload, null, 2), payload);
}

/** PATCH /globals/shop — manager-gated, quota'd, backend-validated. */
async function shopUpdate(args: {
	checkoutUrl?: string;
	cartUrl?: string;
	returnUrl?: string;
	currency?: string;
	currencySymbol?: string;
}): Promise<ReturnType<typeof text> | ReturnType<typeof errorResult>> {
	const cookie = managerCookie();
	if (!cookie) {
		return errorResult(MANAGER_REQUIRED, { error: 'manager_session_required' });
	}
	const quotaMessage = quotaExceeded(cookie);
	if (quotaMessage) {
		return errorResult(quotaMessage, { error: 'quota_exceeded' });
	}
	const base = resolveApiBase();
	if (!base) {
		return errorResult(NO_BASE, { error: 'no_commerce_base' });
	}
	let res: Response;
	try {
		res = await fetch(joinPath(base, 'globals/shop'), {
			method: 'PATCH',
			headers: {
				accept: 'application/json',
				'content-type': 'application/json',
				cookie,
			},
			body: JSON.stringify(args),
		});
	} catch (error) {
		return errorResult(`shop_update failed: ${(error as Error).message}`, {
			error: 'request_failed',
		});
	}
	const payload = (await res.json().catch(() => null)) as unknown;
	if (!res.ok) {
		return errorResult(`shop_update failed: backend responded ${res.status}`, {
			error: 'backend_error',
			status: res.status,
			payload,
		});
	}
	return text(JSON.stringify(payload, null, 2), payload);
}

export function registerShopTools(server: McpServer): void {
	server.registerTool(
		'shop_get',
		{
			title: 'Read shop globals',
			description:
				'Read the storefront shop globals: checkout/cart/return URLs, display currency, the product source mode (native|woo|both) and the enabled payment-method hints. Read-only.',
			annotations: READ_ONLY_TOOL,
		},
		async () => shopGet()
	);

	server.registerTool(
		'shop_update',
		{
			title: 'Update shop globals',
			description:
				'Update the editable shop globals (checkoutUrl, cartUrl, returnUrl, currency, currencySymbol). Requires a manager session; the backend enforces same-site/allowlisted URLs and rejects anything else.',
			annotations: WRITE_TOOL,
			inputSchema: shopPatchSchema,
		},
		async (args) => shopUpdate(args as Parameters<typeof shopUpdate>[0])
	);
}
