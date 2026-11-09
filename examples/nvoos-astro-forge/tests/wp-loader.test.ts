// ---------------------------------------------------------------------------
// wp-loader.test.ts — the Astro content-layer loader against a fake LoaderContext.
//
// Asserts the loader contract: name + schema exposed, store cleared up front,
// digest from modified_gmt, sanitized rendered HTML, featured image wiring,
// and the truncation flag propagation (096: never silent truncation).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { createWpLoader, extractPayloadRaw, payloadRestBase, slugify } from '../lib/wp-loader.ts';
import type { SiteProfile, WpCollection } from '../lib/types.ts';
import type { LoaderContext } from 'astro/loaders';

interface StoredEntry {
	id: string;
	data: Record<string, unknown>;
	digest?: number | string;
	rendered?: { html: string };
}

/** Minimal LoaderContext double — enough surface for the loader. */
function fakeContext(store: { entries: StoredEntry[]; cleared: boolean }) {
	return {
		collection: 'posts',
		store: {
			clear: () => {
				store.cleared = true;
				store.entries = [];
			},
			set: (entry: StoredEntry) => {
				store.entries.push(entry);
				return true;
			},
		},
		meta: { get: () => undefined, set: () => {} },
		logger: { warn: () => {}, info: () => {} },
		config: {},
		parseData: async <T extends Record<string, unknown>>(props: { data: T }) => props.data,
		renderMarkdown: async () => ({ html: '' }),
		generateDigest: (data: string | Record<string, unknown>) => `digest:${String(data)}`,
	} as unknown as LoaderContext;
}

/** Canned WP entries + a fetchImpl serving them with pagination headers. */
function cannedFetch(entries: Record<string, unknown>[], totalPages = 1) {
	return async (input: string | URL | Request): Promise<Response> => {
		const url = new URL(String(input));
		const page = Number(url.searchParams.get('page') ?? '1');
		const perPage = Number(url.searchParams.get('per_page') ?? '100');
		return new Response(JSON.stringify(entries.slice((page - 1) * perPage, page * perPage)), {
			status: 200,
			headers: { 'content-type': 'application/json', 'x-wp-totalpages': String(totalPages) },
		});
	};
}

const PROFILE: SiteProfile = {
	url: 'https://fixture.test',
	collections: [
		{
			slug: 'posts',
			restBase: 'wp/v2/posts',
			fields: ['id', 'date', 'modified_gmt', 'title', 'slug', 'link', 'content', 'excerpt', '_links'],
			embed: true,
		},
	],
};

const COLLECTION = PROFILE.collections[0];

describe('createWpLoader contract', () => {
	it('exposes name and schema (template validation contract)', () => {
		const loader = createWpLoader({ site: PROFILE, collection: COLLECTION });
		expect(loader.name).toBe('nvoos-wp');
		expect((loader as { schema?: unknown }).schema).toBeDefined();
	});
});

