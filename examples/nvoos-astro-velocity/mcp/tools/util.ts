// ---------------------------------------------------------------------------
// util.ts — shared MCP tool helpers (Proposal 095 §3.3).
//
// text() / image() / errorResult() build SDK tool-call results in the
// canonical CallToolResult shape. resolveApiBase() and envValue() read the
// per-request ambient context (mcp/context.ts) so tool handlers never reach
// for process globals directly (stdio mode falls back to process.env).
// ---------------------------------------------------------------------------

import { getMcpRequestContext } from '../context';
import { resolveBase } from '../../src/lib/commerce/client';

/** Text tool result — plain-text content plus an optional structured payload. */
export function text(content: string, structured?: unknown) {
	return {
		content: [{ type: 'text' as const, text: content }],
		...(structured !== undefined ? { structuredContent: structured } : {}),
	};
}

/** Image tool result — MCP image content block (base64 payload). */
export function image(data: string, mimeType: string) {
	return {
		content: [{ type: 'image' as const, data, mimeType }],
	};
}

/** Typed error tool result — isError: true so the SDK flags the call. */
export function errorResult(message: string, structured?: unknown) {
	return { ...text(message, structured), isError: true };
}

/** One env key from the per-request context (process.env in stdio mode). */
export function envValue(key: string): string | undefined {
	const ctx = getMcpRequestContext();
	return ctx ? ctx.env[key] : process.env[key];
}

/**
 * Resolve the commerce-contract base for tool calls.
 *
 * Precedence: NVOOS_SSR_API_ORIGIN (the storefront BFF origin — resolved
 * through the commerce client's resolveBase, which appends the same-origin
 * /api contract path), else NVOOS_API_BASE used verbatim (a full contract
 * base URL, e.g. the hermetic fixture server). Null when neither is set.
 */
export function resolveApiBase(): string | null {
	const ssrOrigin = envValue('NVOOS_SSR_API_ORIGIN');
	if (ssrOrigin) {
		try {
			return resolveBase(ssrOrigin);
		} catch {
			return null;
		}
	}
	const apiBase = envValue('NVOOS_API_BASE');
	return apiBase ? apiBase.replace(/\/+$/, '') : null;
}
