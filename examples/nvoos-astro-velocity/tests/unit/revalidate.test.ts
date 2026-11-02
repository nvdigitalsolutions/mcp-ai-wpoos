// revalidate.test.ts — Proposal 104 Q3: the /internal/revalidate route.
// Fail-closed auth: unset token 503, bad token 401, good token purges the
// requested tags (defaulting to the products tag set).
import { afterEach, describe, expect, it, vi } from 'vitest';

const { POST } = await import('../../src/pages/internal/revalidate');

const TOKEN = 'test-revalidate-secret';

function requestWith(authorization: string | null, body?: unknown): Request {
	return new Request('http://localhost/internal/revalidate', {
		method: 'POST',
		headers: authorization ? { authorization } : {},
		body: body === undefined ? undefined : JSON.stringify(body),
	});
}

function fakeCache(overrides: Partial<{ enabled: boolean; invalidate: () => Promise<void> }> = {}) {
	return {
		enabled: overrides.enabled ?? true,
		invalidate: overrides.invalidate ?? vi.fn(async () => {}),
		set: vi.fn(),
	};
}

afterEach(() => {
	delete process.env.NVOOS_REVALIDATE_TOKEN;
});

describe('POST /internal/revalidate', () => {
	it('503s when no token is configured (fail closed)', async () => {
		const res = await POST({
			request: requestWith(null),
			cache: fakeCache(),
		} as never);
		expect(res.status).toBe(503);
	});

	it('401s on a missing or wrong bearer', async () => {
		process.env.NVOOS_REVALIDATE_TOKEN = TOKEN;
		for (const auth of [null, 'Bearer wrong', 'Token ' + TOKEN]) {
			const res = await POST({
				request: requestWith(auth),
				cache: fakeCache(),
			} as never);
			expect(res.status).toBe(401);
		}
	});

	it('purges the requested tags with a valid bearer', async () => {
		process.env.NVOOS_REVALIDATE_TOKEN = TOKEN;
		const cache = fakeCache();
		const res = await POST({
			request: requestWith(`Bearer ${TOKEN}`, { tags: ['products', 'product-42'] }),
			cache,
		} as never);
		expect(res.status).toBe(200);
		expect(await res.json()).toEqual({ purged: ['products', 'product-42'] });
		expect(cache.invalidate).toHaveBeenCalledWith({ tags: ['products', 'product-42'] });
	});

	it('defaults to the products tag set without a body', async () => {
		process.env.NVOOS_REVALIDATE_TOKEN = TOKEN;
		const cache = fakeCache();
		const res = await POST({
			request: requestWith(`Bearer ${TOKEN}`, {}),
			cache,
		} as never);
		expect(res.status).toBe(200);
		expect(await res.json()).toEqual({ purged: ['products'] });
		expect(cache.invalidate).toHaveBeenCalledWith({ tags: ['products'] });
	});

	it('503s when the route cache is disabled', async () => {
		process.env.NVOOS_REVALIDATE_TOKEN = TOKEN;
		const res = await POST({
			request: requestWith(`Bearer ${TOKEN}`),
			cache: fakeCache({ enabled: false }),
		} as never);
		expect(res.status).toBe(503);
	});
});
