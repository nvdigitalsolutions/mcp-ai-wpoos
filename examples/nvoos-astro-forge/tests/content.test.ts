// ---------------------------------------------------------------------------
// content.test.ts — the Proposal 106 P2 hybrid-local content layer: the
// glob+WP merge (WP wins on collision, drafts never ship, local fills the
// gaps), the offline tolerance, the SEO-meta extraction (Rank Math + Yoast,
// absent plugins never crash), and the shared content schema normalization.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import type { Loader, LoaderContext } from 'astro/loaders';

import {
	contentSchema,
	createWpLoader,
	extractRaw,
	isDraftEntry,
	isOfflineError,
} from '../lib/wp-loader.ts';
import { WpClientError } from '../lib/wp-client.ts';
import type { SiteProfile } from '../lib/types.ts';

interface StoredEntry {
	id: string;
	data: Record<string, unknown>;
	digest?: number | string;
	rendered?: { html: string };
}

/** A real map-backed store double — the hybrid merge reads/writes it. */
function fakeStore() {
	const map = new Map<string, StoredEntry>();
	const warnings: string[] = [];
	return {
		store: {
			entries: () => [...map.entries()],
			has: (key: string) => map.has(key),
			set: (entry: StoredEntry) => {
				map.set(entry.id, entry);
				return true;
			},
			delete: (key: string) => {
				map.delete(key);
			},
			clear: () => {
				map.clear();
			},
			keys: () => [...map.keys()],
			values: () => [...map.values()],
		},
		map,
		warnings,
	};
}

function fakeContext(store: ReturnType<typeof fakeStore>['store']): LoaderContext {
	return {
		collection: 'posts',
		store: store as unknown as LoaderContext['store'],
		meta: { get: () => undefined, set: () => {} },
		logger: { warn: () => {}, info: () => {} },
		config: {},
		parseData: async <T extends Record<string, unknown>>(props: { data: T }) => props.data,
		renderMarkdown: async () => ({ html: '' }),
		generateDigest: (data: string | Record<string, unknown>) => `digest:${String(data)}`,
	} as unknown as LoaderContext;
}

const PROFILE: SiteProfile = {
	url: 'https://fixture.test',
	collections: [
		{
			slug: 'posts',
			restBase: 'wp/v2/posts',
			fields: [
				'id', 'date', 'modified_gmt', 'title', 'slug', 'link', 'content', 'excerpt',
				'rank_math_title', 'rank_math_description', 'yoast_head_json',
			],
			embed: true,
		},
	],
};

const COLLECTION = PROFILE.collections[0];
const LOCAL = { pattern: '**/*.mdx', base: './src/content/posts' };

/** A fake local loader — sets entries the way astro's glob() would. */
function fakeLocalLoader(entries: Array<{ id: string; data: Record<string, unknown> }>): Loader {
	return {
		name: 'glob-loader',
		load: async (context) => {
			for (const entry of entries) {
				context.store.set({ id: entry.id, data: entry.data });
			}
		},
	};
}

function cannedFetch(entries: Record<string, unknown>[]): typeof fetch {
	return async (input: string | URL | Request): Promise<Response> => {
		const url = new URL(String(input));
		void url;
		return new Response(JSON.stringify(entries), {
			status: 200,
			headers: { 'content-type': 'application/json', 'x-wp-totalpages': '1' },
		});
	};
}

async function runHybrid(options: {
	local: Array<{ id: string; data: Record<string, unknown> }>;
	fetch: typeof fetch;
}) {
	const fake = fakeStore();
	const loader = createWpLoader({
		site: PROFILE,
		collection: COLLECTION,
		local: LOCAL,
		localLoaderFactory: () => fakeLocalLoader(options.local),
	});
	const originalFetch = globalThis.fetch;
	(globalThis as { fetch: typeof fetch }).fetch = options.fetch;
	try {
		await loader.load(
			Object.assign(fakeContext(fake.store), {
				logger: { warn: (message: string) => fake.warnings.push(message), info: () => {} },
			})
		);
	} finally {
		(globalThis as { fetch: typeof fetch }).fetch = originalFetch;
	}
	return fake;
}

