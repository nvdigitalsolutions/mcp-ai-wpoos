import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import {
	buildCollectionQuery,
	buildWhereParams,
	getCollection,
	getCollectionOrNull,
	getAll,
	getShop,
	getThemePolicy,
	MAX_LIMIT,
} from '../../src/lib/commerce/client';
import type { Product, SortValue } from '../../src/lib/commerce/types';

describe('buildWhereParams', () => {
	it('serializes equals operators', () => {
		expect(buildWhereParams({ category: { equals: 5 } })).toBe(
			'where%5Bcategory%5D%5Bequals%5D=5'
		);
	});

	it('serializes in operators with repeated params', () => {
		expect(buildWhereParams({ colors: { in: [1, 2] } })).toBe(
			'where%5Bcolors%5D%5Bin%5D=1&where%5Bcolors%5D%5Bin%5D=2'
		);
	});

	it('skips empty values', () => {
		expect(buildWhereParams({ name: { like: '' }, brand: { equals: 3 } })).toBe(
			'where%5Bbrand%5D%5Bequals%5D=3'
		);
	});

	it('returns empty string for no filters', () => {
		expect(buildWhereParams({})).toBe('');
	});
});

describe('buildCollectionQuery', () => {
	it('omits page 1 and caps limit at the bridge maximum', () => {
		expect(buildCollectionQuery({ page: 1, limit: 500 })).toBe(
			`limit=${MAX_LIMIT}`
		);
	});

	it('includes page, sort and where together', () => {
		const query = buildCollectionQuery({
			page: 2,
			limit: 24,
			sort: '-price',
			where: { category: { equals: 1 } },
		});
		expect(query).toContain('page=2');
		expect(query).toContain('limit=24');
		expect(query).toContain('sort=-price');
		expect(query).toContain('where%5Bcategory%5D%5Bequals%5D=1');
	});
});

describe('getCollection / getAll', () => {
	const requestUrl = new URL('https://store.example/categories/1/sofas/');

	beforeEach(() => {
		vi.unstubAllGlobals();
	});

	it('returns and validates the envelope', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn(async () =>
				new Response(
					JSON.stringify({
						docs: [{ id: 1 }],
						totalDocs: 1,
						limit: 24,
						totalPages: 1,
						page: 1,
						hasNextPage: false,
						hasPrevPage: false,
					}),
					{ status: 200 }
				)
			)
		);
		const data = await getCollection<{ id: number }>('products', requestUrl, {
			page: 1,
			limit: 24,
		});
		expect(data.docs).toHaveLength(1);
		expect(data.totalDocs).toBe(1);
	});

	it('throws loudly on a malformed envelope (P5 contract drift)', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn(async () => new Response(JSON.stringify({ items: [] }), { status: 200 }))
		);
		await expect(getCollection<Product>('products', requestUrl)).rejects.toThrow(
			/\[products\] envelope/
		);
	});

	it('throws on non-2xx responses', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn(async () => new Response('{}', { status: 502 }))
		);
		await expect(getCollection<Product>('products', requestUrl)).rejects.toThrow(
			/502/
		);
	});

	describe('getCollectionOrNull (auxiliary reads)', () => {
		const homeUrl = new URL('https://store.example/');

		it('returns the validated envelope when the endpoint exists', async () => {
			vi.stubGlobal(
				'fetch',
				vi.fn(async () =>
					new Response(
						JSON.stringify({
							docs: [],
							totalDocs: 3,
							limit: 1,
							totalPages: 1,
							page: 1,
							hasNextPage: false,
							hasPrevPage: false,
						}),
						{ status: 200 }
					)
				)
			);
			const data = await getCollectionOrNull<{ id: number }>(
				'content/posts',
				homeUrl,
				{ limit: 1 }
			);
			expect(data?.totalDocs).toBe(3);
		});

		it('degrades a 404 to null (backend predates the collection)', async () => {
			vi.stubGlobal(
				'fetch',
				vi.fn(async () => new Response('{}', { status: 404 }))
			);
			await expect(
				getCollectionOrNull<{ id: number }>('content/posts', homeUrl)
			).resolves.toBeNull();
		});

		it('still throws on other failures and malformed envelopes', async () => {
			vi.stubGlobal(
				'fetch',
				vi.fn(async () => new Response('{}', { status: 502 }))
			);
			await expect(
				getCollectionOrNull<{ id: number }>('content/posts', homeUrl)
			).rejects.toThrow(/502/);

			vi.stubGlobal(
				'fetch',
				vi.fn(async () =>
					new Response(JSON.stringify({ items: [] }), { status: 200 })
				)
			);
			await expect(
				getCollectionOrNull<{ id: number }>('content/posts', homeUrl)
			).rejects.toThrow(/envelope/);
		});
	});

	it('evicts module-memo entries after the TTL instead of pinning first-render data', async () => {
		// 2026-11-03 prod incident: the module-level memo lived forever, so the
		// first render after a deploy pinned stale envelopes until the next
		// redeploy (seed-era images served for ~30 min).
		vi.useFakeTimers();
		try {
			let calls = 0;
			vi.stubGlobal(
				'fetch',
				vi.fn(async () => {
					calls += 1;
					return new Response(
						JSON.stringify({
							docs: [{ id: calls }],
							totalDocs: 1,
							limit: 7,
							totalPages: 1,
							page: 1,
							hasNextPage: false,
							hasPrevPage: false,
						}),
						{ status: 200 }
					);
				}
			));
			const url = new URL('https://store.example/');
			// Explicit annotation: an untyped literal widens `sort` to `string`,
			// which is not assignable to the client's `sort?: SortValue`.
			const params: { limit: number; sort: SortValue } = { limit: 7, sort: 'date' };

			const first = await getCollection<{ id: number }>('products', url, params);
			const second = await getCollection<{ id: number }>('products', url, params);
			expect(calls).toBe(1); // deduped within the TTL
			expect(second.docs[0]?.id).toBe(first.docs[0]?.id);

			vi.advanceTimersByTime(11_000);
			const third = await getCollection<{ id: number }>('products', url, params);
			expect(calls).toBe(2); // evicted and refetched
			expect(third.docs[0]?.id).toBe(2);
		} finally {
			vi.useRealTimers();
			vi.unstubAllGlobals();
		}
	});

	it('accumulates all pages for getAll', async () => {
		const pages: Record<string, number[]> = {
			'1': [1, 2],
			'2': [3],
		};
		vi.stubGlobal(
			'fetch',
			vi.fn(async (input: RequestInfo | URL) => {
				const url = new URL(String(input));
				const page = url.searchParams.get('page') ?? '1';
				const docs = (pages[page] ?? []).map((id) => ({ id }));
				return new Response(
					JSON.stringify({
						docs,
						totalDocs: 3,
						limit: 2,
						totalPages: 2,
						page: Number(page),
						hasNextPage: page === '1',
						hasPrevPage: page === '2',
					}),
					{ status: 200 }
				);
			})
		);
		const all = await getAll<{ id: number }>('products', requestUrl);
		expect(all.map((doc) => doc.id)).toEqual([1, 2, 3]);
	});
});

