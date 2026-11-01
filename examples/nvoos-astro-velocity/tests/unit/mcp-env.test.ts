// ---------------------------------------------------------------------------
// mcp-env.test.ts — the BFF /mcp env seeding rule. Regression (2026-10-31):
// the CI smoke step boots the BFF with only NVOOS_TARGET_SITE and Playwright
// reuses that server, so the playwright.config.ts NVOOS_SSR_API_ORIGIN never
// applies — the manager-session gate then has no contract base to verify
// against and every manager write 401s. seedMcpEnv must derive the contract
// base from the proxy target, exactly the value the /api proxy itself uses.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { seedMcpEnv } from '../../scripts/lib/mcp-env.mjs';

describe('seedMcpEnv', () => {
	it('derives the contract base from NVOOS_TARGET_SITE when no base is configured', () => {
		expect(seedMcpEnv({ NVOOS_TARGET_SITE: 'http://localhost:8123' })).toEqual({
			NVOOS_TARGET_SITE: 'http://localhost:8123',
			NVOOS_SSR_API_ORIGIN: 'http://localhost:8123/wp-json/nvoos-commerce/v1',
		});
	});

	it('strips a trailing slash from the proxy target', () => {
		expect(seedMcpEnv({ NVOOS_TARGET_SITE: 'https://backend.example.com/' })).toMatchObject({
			NVOOS_SSR_API_ORIGIN: 'https://backend.example.com/wp-json/nvoos-commerce/v1',
		});
	});

	it('lets an explicit NVOOS_SSR_API_ORIGIN win', () => {
		const env = seedMcpEnv({
			NVOOS_TARGET_SITE: 'http://localhost:8123',
			NVOOS_SSR_API_ORIGIN: 'http://localhost:8123/wp-json/nvoos-commerce/v1',
		});
		expect(env.NVOOS_SSR_API_ORIGIN).toBe('http://localhost:8123/wp-json/nvoos-commerce/v1');
	});

	it('lets an explicit NVOOS_API_BASE win (verbatim tool base semantics)', () => {
		expect(
			seedMcpEnv({
				NVOOS_TARGET_SITE: 'http://localhost:8123',
				NVOOS_API_BASE: 'http://localhost:8123/wp-json/nvoos-commerce/v1',
			})
		).toEqual({
			NVOOS_TARGET_SITE: 'http://localhost:8123',
			NVOOS_API_BASE: 'http://localhost:8123/wp-json/nvoos-commerce/v1',
		});
	});

	it('leaves the snapshot unseeded without a proxy target', () => {
		expect(seedMcpEnv({})).toEqual({});
	});

	it('returns a fresh object (serve.mjs mutates process.env after boot)', () => {
		const input = { NVOOS_TARGET_SITE: 'http://localhost:8123' };
		const seeded = seedMcpEnv(input);
		expect(seeded).not.toBe(input);
		input.NVOOS_TARGET_SITE = 'http://mutated.example';
		expect(seeded.NVOOS_SSR_API_ORIGIN).toBe('http://localhost:8123/wp-json/nvoos-commerce/v1');
	});
});
