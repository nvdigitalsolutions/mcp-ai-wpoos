// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// wp-loader.test.ts — the Astro content-layer loader against a fake LoaderContext.
//
// Asserts the loader contract: name + schema exposed, store cleared up front,
// digest from modified_gmt, sanitized rendered HTML, featured image wiring,
// and the truncation flag propagation (096: never silent truncation).
// ---------------------------------------------------------------------------

import { mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

import { describe, expect, it } from 'vitest';

import {
	contentSchema,
	createWpLoader,
	extractPayloadRaw,
	localEntryId,
	payloadRestBase,
	slugify,
} from '../lib/wp-loader.ts';
import type { SiteProfile, WpCollection } from '../lib/types.ts';
import type { LoaderContext } from 'astro/loaders';

interface StoredEntry {
	id: string;
	data: Record<string, unknown>;
	digest?: number | string;
	rendered?: { html: string };
}

/** Minimal LoaderContext double — enough surface for the loader. */
interface TestLogger {
	warn: ( message: string ) => void;
	info: ( message: string ) => void;
}

function fakeContext(
	store: { entries: StoredEntry[]; cleared: boolean },
	root?: URL,
	logger: TestLogger = { warn: () => {}, info: () => {} }
) {
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
			// The hybrid-local merge reads the store back (it captures local
			// entries before the WP load clears it) and deletes a WordPress entry
			// an explicit `override` replaces.
			entries: () => store.entries.map((entry) => [entry.id, entry] as [string, StoredEntry]),
			delete: (key: string) => {
				store.entries = store.entries.filter((entry) => entry.id !== key);
			},
			has: (key: string) => store.entries.some((entry) => entry.id === key),
			get: (key: string) => store.entries.find((entry) => entry.id === key),
		},
		meta: { get: () => undefined, set: () => {} },
		logger,
		config: root ? { root } : {},
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

	it('keeps the materializer slug provenance through the schema', () => {
		// Astro parses every frontmatter field through this schema and the
		// transform returns a NEW object, so an undeclared field is stripped
		// before `adoptLocal` can merge on it.
		const parsed = (contentSchema as { parse: (value: unknown) => Record<string, unknown> }).parse({
			title: 'Services team',
			slug: 'team-3',
			slugRenamedFrom: 'team',
		});
		expect(parsed.slug).toBe('team-3');
		expect(parsed.slugRenamedFrom).toBe('team');
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

	it('decodes entity-encoded titles and derives a plain-text seoDescription', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const loader = createWpLoader({ site: PROFILE, collection: COLLECTION });
		const fetchImpl = cannedFetch([
			{
				id: 7,
				title: { rendered: 'We Didn&#8217;t Know Why' },
				slug: 'we-didnt-know-why',
				content: { rendered: '<p>x</p>' },
				excerpt: { rendered: '<p>written &amp; directed by Evan</p>\n' },
			},
		]);

		const originalFetch = globalThis.fetch;
		(globalThis as { fetch: typeof fetch }).fetch = fetchImpl as typeof fetch;
		try {
			await loader.load(fakeContext(store));
		} finally {
			(globalThis as { fetch: typeof fetch }).fetch = originalFetch;
		}

		// The title is a TEXT sink (document title, h1, JSON-LD): the entity must
		// be decoded or the page renders `We Didn&#8217;t` literally.
		expect(store.entries[0].data.title).toBe('We Didn’t Know Why');
		// The excerpt stays HTML for cards/feeds…
		expect(store.entries[0].data.excerpt).toBe('<p>written &amp; directed by Evan</p>\n');
		// …while the description (a meta/JSON-LD sink) is plain text.
		expect(store.entries[0].data.seoDescription).toBe('written & directed by Evan');
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

describe( 'hybrid local content (106 P2)', () => {
	type LocalEntry = Record< string, unknown >;

	function loaderWith( entries: LocalEntry[] ) {
		return createWpLoader( {
			site: PROFILE,
			collection: COLLECTION,
			userAgent: 'test',
			local: { pattern: '**/*.mdx', base: './src/content/posts' },
			localLoaderFactory: () =>
				( {
					name: 'fake-local',
					load: async ( context: { store: { set: ( entry: LocalEntry ) => void } } ) => {
						for ( const entry of entries ) {
							context.store.set( entry );
						}
					},
				} as never ),
		} );
	}

	async function load( entries: LocalEntry[], root?: URL ) {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const originalFetch = globalThis.fetch;
		( globalThis as { fetch: typeof fetch } ).fetch = cannedFetch( [] );
		try {
			await loaderWith( entries ).load( fakeContext( store, root ) );
		} finally {
			( globalThis as { fetch: typeof fetch } ).fetch = originalFetch;
		}
		return store.entries;
	}

	it( 'gives a materialized entry the sanitized rendered.html the semantic renderer reads', async () => {
		const root = await mkdtemp( join( tmpdir(), 'forge-local-' ) );
		const dir = join( root, 'src', 'content', 'posts' );
		await mkdir( dir, { recursive: true } );
		await writeFile(
			join( dir, 'application.html' ),
			'<section id="home"><p>Hi</p><script>alert(1)</script></section>',
			'utf8'
		);

		const entries = await load(
			[ { id: 'application', data: { title: 'Application' }, digest: 1, body: '' } ],
			pathToFileURL( root )
		);

		expect( entries ).toHaveLength( 1 );
		expect( entries[ 0 ].data.slug ).toBe( 'application' );
		// The semantic renderer classifies rendered.html: without it a local page
		// renders unthemed raw markup and the site loses its bands.
		expect( entries[ 0 ].rendered?.html ).toContain( '<section id="home">' );
		// Same sanitizer as the WP path.
		expect( entries[ 0 ].rendered?.html ).not.toContain( '<script' );
	} );

	it( 'never promotes the MDX source to rendered.html', async () => {
		// `body` on a glob entry is the Markdown/MDX SOURCE, not output. Setting
		// rendered.html from it took the DesignRenderer branch in the post route
		// and rendered a real MDX post as literal `import` lines and bullets,
		// shadowing the compiled <Content /> (2026-11-15).
		const entries = await load( [
			{
				id: 'hello-world',
				data: { title: 'Hello' },
				digest: 6,
				body: "import FaqAccordion from '../../components/blocks/FaqAccordion.astro';\n\n- a bullet\n",
			},
		] );

		expect( entries ).toHaveLength( 1 );
		expect( entries[ 0 ].rendered ).toBeUndefined();
	} );

	it( 'prefers a sibling <id>.html body over the MDX body', async () => {
		const root = await mkdtemp( join( tmpdir(), 'forge-local-' ) );
		const dir = join( root, 'src', 'content', 'posts' );
		await mkdir( dir, { recursive: true } );
		await writeFile(
			join( dir, 'application.html' ),
			'<section id="home"><h1>From file</h1></section>',
			'utf8'
		);

		const entries = await load(
			[ { id: 'application', data: {}, digest: 2, body: 'MDX body is ignored' } ],
			pathToFileURL( root )
		);

		expect( entries ).toHaveLength( 1 );
		expect( entries[ 0 ].rendered?.html ).toContain( 'From file' );
	} );

	it( 'leaves rendered undefined when local content has no body', async () => {
		const entries = await load( [ { id: 'bare', data: { title: 'Bare' }, digest: 3, body: '   ' } ] );
		expect( entries ).toHaveLength( 1 );
		expect( entries[ 0 ].rendered ).toBeUndefined();
	} );

	it( 'never ships a local draft', async () => {
		const entries = await load( [
			{ id: 'draft', data: { title: 'Draft', draft: true }, digest: 4, body: '<p>x</p>' },
		] );
		expect( entries ).toHaveLength( 0 );
	} );
} );

	describe( 'hybrid local — collision is keyed on the slug, not the store id (2026-11-22)', () => {
		type LocalEntry = Record< string, unknown >;
		const PAGE_COLLECTION: WpCollection = {
			...COLLECTION,
			slug: 'pages',
			restBase: 'wp/v2/pages',
			kind: 'page',
		};

		function wpEntry( id: number, slug: string, title = slug ): Record< string, unknown > {
			return {
				id,
				date: '2026-01-01T00:00:00',
				modified_gmt: '2026-01-02T00:00:00',
				title: { rendered: title },
				slug,
				link: `https://fixture.test/${ slug }/`,
				content: { rendered: `<p>${ title }</p>` },
				excerpt: { rendered: '' },
			};
		}

		async function run(
			collection: WpCollection,
			localEntries: LocalEntry[],
			wpEntries: Record< string, unknown >[],
			options: { localOnly?: boolean } = {}
		) {
			const store = { entries: [] as StoredEntry[], cleared: false };
			const logs = { warn: [] as string[], info: [] as string[] };
			const context = fakeContext( store, undefined, {
				warn: ( message: string ) => logs.warn.push( message ),
				info: ( message: string ) => logs.info.push( message ),
			} );
			const loader = createWpLoader( {
				site: PROFILE,
				collection,
				userAgent: 'test',
				...( options.localOnly ? { localOnly: true } : {} ),
				local: { pattern: '**/*.mdx', base: `./src/content/${ collection.slug }` },
				localLoaderFactory: () =>
					( {
						name: 'fake-local',
						load: async ( ctx: { store: { set: ( entry: LocalEntry ) => void } } ) => {
							for ( const entry of localEntries ) {
								ctx.store.set( entry );
							}
						},
					} as never ),
			} );
			const originalFetch = globalThis.fetch;
			( globalThis as { fetch: typeof fetch } ).fetch = cannedFetch( wpEntries ) as typeof fetch;
			try {
				await loader.load( context );
			} finally {
				( globalThis as { fetch: typeof fetch } ).fetch = originalFetch;
			}
			return { entries: store.entries, logs };
		}

		it( 'drops a page override with the same slug and names both sources', async () => {
			const { entries, logs } = await run(
				PAGE_COLLECTION,
				[ { id: 'about', data: { title: 'Local about', slug: 'about' }, digest: 1 } ],
				[ wpEntry( 5609, 'about', 'About' ) ]
			);

			expect( entries ).toHaveLength( 1 );
			expect( entries[ 0 ].id ).toBe( '5609' );
			expect( entries[ 0 ].data.title ).toBe( 'About' );
			expect( logs.warn.join( '\n' ) ).toMatch( /was ignored .*slug "about".*id 5609/ );
		} );

		it( 'drops a post override and keeps exactly one route', async () => {
			const { entries } = await run(
				COLLECTION,
				[ { id: 'blog-details', data: { title: 'Local', slug: 'blog-details' }, digest: 1 } ],
				[ wpEntry( 2228, 'blog-details' ) ]
			);

			expect( entries.map( ( entry ) => entry.id ) ).toEqual( [ '2228' ] );
		} );

		it( 'normalizes case when matching the slug', async () => {
			const { entries, logs } = await run(
				PAGE_COLLECTION,
				[ { id: 'about-local', data: { title: 'Local', slug: 'About' }, digest: 1 } ],
				[ wpEntry( 5609, 'about' ) ]
			);

			expect( entries ).toHaveLength( 1 );
			expect( entries[ 0 ].id ).toBe( '5609' );
			expect( logs.warn.join( '\n' ) ).toContain( 'slug "about"' );
		} );

		it( 'lets an explicit override replace a page (slug is the route)', async () => {
			const { entries, logs } = await run(
				PAGE_COLLECTION,
				[
					{
						id: 'about',
						data: { title: 'Local about', slug: 'about', override: true },
						digest: 1,
					},
				],
				[ wpEntry( 5609, 'about', 'About' ) ]
			);

			expect( entries ).toHaveLength( 1 );
			expect( entries[ 0 ].id ).toBe( 'about' );
			expect( entries[ 0 ].data.title ).toBe( 'Local about' );
			expect( entries[ 0 ].data.slug ).toBe( 'about' );
			expect( logs.info.join( '\n' ) ).toMatch( /overrides WordPress "about" \(id 5609\)/ );
		} );

		it( 'keeps the WordPress id and slug when an override replaces a post', async () => {
			const { entries } = await run(
				COLLECTION,
				[
					{
						id: 'blog-details',
						data: { id: 2228, title: 'Local', slug: 'BLOG-DETAILS', override: true },
						digest: 1,
					},
				],
				[ wpEntry( 2228, 'blog-details' ) ]
			);

			expect( entries ).toHaveLength( 1 );
			expect( entries[ 0 ].id ).toBe( '2228' );
			// The WordPress slug is the URL contract — local casing must not move it.
			expect( entries[ 0 ].data.slug ).toBe( 'blog-details' );
			expect( entries[ 0 ].data.title ).toBe( 'Local' );
		} );

		it( 'refuses a post override without the WordPress id', async () => {
			await expect(
				run(
					COLLECTION,
					[ { id: 'blog-details', data: { override: true }, digest: 1 } ],
					[ wpEntry( 2228, 'blog-details' ) ]
				)
			).rejects.toThrow( /carries no id.*id: 2228/ );
		} );

		it( 'refuses a post override whose id is not the WordPress id', async () => {
			await expect(
				run(
					COLLECTION,
					[ { id: 'blog-details', data: { id: 999, override: true }, digest: 1 } ],
					[ wpEntry( 2228, 'blog-details' ) ]
				)
			).rejects.toThrow( /must keep the WordPress id/ );
		} );

		it( 'warns when override matches nothing and adopts it normally', async () => {
			const { entries, logs } = await run(
				PAGE_COLLECTION,
				[ { id: 'new-page', data: { title: 'New', override: true }, digest: 1 } ],
				[ wpEntry( 5609, 'about' ) ]
			);

			expect( entries.map( ( entry ) => entry.id ).sort() ).toEqual( [ '5609', 'new-page' ] );
			expect( logs.warn.join( '\n' ) ).toMatch( /matches no WordPress document/ );
		} );

		it( 'warns when WordPress itself publishes one slug twice', async () => {
			const { entries, logs } = await run(
				PAGE_COLLECTION,
				[],
				[ wpEntry( 101, 'team' ), wpEntry( 202, 'team' ) ]
			);

			// Both stay in the store: the loader reports the CMS-side defect and
			// `verify`'s route-keys gate fails the run.
			expect( entries ).toHaveLength( 2 );
			expect( logs.warn.join( '\n' ) ).toMatch( /publishes slug "team" twice \(ids 101, 202\)/ );
		} );

		it( 'still fills a gap for a non-colliding local entry', async () => {
			const { entries } = await run(
				PAGE_COLLECTION,
				[ { id: 'careers', data: { title: 'Careers' }, digest: 1 } ],
				[ wpEntry( 5609, 'about' ) ]
			);

			expect( entries.map( ( entry ) => entry.id ).sort() ).toEqual( [ '5609', 'careers' ] );
		} );

		it( 'keeps both documents of a de-duplicated snapshot in materialized mode', async () => {
			// `lib/materialize.ts` publishes `team` + `team-3` with the numeric id in
			// each frontmatter. Both must reach the store so `/[slug]/` renders both.
			const { entries } = await run(
				PAGE_COLLECTION,
				[
					{
						id: 'team',
						data: { id: 101, slug: 'team', link: 'https://fixture.test/about/team/' },
						digest: 1,
					},
					{
						id: 'team-3',
						data: {
							id: 102,
							slug: 'team-3',
							slugRenamedFrom: 'team',
							link: 'https://fixture.test/services/team/',
						},
						digest: 1,
					},
				],
				[],
				{ localOnly: true }
			);

			expect( entries.map( ( entry ) => entry.id ).sort() ).toEqual( [ '101', '102' ] );
			expect( entries.map( ( entry ) => entry.data.slug ).sort() ).toEqual( [ 'team', 'team-3' ] );
		} );

		it( 'still yields a de-duplicated snapshot entry to WordPress in a hybrid load', async () => {
			// The snapshot publishes `team-3` after a rename, but the CMS still owns
			// the source slug `team`. Merging on the published slug would add a
			// phantom page beside the live one; the provenance field keeps the
			// documented "WordPress wins" contract.
			const { entries, logs } = await run(
				PAGE_COLLECTION,
				[
					{
						id: 'team-3',
						data: {
							id: 102,
							slug: 'team-3',
							slugRenamedFrom: 'team',
							link: 'https://fixture.test/services/team/',
						},
						digest: 1,
					},
				],
				[ wpEntry( 101, 'team', 'About team' ) ]
			);

			expect( entries.map( ( entry ) => entry.id ) ).toEqual( [ '101' ] );
			expect( logs.warn.join( '\n' ) ).toMatch( /was ignored .*slug "team".*id 101/ );
		} );
	} );

	describe( 'materialized content — no CMS (2026-11-15)', () => {
	function localOnlyLoader( entries: Record< string, unknown >[], base = './src/content/posts' ) {
		return createWpLoader( {
			site: PROFILE,
			collection: COLLECTION,
			local: { pattern: '**/*.mdx', base },
			localOnly: true,
			localLoaderFactory: () =>
				( {
					name: 'fake-local',
					load: async ( context: { store: { set: ( entry: Record< string, unknown > ) => void } } ) => {
						for ( const entry of entries ) {
							context.store.set( entry );
						}
					},
				} as never ),
		} );
	}

	it( 'never contacts the dead CMS', async () => {
		// Run #102 (2026-11-15) died on `Endpoint not found: wp/v2/pages` AFTER the
		// content had been materialized: a CMS-less profile must not fetch at all.
		let calls = 0;
		const originalFetch = globalThis.fetch;
		( globalThis as { fetch: typeof fetch } ).fetch = ( async () => {
			calls += 1;
			throw new Error( 'the CMS is gone — nothing may fetch it' );
		} ) as typeof fetch;

		try {
			await localOnlyLoader( [
				{ id: 'application', data: { title: 'Application' }, digest: 1, body: '' },
			] ).load( fakeContext( { entries: [], cleared: false } ) );
		} finally {
			( globalThis as { fetch: typeof fetch } ).fetch = originalFetch;
		}

		expect( calls ).toBe( 0 );
	} );

	it( 'adopts the snapshot body and drops drafts', async () => {
		const root = await mkdtemp( join( tmpdir(), 'forge-materialized-' ) );
		const dir = join( root, 'src', 'content', 'posts' );
		await mkdir( dir, { recursive: true } );
		await writeFile(
			join( dir, 'application.html' ),
			'<section><p>Recovered</p><script>alert(1)</script></section>',
			'utf8'
		);

		const store = { entries: [] as StoredEntry[], cleared: false };
		await localOnlyLoader( [
			{ id: 'application', data: { title: 'Application' }, digest: 1, body: '' },
			{ id: 'draft', data: { title: 'Draft', draft: true }, digest: 2, body: '' },
		] ).load( fakeContext( store, pathToFileURL( root ) ) );

		expect( store.cleared ).toBe( true );
		expect( store.entries.map( ( entry ) => entry.id ) ).toEqual( [ 'application' ] );
		expect( store.entries[ 0 ].data.slug ).toBe( 'application' );
		expect( store.entries[ 0 ].rendered?.html ).toContain( 'Recovered' );
		expect( store.entries[ 0 ].rendered?.html ).not.toContain( '<script' );
	} );

	it( 'keeps the WordPress numeric id so the detached routes match the live build', async () => {
		// The glob id is the filename slug; the snapshot's frontmatter carries the
		// source id. A materialized post must keep `/posts/2228/blog-details/` —
		// adopting the slug id doubled it to `/posts/blog-details/blog-details/`
		// and broke every post URL at cutover (virtuallysrilanka, 2026-11-22).
		const store = { entries: [] as StoredEntry[], cleared: false };
		await localOnlyLoader( [
			{
				id: 'blog-details',
				data: { id: 2228, slug: 'blog-details', title: 'blog-details' },
				digest: 1,
				body: '',
			},
		] ).load( fakeContext( store ) );

		expect( store.entries.map( ( entry ) => entry.id ) ).toEqual( [ '2228' ] );
		expect( store.entries[ 0 ].data.slug ).toBe( 'blog-details' );
	} );

	it( 'finds the body beside the FILE, not beside a renamed store id', async () => {
		// Two pages may share a slug, so the snapshot names the second file
		// `team-3.mdx` while the store id is its numeric WordPress id. `<id>.html`
		// would miss; the sibling of `entry.filePath` is the body.
		const root = await mkdtemp( join( tmpdir(), 'forge-materialized-' ) );
		const dir = join( root, 'src', 'content', 'pages' );
		await mkdir( dir, { recursive: true } );
		await writeFile( join( dir, 'team-3.html' ), '<section><p>Services</p></section>', 'utf8' );

		const store = { entries: [] as StoredEntry[], cleared: false };
		await localOnlyLoader(
			[
				{
					id: '102',
					filePath: 'src/content/pages/team-3.mdx',
					data: { id: 102, slug: 'team', title: 'Services team' },
					digest: 1,
					body: '',
				},
			],
			'./src/content/pages'
		).load( fakeContext( store, pathToFileURL( root ) ) );

		expect( store.entries ).toHaveLength( 1 );
		expect( store.entries[ 0 ].id ).toBe( '102' );
		expect( store.entries[ 0 ].data.slug ).toBe( 'team' );
		expect( store.entries[ 0 ].rendered?.html ).toContain( 'Services' );
	} );

	it( 'warns (never fails) when the snapshot carries nothing for a collection', async () => {
		const store = { entries: [] as StoredEntry[], cleared: false };
		const warnings: string[] = [];
		const context = {
			...fakeContext( store ),
			logger: { warn: ( message: string ) => warnings.push( message ), info: () => {} },
		} as unknown as LoaderContext;

		await localOnlyLoader( [] ).load( context );

		expect( store.entries ).toHaveLength( 0 );
		expect( warnings.join( '\n' ) ).toContain( 'materialized' );
	} );

	it( 'fails loudly when a materialized collection has no local source', async () => {
		const loader = createWpLoader( { site: PROFILE, collection: COLLECTION, localOnly: true } );
		await expect(
			loader.load( fakeContext( { entries: [], cleared: false } ) )
		).rejects.toThrow( /materialized.*no local content source/ );
	} );
} );

describe( 'localEntryId (a materialized snapshot may hold two documents for one slug)', () => {
	it( 'prefers the source numeric id', () => {
		expect( localEntryId( { entry: 'team-3.mdx', data: { id: 102, slug: 'team' } } ) ).toBe( '102' );
	});

	it( 'keeps the slug, then the filename, when there is no id', () => {
		expect( localEntryId( { entry: 'team.mdx', data: { slug: 'team' } } ) ).toBe( 'team' );
		expect( localEntryId( { entry: 'About Us.mdx', data: {} } ) ).toBe( 'about-us' );
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