describe('base resolution (resolveBase precedence)', () => {
	const requestUrl = new URL('https://store.example/shop/');

	const okEnvelope = (limit: number) => ({
		docs: [],
		totalDocs: 0,
		limit,
		totalPages: 0,
		page: 1,
		hasNextPage: false,
		hasPrevPage: false,
	});

	beforeEach(() => {
		vi.unstubAllGlobals();
		vi.unstubAllEnvs();
	});

	afterEach(() => {
		vi.unstubAllEnvs();
	});

	it('prefers the same-origin proxy over a baked NVOOS_API_BASE (runtime)', async () => {
		// Regression (incident 2026-10-29): the mirror dist bakes the hermetic
		// fixture origin into NVOOS_API_BASE; runtime SSR must ignore it and
		// use the same-origin /api/* proxy, or every render fetches
		// localhost:8123 and 500s.
		vi.stubEnv('NVOOS_API_BASE', 'http://localhost:8123/wp-json/nvoos-commerce/v1');
		const fetchMock = vi.fn(
			async (_input: RequestInfo | URL) =>
				new Response(JSON.stringify(okEnvelope(24)), { status: 200 })
		);
		vi.stubGlobal('fetch', fetchMock);

		await getCollection<Product>('products', requestUrl);

		const url = String(fetchMock.mock.calls[0][0]);
		expect(url).toContain('https://store.example/api/products');
		expect(url).not.toContain('localhost');
	});

	it('falls back to NVOOS_API_BASE when no request URL exists (build)', async () => {
		vi.stubEnv('NVOOS_API_BASE', 'http://localhost:8123/wp-json/nvoos-commerce/v1');
		const fetchMock = vi.fn(
			async (_input: RequestInfo | URL) =>
				new Response(JSON.stringify(okEnvelope(100)), { status: 200 })
		);
		vi.stubGlobal('fetch', fetchMock);

		await getCollection<Product>('products', undefined);

		const url = String(fetchMock.mock.calls[0][0]);
		expect(url).toContain('http://localhost:8123/wp-json/nvoos-commerce/v1/products');
	});

	it('prefers the in-process loopback origin set by serve.mjs (Velocity)', async () => {
		// Regression (incident 2026-10-29 #2): on Velocity the public
		// self-request fails at the network layer; serve.mjs sets
		// NVOOS_SSR_API_ORIGIN to http://127.0.0.1:<PORT> and the render
		// must self-fetch through the BFF listener instead.
		vi.stubEnv('NVOOS_API_BASE', 'http://localhost:8123/wp-json/nvoos-commerce/v1');
		vi.stubEnv('NVOOS_SSR_API_ORIGIN', 'http://127.0.0.1:4321');
		const fetchMock = vi.fn(
			async (_input: RequestInfo | URL) =>
				new Response(JSON.stringify(okEnvelope(24)), { status: 200 })
		);
		vi.stubGlobal('fetch', fetchMock);

		await getCollection<Product>('products', requestUrl);

		const url = String(fetchMock.mock.calls[0][0]);
		expect(url).toContain('http://127.0.0.1:4321/api/products');
		expect(url).not.toContain('store.example');
	});
});

