// ---------------------------------------------------------------------------
// storefront-origin.test.ts — storefrontOrigin() resolution precedence
// (2026-10-31). Regression: preview_url / screenshot always returned
// http://localhost:4321 — the resolver never saw the request or any env.
// Precedence is now: NVOOS_STOREFRONT_ORIGIN → request-context origin
// (scheme + Host, set by the BFF) → local dev default.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { mcpRequestContext } from '../../mcp/context';
import { storefrontOrigin } from '../../mcp/tools/ops';

describe('storefrontOrigin', () => {
	it('falls back to the local dev origin without env or request context', async () => {
		await mcpRequestContext.run({ env: {} }, async () => {
			expect(storefrontOrigin()).toBe('http://localhost:4321');
		});
	});

	it('uses the request origin carried in the MCP request context', async () => {
		await mcpRequestContext.run(
			{ env: {}, origin: 'https://astro.nvoos.cloud' },
			async () => {
				expect(storefrontOrigin()).toBe('https://astro.nvoos.cloud');
			}
		);
	});

	it('lets an explicit NVOOS_STOREFRONT_ORIGIN win over the request origin', async () => {
		await mcpRequestContext.run(
			{
				env: { NVOOS_STOREFRONT_ORIGIN: 'https://store.example/' },
				origin: 'https://ctx.example',
			},
			async () => {
				expect(storefrontOrigin()).toBe('https://store.example');
			}
		);
	});

	it('ignores malformed or non-http(s) NVOOS_STOREFRONT_ORIGIN values', async () => {
		await mcpRequestContext.run(
			{ env: { NVOOS_STOREFRONT_ORIGIN: 'not-a-url' }, origin: 'https://ctx.example' },
			async () => {
				expect(storefrontOrigin()).toBe('https://ctx.example');
			}
		);
		await mcpRequestContext.run(
			{ env: { NVOOS_STOREFRONT_ORIGIN: 'ftp://store.example' } },
			async () => {
				expect(storefrontOrigin()).toBe('http://localhost:4321');
			}
		);
	});
});