describe('hybrid local merge (Proposal 106 P2)', () => {
	it('WP wins on store-id collision and local fills the gaps', async () => {
		const { store, map } = await runHybrid({
			local: [
				{ id: 'hello-world', data: { title: 'Hello local', slug: 'hello-world', date: '2026-11-03' } },
				{ id: '42', data: { title: 'Local impostor', slug: 'local-42', date: '2026-11-03' } },
				// No slug in the frontmatter — the merge fills it from the glob id.
				{ id: 'slugless', data: { title: 'Slugless', date: '2026-11-03' } },
			],
			fetch: cannedFetch([
				{ id: 42, date: '2026-10-01T00:00:00', modified_gmt: '2026-10-02T00:00:00', title: { rendered: 'WP wins' }, slug: 'wp-wins', link: 'https://fixture.test/wp-wins/', content: { rendered: '<p>WP body</p>' }, excerpt: { rendered: '' } },
			]),
		});
		expect(map.size).toBe(3);
		expect(map.get('42')?.data.title).toBe('WP wins'); // WP beats the local impostor
		expect(map.get('hello-world')?.data.title).toBe('Hello local'); // local fills the gap
		expect(map.get('slugless')?.data.slug).toBe('slugless'); // filled from the glob id
		expect(store.has('42')).toBe(true);
	});

	it('never ships draft local entries (the frontmatter contract)', async () => {
		const { map } = await runHybrid({
			local: [
				{ id: 'published', data: { title: 'Ships', slug: 'published', date: '2026-11-03', draft: false } },
				{ id: 'wip', data: { title: 'Hidden', slug: 'wip', date: '2026-11-03', draft: true } },
			],
			fetch: cannedFetch([]),
		});
		expect(map.has('published')).toBe(true);
		expect(map.has('wip')).toBe(false);
		expect(isDraftEntry({ data: { draft: true } })).toBe(true);
		expect(isDraftEntry({ data: { draft: false } })).toBe(false);
		expect(isDraftEntry({ data: {} })).toBe(false);
	});

	it('degrades to local-only with a warning when WP is unreachable', async () => {
		const { map, warnings } = await runHybrid({
			local: [{ id: 'hello-world', data: { title: 'Hello local', slug: 'hello-world', date: '2026-11-03' } }],
			fetch: (async () => {
				throw new TypeError('fetch failed');
			}) as unknown as typeof fetch,
		});
		expect(map.has('hello-world')).toBe(true);
		expect(warnings.join('\n')).toContain('serving local content only');
	});

	it('still fails on HTTP-level errors (auth is never silent)', async () => {
		const throwing = (async () => {
			throw new WpClientError('wp_auth_required', 401, 'unauthorized');
		}) as unknown as typeof fetch;
		await expect(
			runHybrid({ local: [{ id: 'x', data: { title: 'x' } }], fetch: throwing })
		).rejects.toThrow(/unauthorized/);
	});

	it('classifies only network-level failures as offline', () => {
		expect(isOfflineError(new TypeError('fetch failed'))).toBe(true);
		expect(isOfflineError(new WpClientError('wp_auth_required', 401, 'no'))).toBe(false);
		expect(isOfflineError(new Error('boom'))).toBe(false);
	});
});

describe('SEO meta extraction (Proposal 106 §1.2)', () => {
	it('reads Rank Math fields first', () => {
		const raw = extractRaw({
			id: 1, title: { rendered: 'Post' }, slug: 'post', content: { rendered: '<p>x</p>' },
			rank_math_title: 'Rank title', rank_math_description: 'Rank description',
		});
		expect(raw.seoTitle).toBe('Rank title');
		expect(raw.seoDescription).toBe('Rank description');
	});

	it('falls back to the Yoast head JSON', () => {
		const raw = extractRaw({
			id: 2, title: { rendered: 'Post' }, slug: 'post', content: { rendered: '<p>x</p>' },
			yoast_head_json: { title: 'Yoast title', description: 'Yoast description' },
		});
		expect(raw.seoTitle).toBe('Yoast title');
		expect(raw.seoDescription).toBe('Yoast description');
	});

	it('tolerates absent plugins/fields (never crashes, values stay undefined)', () => {
		const raw = extractRaw({ id: 3, title: { rendered: 'Post' }, slug: 'post', content: { rendered: '<p>x</p>' } });
		expect(raw.seoTitle).toBeUndefined();
		expect(raw.seoDescription).toBeUndefined();
		const odd = extractRaw({
			id: 4, title: { rendered: 'Post' }, slug: 'post', content: { rendered: '<p>x</p>' },
			rank_math_title: null, rank_math_description: 42, yoast_head_json: 'not-an-object',
		});
		expect(odd.seoTitle).toBeUndefined();
		expect(odd.seoDescription).toBeUndefined();
	});
});

describe('contentSchema (the shared posts/pages schema)', () => {
	it('normalizes local frontmatter into the canonical shape', () => {
		const parsed = contentSchema.parse({
			title: 'Hello',
			description: 'The description',
			date: new Date('2026-11-03T00:00:00Z'),
			draft: false,
			unrelated: 'stripped',
		});
		expect(parsed.title).toBe('Hello');
		expect(parsed.excerpt).toBe('The description'); // description fills the excerpt
		expect(parsed.date).toBe('2026-11-03T00:00:00.000Z'); // Date normalized to ISO
		expect(parsed.slug).toBe(''); // no slug — the entry id (filename slug) fills it
		expect(parsed.draft).toBe(false);
		expect(parsed).not.toHaveProperty('unrelated'); // unknown keys stripped
	});

	it('derives the slug from a string id and passes WP shapes through', () => {
		const parsed = contentSchema.parse({
			id: 'hello-world', title: 'Hello', date: '2026-11-03',
			slug: 'hello-world', modified: '2026-11-04', excerpt: '<p>E</p>', seoTitle: 'SEO',
		});
		expect(parsed.slug).toBe('hello-world');
		expect(parsed.modified).toBe('2026-11-04');
		expect(parsed.excerpt).toBe('<p>E</p>');
		expect(parsed.seoTitle).toBe('SEO');
		expect(parsed.date).toBe('2026-11-03');
	});

	it('normalizes unquoted YAML dates (Date instances) and empty strings', () => {
		expect(contentSchema.parse({ title: 'T' }).date).toBe('');
		expect(contentSchema.parse({ title: 'T', date: new Date(0) }).date).toBe('1970-01-01T00:00:00.000Z');
	});
});
