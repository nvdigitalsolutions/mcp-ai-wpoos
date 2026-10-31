// ---------------------------------------------------------------------------
// wp-loader.test.ts — the Astro content-layer loader against a fake LoaderContext.
//
// Asserts the loader contract: name + schema exposed, store cleared up front,
// digest from modified_gmt, sanitized rendered HTML, featured image wiring,
// and the truncation flag propagation (096: never silent truncation).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { createWpLoader } from '../lib/wp-loader.ts';
import type { SiteProfile } from '../lib/types.ts';
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
});
