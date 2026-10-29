import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import {
	buildCollectionQuery,
	buildWhereParams,
	getCollection,
	getAll,
	MAX_LIMIT,
} from '../../src/lib/commerce/client';
import type { Product } from '../../src/lib/commerce/types';

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
});