describe('getThemePolicy (105 §3)', () => {
	// Each test uses a UNIQUE host: the policy memo is module-level and keyed
	// by the resolved base, so shared URLs would leak entries across tests.
	const policyDoc = {
		policy: 'dark',
		contrast: 'auto',
		defaultTheme: 'dark',
		updatedAt: '2026-11-03T09:00:00Z',
		$extensions: {},
	};

	beforeEach(() => {
		vi.unstubAllGlobals();
		vi.unstubAllEnvs();
	});

	afterEach(() => {
		vi.useRealTimers();
		vi.unstubAllEnvs();
	});

	it('fetches globals/theme through the same-origin base (precedence)', async () => {
		const requestUrl = new URL('https://policy-precedence.example/shop/');
		vi.stubEnv('NVOOS_API_BASE', 'http://localhost:8123/wp-json/nvoos-commerce/v1');
		const fetchMock = vi.fn(
			async (_input: RequestInfo | URL) =>
				new Response(JSON.stringify(policyDoc), { status: 200 })
		);
		vi.stubGlobal('fetch', fetchMock);

		const doc = await getThemePolicy(requestUrl);
		expect(doc.policy).toBe('dark');
		const url = String(fetchMock.mock.calls[0][0]);
		expect(url).toBe('https://policy-precedence.example/api/globals/theme');
	});

	it('memoizes the doc for 60 s (one fetch across renders)', async () => {
		const requestUrl = new URL('https://policy-memo.example/shop/');
		vi.useFakeTimers();
		const fetchMock = vi.fn(
			async (_input: RequestInfo | URL) =>
				new Response(JSON.stringify(policyDoc), { status: 200 })
		);
		vi.stubGlobal('fetch', fetchMock);

		await getThemePolicy(requestUrl);
		vi.advanceTimersByTime(30_000);
		await getThemePolicy(requestUrl);
		expect(fetchMock).toHaveBeenCalledTimes(1);

		// Expiry re-fetches.
		vi.advanceTimersByTime(31_000);
		await getThemePolicy(requestUrl);
		expect(fetchMock).toHaveBeenCalledTimes(2);
	});

	it('returns the default doc on fetch failure and does not cache it', async () => {
		const requestUrl = new URL('https://policy-failure.example/shop/');
		const fetchMock = vi.fn(async () => new Response('{}', { status: 502 }));
		vi.stubGlobal('fetch', fetchMock);

		const first = await getThemePolicy(requestUrl);
		expect(first.policy).toBe('auto');
		await getThemePolicy(requestUrl);
		expect(fetchMock).toHaveBeenCalledTimes(2); // failures never memoize
	});

	it('parses malformed bodies leniently', async () => {
		const requestUrl = new URL('https://policy-malformed.example/shop/');
		vi.stubGlobal(
			'fetch',
			vi.fn(async () => new Response('{"policy": 42}', { status: 200 }))
		);
		const doc = await getThemePolicy(requestUrl);
		expect(doc.policy).toBe('auto');
	});
});

describe('getShop (106 §1.3)', () => {
	const shopDoc = {
		checkoutUrl: 'https://nvoos.pro/checkout/',
		cartUrl: 'https://nvoos.pro/cart/',
		currency: 'USD',
		currencySymbol: '$',
		updatedAt: '2026-11-03T09:00:00Z',
	};

	beforeEach(() => {
		vi.unstubAllGlobals();
		vi.unstubAllEnvs();
	});

	afterEach(() => {
		vi.useRealTimers();
		vi.unstubAllEnvs();
	});

	it('fetches globals/shop through the resolved base and memoizes 60 s', async () => {
		const requestUrl = new URL('https://shop-memo.example/cart/');
		vi.useFakeTimers();
		const fetchMock = vi.fn(
			async (_input: RequestInfo | URL) =>
				new Response(JSON.stringify(shopDoc), { status: 200 })
		);
		vi.stubGlobal('fetch', fetchMock);

		const shop = await getShop(requestUrl);
		expect(shop.checkoutUrl).toBe('https://nvoos.pro/checkout/');
		const url = String(fetchMock.mock.calls[0][0]);
		expect(url).toBe('https://shop-memo.example/api/globals/shop');

		await getShop(requestUrl);
		expect(fetchMock).toHaveBeenCalledTimes(1);
		vi.advanceTimersByTime(61_000);
		await getShop(requestUrl);
		expect(fetchMock).toHaveBeenCalledTimes(2);
	});

	it('fails closed to the default doc on failure', async () => {
		const requestUrl = new URL('https://shop-failure.example/cart/');
		vi.stubGlobal(
			'fetch',
			vi.fn(async () => new Response('{}', { status: 404 }))
		);
		const shop = await getShop(requestUrl);
		expect(shop.checkoutUrl).toBe('');
	});
});
