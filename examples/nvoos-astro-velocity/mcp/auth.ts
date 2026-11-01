// ---------------------------------------------------------------------------
// auth.ts — nvoos-astro-mcp authorization (Proposal 095 §3.3).
//
// Pure helpers, injectable fetcher for tests. Write tools are double-gated:
// bearer NVOOS_MCP_TOKEN (timing-safe) OR a valid manager session on the
// commerce contract (canManage). Fail closed: no token + no session = deny.
// ---------------------------------------------------------------------------

import { timingSafeEqual } from 'node:crypto';

/** Tool names that mutate storefront state (write gate). */
export const WRITE_TOOLS = new Set([
	'tokens_set',
	'tokens_import_dtcg',
	'tokens_rollback',
	'theme_create',
	'theme_apply',
	'theme_delete',
	'design_theme',
	'cms_create',
	'cms_update',
	'cms_delete',
	'media_upload',
	'rebuild_trigger',
	'screenshot',
]);

export interface AuthContext {
	ok: boolean;
	message?: string;
	manager?: boolean;
	bearer?: boolean;
}

export interface AuthEnv {
	NVOOS_MCP_TOKEN?: string;
	NVOOS_SSR_API_ORIGIN?: string;
	NVOOS_API_BASE?: string;
}

export function constantTimeEqual(a: string, b: string): boolean {
	const bufA = Buffer.from(a, 'utf8');
	const bufB = Buffer.from(b, 'utf8');
	if (bufA.length !== bufB.length) return false;
	return timingSafeEqual(bufA, bufB);
}

export function verifyBearer(authHeader: string | undefined, expected: string | undefined): boolean {
	if (!expected) return false;
	if (!authHeader) return false;
	const match = /^Bearer\s+(.+)$/i.exec(authHeader.trim());
	if (!match) return false;
	return constantTimeEqual(match[1].trim(), expected);
}

export async function verifyManagerSession(
	cookie: string | undefined,
	apiBase: string | undefined,
	fetcher: typeof fetch = fetch
): Promise<boolean> {
	if (!cookie || !apiBase) return false;
	try {
		const response = await fetcher(`${apiBase.replace(/\/+$/, '')}/auth/me`, {
			headers: { cookie, accept: 'application/json' },
		});
		if (!response.ok) return false;
		const payload = (await response.json()) as { canManage?: boolean };
		return payload.canManage === true;
	} catch {
		return false;
	}
}

export function firstHeaderValue(value: string | string[] | undefined): string | undefined {
	if (Array.isArray(value)) return value[0];
	return value;
}

/**
 * @param body parsed JSON-RPC body (undefined for GET/SSE requests).
 */
export function isWriteToolCall(body: unknown): boolean {
	if (!body || typeof body !== 'object') return false;
	const message = body as { method?: string; params?: { name?: string } };
	if (message.method !== 'tools/call') return false;
	const name = message.params?.name;
	return typeof name === 'string' && WRITE_TOOLS.has(name);
}

export async function authorizeRequest(
	req: { headers: Record<string, string | string[] | undefined> },
	body: unknown,
	env: AuthEnv,
	fetcher: typeof fetch = fetch
): Promise<AuthContext> {
	if (!isWriteToolCall(body)) {
		return { ok: true };
	}
	const bearer = verifyBearer(firstHeaderValue(req.headers['authorization']), env.NVOOS_MCP_TOKEN);
	if (bearer) {
		return { ok: true, bearer: true };
	}
	const manager = await verifyManagerSession(
		firstHeaderValue(req.headers['cookie']),
		env.NVOOS_SSR_API_ORIGIN ?? env.NVOOS_API_BASE,
		fetcher
	);
	if (manager) {
		return { ok: true, manager: true };
	}
	return {
		ok: false,
		message:
			'write tools require a valid NVOOS_MCP_TOKEN bearer token or a manager session (manage_astro)',
	};
}