describe('createWpLoader load()', () => {
	it('stores sanitized entries keyed by WP post ID with a content digest', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const loader = createWpLoader({ site: PROFILE, collection: COLLECTION });
		const fetchImpl = cannedFetch([
			{
				id: 42,
				date: '2026-10-01T00:00:00',
				modified_gmt: '2026-10-02T12:00:00',
				title: { rendered: 'Hello Fixture' },
				slug: 'hello-fixture',
				link: 'https://fixture.test/hello-fixture/',
				content: { rendered: '<p>Body</p>' },
				excerpt: { rendered: '<p>Excerpt</p>' },
			},
		]);

		// The loader builds its own WpClient; inject the fetch seam via the
		// global fetch override used in these tests.
		const originalFetch = globalThis.fetch;
		(globalThis as { fetch: typeof fetch }).fetch = fetchImpl as typeof fetch;
		try {
			await loader.load(fakeContext(store));
		} finally {
			(globalThis as { fetch: typeof fetch }).fetch = originalFetch;
		}

		expect(store.cleared).toBe(true);
		expect(store.entries).toHaveLength(1);
		const entry = store.entries[0];
		expect(entry.id).toBe('42');
		expect(entry.data.title).toBe('Hello Fixture');
		expect(entry.data.slug).toBe('hello-fixture');
		expect(entry.data.excerpt).toBe('<p>Excerpt</p>');
		expect(entry.rendered?.html).toBe('<p>Body</p>');
		expect(entry.digest).toBe('digest:2026-10-02T12:00:00');
	});

	it('sanitizes script vectors inside rendered HTML', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const loader = createWpLoader({ site: PROFILE, collection: COLLECTION });
		const fetchImpl = cannedFetch([
			{
				id: 1,
				title: { rendered: 'Nasty' },
				slug: 'nasty',
				content: { rendered: '<p>ok</p><script>alert(1)</script>' },
			},
		]);

		const originalFetch = globalThis.fetch;
		(globalThis as { fetch: typeof fetch }).fetch = fetchImpl as typeof fetch;
		try {
			await loader.load(fakeContext(store));
		} finally {
			(globalThis as { fetch: typeof fetch }).fetch = originalFetch;
		}

		expect(store.entries[0].rendered?.html).toBe('<p>ok</p>');
	});

	it('wires the featured image from _embedded media', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const loader = createWpLoader({ site: PROFILE, collection: COLLECTION });
		const fetchImpl = cannedFetch([
			{
				id: 1,
				title: { rendered: 'Image post' },
				slug: 'image-post',
				content: { rendered: '<p>x</p>' },
				_embedded: { 'wp:featuredmedia': [{ source_url: 'https://fixture.test/uploads/featured.png' }] },
			},
		]);

		const originalFetch = globalThis.fetch;
		(globalThis as { fetch: typeof fetch }).fetch = fetchImpl as typeof fetch;
		try {
			await loader.load(fakeContext(store));
		} finally {
			(globalThis as { fetch: typeof fetch }).fetch = originalFetch;
		}

		expect(store.entries[0].data.featuredImage).toBe('https://fixture.test/uploads/featured.png');
	});

	it('propagates the truncation flag into entries and warns', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const warnings: string[] = [];
		const context = {
			...fakeContext(store),
			logger: { warn: (message: string) => warnings.push(message), info: () => {} },
		} as unknown as LoaderContext;
		const loader = createWpLoader({
			site: PROFILE,
			collection: { ...COLLECTION, maxPages: 1 },
		});
		// 3 pages of data, cap of 1 → truncated.
		const many = Array.from({ length: 200 }, (_, i) => ({
			id: i + 1,
			title: { rendered: `P${i}` },
			slug: `p-${i}`,
			content: { rendered: '<p>x</p>' },
		}));
		const fetchImpl = cannedFetch(many, 2);

		const originalFetch = globalThis.fetch;
		(globalThis as { fetch: typeof fetch }).fetch = fetchImpl as typeof fetch;
		try {
			await loader.load(context);
		} finally {
			(globalThis as { fetch: typeof fetch }).fetch = originalFetch;
		}

		expect(store.entries[0].data.truncated).toBe(true);
		expect(warnings.some((w) => w.includes('incomplete'))).toBe(true);
	});

	it('extracts the wc/v3 commerce shape (price/stock/sku/images/categories)', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const loader = createWpLoader({
			site: PROFILE,
			collection: { ...COLLECTION, restBase: 'wc/v3/products', kind: 'product' },
		});
		const fetchImpl = cannedFetch([
			{
				id: 7,
				name: 'Woo Sweater',
				slug: 'woo-sweater',
				permalink: 'https://fixture.test/product/woo-sweater/',
				date_created_gmt: '2026-10-01T00:00:00',
				date_modified_gmt: '2026-10-02T00:00:00',
				description: '<p>Body</p><script>alert(1)</script>',
				short_description: '<p>Short</p>',
				price: '89.99',
				regular_price: '99.99',
				sale_price: '89.99',
				stock_status: 'instock',
				sku: 'WS-7',
				images: [{ src: 'https://fixture.test/a.png' }],
				categories: [{ name: 'Knitwear' }],
			},
		]);

		const originalFetch = globalThis.fetch;
		(globalThis as { fetch: typeof fetch }).fetch = fetchImpl as typeof fetch;
		try {
			await loader.load(fakeContext(store));
		} finally {
			(globalThis as { fetch: typeof fetch }).fetch = originalFetch;
		}

		const data = store.entries[0].data;
		expect(data.title).toBe('Woo Sweater');
		expect(data.link).toBe('https://fixture.test/product/woo-sweater/');
		expect(data.price).toBe('89.99');
		expect(data.regularPrice).toBe('99.99');
		expect(data.salePrice).toBe('89.99');
		expect(data.stockStatus).toBe('instock');
		expect(data.sku).toBe('WS-7');
		expect(data.categories).toEqual(['Knitwear']);
		expect(data.images).toEqual(['https://fixture.test/a.png']);
		expect(data.featuredImage).toBe('https://fixture.test/a.png');
		expect(store.entries[0].rendered?.html).toBe('<p>Body</p>');
	});

	it('skips entries whose slug is excluded', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const loader = createWpLoader({
			site: PROFILE,
			collection: { ...COLLECTION, exclude: ['login-customizer'] },
		});
		const fetchImpl = cannedFetch([
			{ id: 1, title: { rendered: 'Keep' }, slug: 'keep', content: { rendered: '<p>x</p>' } },
			{
				id: 2,
				title: { rendered: 'Junk' },
				slug: 'login-customizer',
				content: { rendered: '<p>x</p>' },
			},
		]);

		const originalFetch = globalThis.fetch;
		(globalThis as { fetch: typeof fetch }).fetch = fetchImpl as typeof fetch;
		try {
			await loader.load(fakeContext(store));
		} finally {
			(globalThis as { fetch: typeof fetch }).fetch = originalFetch;
		}

		expect(store.entries).toHaveLength(1);
		expect(store.entries[0].data.slug).toBe('keep');
	});

	it('tolerates a missing optional source as an empty collection', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const warnings: string[] = [];
		const context = {
			...fakeContext(store),
			logger: { warn: (message: string) => warnings.push(message), info: () => {} },
		} as unknown as LoaderContext;
		const loader = createWpLoader({
			site: PROFILE,
			collection: { ...COLLECTION, restBase: 'wc/v3/products', optional: true },
		});
		const fetchImpl = async (): Promise<Response> =>
			new Response('{}', { status: 404, headers: { 'content-type': 'application/json' } });

		const originalFetch = globalThis.fetch;
		(globalThis as { fetch: typeof fetch }).fetch = fetchImpl as typeof fetch;
		try {
			await loader.load(context);
		} finally {
			(globalThis as { fetch: typeof fetch }).fetch = originalFetch;
		}

		expect(store.entries).toHaveLength(0);
		expect(store.cleared).toBe(true);
		expect(warnings.some((w) => w.includes('optional'))).toBe(true);
	});

	it('still fails on auth errors for optional sources', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const loader = createWpLoader({
			site: PROFILE,
			collection: { ...COLLECTION, restBase: 'wc/v3/products', optional: true },
		});
		const fetchImpl = async (): Promise<Response> =>
			new Response('{}', { status: 401, headers: { 'content-type': 'application/json' } });

		const originalFetch = globalThis.fetch;
		(globalThis as { fetch: typeof fetch }).fetch = fetchImpl as typeof fetch;
		try {
			await expect(loader.load(fakeContext(store))).rejects.toMatchObject({ code: 'wp_auth_required' });
		} finally {
			(globalThis as { fetch: typeof fetch }).fetch = originalFetch;
		}
	});

	it('resolves featured media by ID when _embed has none', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const loader = createWpLoader({
			site: PROFILE,
			collection: { ...COLLECTION, restBase: 'wp/v2/product' },
		});
		const mediaRequests: string[] = [];
		const fetchImpl = async (input: string | URL | Request): Promise<Response> => {
			const url = new URL(String(input));
			mediaRequests.push(url.pathname);
			if (url.pathname.includes('/media/')) {
				return new Response(JSON.stringify({ source_url: 'https://fixture.test/uploads/501.png' }), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				});
			}
			return new Response(
				JSON.stringify([{ id: 9, title: { rendered: 'P' }, slug: 'p', content: { rendered: '<p>x</p>' }, featured_media: 501 }]),
				{ status: 200, headers: { 'content-type': 'application/json', 'x-wp-totalpages': '1' } }
			);
		};

		const originalFetch = globalThis.fetch;
		(globalThis as { fetch: typeof fetch }).fetch = fetchImpl as typeof fetch;
		try {
			await loader.load(fakeContext(store));
		} finally {
			(globalThis as { fetch: typeof fetch }).fetch = originalFetch;
		}

		expect(store.entries[0].data.featuredImage).toBe('https://fixture.test/uploads/501.png');
		expect(mediaRequests.some((p) => p.includes('/media/501'))).toBe(true);
	});

	it('maps the nvoos-commerce/v1 envelope into the stable shape', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const loader = createWpLoader({
			site: PROFILE,
			collection: {
				...COLLECTION,
				restBase: 'nvoos-commerce/v1/products',
				kind: 'product',
				source: 'payload',
			},
		});
		const fetchImpl = async (): Promise<Response> =>
			new Response(
				JSON.stringify({
					docs: [
						{
							id: 301,
							name: 'Aurora 3-Seater Sofa',
							price: 1299,
							oldPrice: 1499,
							description: '<p>Deep and cloud-soft.</p>',
							category: { name: 'Sofas' },
							image: 'https://fixture.test/a.png',
							statuses: [{ name: 'In Stock' }],
							createdAt: '2026-09-10 10:00:00',
							updatedAt: '2026-10-20 09:30:00',
						},
					],
					totalDocs: 1,
					totalPages: 1,
					page: 1,
				}),
				{ status: 200, headers: { 'content-type': 'application/json' } }
			);

		const originalFetch = globalThis.fetch;
		(globalThis as { fetch: typeof fetch }).fetch = fetchImpl as typeof fetch;
		try {
			await loader.load(fakeContext(store));
		} finally {
			(globalThis as { fetch: typeof fetch }).fetch = originalFetch;
		}

		const data = store.entries[0].data;
		expect(store.entries[0].id).toBe('301');
		expect(data.title).toBe('Aurora 3-Seater Sofa');
		expect(data.slug).toBe('aurora-3-seater-sofa');
		expect(data.price).toBe('1299');
		expect(data.regularPrice).toBe('1499'); // oldPrice = the strike-through
		expect(data.salePrice).toBe('1299'); // price < oldPrice → on sale
		expect(data.categories).toEqual(['Sofas']);
		expect(data.stockStatus).toBe('In Stock');
		expect(data.featuredImage).toBe('https://fixture.test/a.png');
		expect(store.entries[0].rendered?.html).toBe('<p>Deep and cloud-soft.</p>');
	});

	it('paginates the payload envelope and flags truncation at the cap', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const warnings: string[] = [];
		const context = {
			...fakeContext(store),
			logger: { warn: (message: string) => warnings.push(message), info: () => {} },
		} as unknown as LoaderContext;
		const loader = createWpLoader({
			site: PROFILE,
			collection: {
				...COLLECTION,
				restBase: 'nvoos-commerce/v1/products',
				kind: 'product',
				source: 'payload',
				perPage: 1,
				maxPages: 1,
			},
		});
		const doc = (id: number) => ({ id, name: `Doc ${id}`, price: 10, createdAt: '2026-01-01 00:00:00' });
		const fetchImpl = async (input: string | URL | Request): Promise<Response> => {
			const url = new URL(String(input));
			const page = Number(url.searchParams.get('page') ?? '1');
			return new Response(
				JSON.stringify({ docs: [doc(page)], totalDocs: 2, totalPages: 2, page }),
				{ status: 200, headers: { 'content-type': 'application/json' } }
			);
		};

		const originalFetch = globalThis.fetch;
		(globalThis as { fetch: typeof fetch }).fetch = fetchImpl as typeof fetch;
		try {
			await loader.load(context);
		} finally {
			(globalThis as { fetch: typeof fetch }).fetch = originalFetch;
		}

		expect(store.entries).toHaveLength(1); // cap stopped the fetch
		expect(store.entries[0].data.truncated).toBe(true);
		expect(warnings.some((w) => w.includes('incomplete'))).toBe(true);
	});
});

describe( 'extractPayloadRaw (nvoos-commerce envelope)', () => {
	it( 'maps name/description into the entry shape', () => {
		const raw = extractPayloadRaw( {
			id: 9,
			name: 'Aurora Sofa',
			description: '<p>Deep seat</p>',
			createdAt: '2026-11-01T10:00:00',
			updatedAt: '2026-11-02T10:00:00',
			image: 'https://fixture.test/a.png',
			images: ['https://fixture.test/b.png'],
		} );

		expect( raw.title ).toBe( 'Aurora Sofa' );
		expect( raw.html ).toBe( '<p>Deep seat</p>' );
		expect( raw.date ).toBe( '2026-11-01T10:00:00' );
		expect( raw.modified ).toBe( '2026-11-02T10:00:00' );
		expect( raw.featuredImage ).toBe( 'https://fixture.test/a.png' );
	} );

	it( 'derives the slug from the title when the document carries none', () => {
		expect( extractPayloadRaw( { id: 1, name: 'Aurora 3-Seater Sofa' } ).slug ).toBe( 'aurora-3-seater-sofa' );
	} );

	it( 'prefers an explicit slug (local content keeps its URL on a rename)', () => {
		const raw = extractPayloadRaw( {
			id: 7,
			name: 'Behind The Scenes (renamed)',
			slug: 'behind-the-scenes',
		} );

		expect( raw.slug ).toBe( 'behind-the-scenes' );
	} );
} );

describe( 'local source (Proposal 135 §D4)', () => {
	const LOCAL: WpCollection = {
		slug: 'posts',
		restBase: '',
		fields: [],
		source: 'local',
	};

	it( 'defaults the REST base to the bridge local collection', () => {
		expect( payloadRestBase( LOCAL ) ).toBe( 'nvoos-commerce/v1/local' );
		expect( payloadRestBase( { ...LOCAL, restBase: 'nvoos-commerce/v1/pages' } ) ).toBe( 'nvoos-commerce/v1/pages' );
	} );

	it( 'loads local documents with their explicit slugs', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const loader = createWpLoader( {
			site: PROFILE,
			collection: LOCAL,
			userAgent: 'test',
		} );

		const requested: string[] = [];
		const originalFetch = globalThis.fetch;
		( globalThis as { fetch: typeof fetch } ).fetch = ( async ( input: string | URL | Request ) => {
			requested.push( String( input ) );
			return new Response(
				JSON.stringify( {
					docs: [
						{
							id: 7,
							name: 'Behind The Scenes',
							slug: 'behind-the-scenes',
							description: '<p>Notes</p><script>alert(1)</script>',
							seoTitle: 'Behind The Scenes — Studio',
							createdAt: '2026-11-01T09:00:00',
							updatedAt: '2026-11-03T09:00:00',
						},
					],
					totalDocs: 1,
					totalPages: 1,
				} ),
				{ status: 200, headers: { 'content-type': 'application/json' } }
			);
		} ) as typeof fetch;

		try {
			await loader.load( fakeContext( store ) );
		} finally {
			( globalThis as { fetch: typeof fetch } ).fetch = originalFetch;
		}

		expect( requested[0] ).toContain( '/wp-json/nvoos-commerce/v1/local' );
		expect( store.entries ).toHaveLength( 1 );

		const entry = store.entries[0];
		expect( entry.id ).toBe( '7' );
		expect( entry.data.slug ).toBe( 'behind-the-scenes' );
		expect( entry.data.title ).toBe( 'Behind The Scenes' );
		expect( entry.data.seoTitle ).toBe( 'Behind The Scenes — Studio' );
		// Sanitizer ran: the script tag is gone.
		expect( entry.rendered?.html ).toContain( '<p>Notes</p>' );
		expect( entry.rendered?.html ).not.toContain( '<script' );
	} );

	it( 'degrades to an empty collection when an optional local source 404s', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const loader = createWpLoader( {
			site: PROFILE,
			collection: { ...LOCAL, optional: true },
		} );

		const originalFetch = globalThis.fetch;
		( globalThis as { fetch: typeof fetch } ).fetch = ( async () =>
			new Response( JSON.stringify( { code: 'rest_no_route', message: 'No route.' } ), {
				status: 404,
				headers: { 'content-type': 'application/json' },
			} ) ) as typeof fetch;

		try {
			await loader.load( fakeContext( store ) );
		} finally {
			( globalThis as { fetch: typeof fetch } ).fetch = originalFetch;
		}

		expect( store.entries ).toHaveLength( 0 );
	} );
} );

describe( 'slugify (D4 — limax, matches the velocity rule)', () => {
	it( 'transliterates accents', () => {
		expect( slugify( 'Café Crème' ) ).toBe( 'cafe-creme' );
		expect( slugify( 'Ångström Über' ) ).toBe( 'angstroem-ueber' );
	} );

	it( 'keeps the plain ASCII behavior', () => {
		expect( slugify( 'Aurora 3-Seater Sofa' ) ).toBe( 'aurora-3-seater-sofa' );
		expect( slugify( 'hello_world  ' ) ).toBe( 'hello-world' );
	} );

	it( 'drops apostrophes, never separates them (velocity parity)', () => {
		expect( slugify( "Lena's Chair!" ) ).toBe( 'lenas-chair' );
	} );

	it( 'caps at 80 characters', () => {
		const long = 'x'.repeat( 120 );
		expect( slugify( long ) ).toBe( 'x'.repeat( 80 ) );
	} );

	it( 'falls back to "item" for empty input', () => {
		expect( slugify( '' ) ).toBe( 'item' );
		expect( slugify( '---' ) ).toBe( 'item' );
	} );
} );
