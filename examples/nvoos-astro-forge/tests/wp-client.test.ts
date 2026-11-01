// ---------------------------------------------------------------------------
// wp-client.test.ts — hermetic tests for the WordPress REST client.
//
// No network: a canned fetchImpl serves the wp/v2 wire shapes (JSON bodies +
// X-WP-TotalPages headers) and records every request so the anti-N+1
// discipline (`_fields` pruning + `_embed`) is asserted, not assumed.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { WpClient, WpClientError } from '../lib/wp-client.ts';
import type { SiteProfile } from '../lib/types.ts';

interface RecordedRequest {
	url: string;
	headers: Record<string, string>;
}

/** Build a canned WordPress REST backend. */
function cannedWp(entries: Record<string, unknown>[], totalPages = 1) {
	const requests: RecordedRequest[] = [];
	const fetchImpl = async (input: string | URL | Request, init?: RequestInit): Promise<Response> => {
		const url = new URL(String(input));
		requests.push({ url: url.toString(), headers: (init?.headers ?? {}) as Record<string, string> });
		const path = url.pathname.replace('/wp-json/', '');
		const page = Number(url.searchParams.get('page') ?? '1');

		// Root document.
		if (path === '') {
			return json({ name: 'Fixture Site', description: '', url: 'https://fixture.test', namespaces: ['wp/v2'] });
		}
		// Post types.
		if (path === 'wp/v2/types') {
			return json({
				post: { name: 'Posts', rest_base: 'posts' },
				page: { name: 'Pages', rest_base: 'pages' },
			});
		}
		// Paginated collection — slice the canned entries by page.
		const perPage = Number(url.searchParams.get('per_page') ?? '100');
		const slice = entries.slice((page - 1) * perPage, page * perPage);
		return json(slice, { 'x-wp-totalpages': String(totalPages), 'x-wp-total': String(entries.length) });
	};
	return { fetchImpl, requests };
}

function json(body: unknown, extra: Record<string, string> = {}): Response {
	return new Response(JSON.stringify(body), {
		status: 200,
		headers: { 'content-type': 'application/json', ...extra },
	});
}

const PROFILE: SiteProfile = {
	url: 'https://fixture.test',
	collections: [{ slug: 'posts', restBase: 'wp/v2/posts', fields: ['id', 'date', 'title', 'link', 'content'] }],
};

describe('WpClient pagination', () => {
	it('follows X-WP-TotalPages until the natural end', async () => {
		const entries = Array.from({ length: 250 }, (_, i) => ({ id: i + 1 }));
		const { fetchImpl, requests } = cannedWp(entries, 3);
		const client = new WpClient({ baseUrl: 'https://fixture.test', fetchImpl });

		const result = await client.fetchAll('wp/v2/posts', { perPage: 100 });

		expect(result.entries).toHaveLength(250);
		expect(result.pages).toBe(3);
		expect(result.truncated).toBe(false);
		expect(requests).toHaveLength(3);
		expect(requests.map((r) => new URL(r.url).searchParams.get('page'))).toEqual(['1', '2', '3']);
	});

	it('flags truncation when the page cap stops the fetch early', async () => {
		const entries = Array.from({ length: 500 }, (_, i) => ({ id: i + 1 }));
		const { fetchImpl } = cannedWp(entries, 10);
		const client = new WpClient({ baseUrl: 'https://fixture.test', fetchImpl });

		const result = await client.fetchAll('wp/v2/posts', { perPage: 100, maxPages: 3 });

		expect(result.entries).toHaveLength(300);
		expect(result.pages).toBe(3);
		expect(result.truncated).toBe(true);
	});

	it('does not flag truncation when the cap exactly matches the page count', async () => {
		const entries = Array.from({ length: 300 }, (_, i) => ({ id: i + 1 }));
		const { fetchImpl } = cannedWp(entries, 3);
		const client = new WpClient({ baseUrl: 'https://fixture.test', fetchImpl });

		const result = await client.fetchAll('wp/v2/posts', { perPage: 100, maxPages: 3 });

		expect(result.entries).toHaveLength(300);
		expect(result.truncated).toBe(false);
	});
});

describe('WpClient anti-N+1 discipline', () => {
	it('sends `_fields` pruning and `_embed` when configured', async () => {
		const { fetchImpl, requests } = cannedWp([{ id: 1 }], 1);
		const client = new WpClient({ baseUrl: 'https://fixture.test', fetchImpl });

		await client.fetchAll('wp/v2/posts', {
			fields: ['id', 'title', 'content'],
			embed: true,
		});

		const url = new URL(requests[0].url);
		expect(url.searchParams.get('_fields')).toBe('id,title,content');
		expect(url.searchParams.get('_embed')).toBe('1');
		expect(url.searchParams.get('per_page')).toBe('100');
	});
});

describe('WpClient auth', () => {
	it('sends Basic auth headers', async () => {
		const { fetchImpl, requests } = cannedWp([], 1);
		const client = new WpClient({
			baseUrl: 'https://fixture.test',
			auth: { type: 'basic', username: 'user', password: 'pass' },
			fetchImpl,
		});

		await client.fetchAll('wp/v2/posts');

		const expected = `Basic ${Buffer.from('user:pass').toString('base64')}`;
		expect(requests[0].headers.authorization).toBe(expected);
	});

	it('sends Bearer auth headers', async () => {
		const { fetchImpl, requests } = cannedWp([], 1);
		const client = new WpClient({
			baseUrl: 'https://fixture.test',
			auth: { type: 'bearer', token: 'secret-token' },
			fetchImpl,
		});

		await client.fetchAll('wp/v2/posts');

		expect(requests[0].headers.authorization).toBe('Bearer secret-token');
	});
});

describe('WpClient errors', () => {
	it('maps 401/403 to wp_auth_required', async () => {
		const client = new WpClient({
			baseUrl: 'https://fixture.test',
			fetchImpl: async () => new Response('{}', { status: 401 }),
		});
		await expect(client.fetchAll('wp/v2/posts')).rejects.toMatchObject({
			name: 'WpClientError',
			code: 'wp_auth_required',
			status: 401,
		});
	});

	it('maps 404 to wp_not_found', async () => {
		const client = new WpClient({
			baseUrl: 'https://fixture.test',
			fetchImpl: async () => new Response('{}', { status: 404 }),
		});
		await expect(client.fetchAll('wp/v2/missing')).rejects.toMatchObject({
			name: 'WpClientError',
			code: 'wp_not_found',
			status: 404,
		});
	});

	it('maps other failures to wp_http_error with the status', async () => {
		const client = new WpClient({
			baseUrl: 'https://fixture.test',
			fetchImpl: async () => new Response('{}', { status: 500 }),
		});
		await expect(client.fetchAll('wp/v2/posts')).rejects.toMatchObject({
			name: 'WpClientError',
			code: 'wp_http_error',
			status: 500,
		});
	});
});

describe('WpClient discovery', () => {
	it('reads the root document', async () => {
		const { fetchImpl } = cannedWp([], 1);
		const client = new WpClient({ baseUrl: 'https://fixture.test', fetchImpl });

		const root = await client.root();

		expect(root.name).toBe('Fixture Site');
		expect(root.namespaces).toContain('wp/v2');
	});

	it('lists post types with rest bases', async () => {
		const { fetchImpl } = cannedWp([], 1);
		const client = new WpClient({ baseUrl: 'https://fixture.test', fetchImpl });

		const types = await client.postTypes();

		expect(types.post.rest_base).toBe('posts');
		expect(types.page.name).toBe('Pages');
	});

	it('builds from a site profile', () => {
		const client = WpClient.fromProfile(PROFILE);
		expect(client).toBeInstanceOf(WpClient);
	});
});

describe('WpClient edge cases', () => {
	it('tolerates a missing x-wp-totalpages header (single page)', async () => {
		const fetchImpl = async (): Promise<Response> => json([{ id: 1 }]);
		const client = new WpClient({ baseUrl: 'https://fixture.test', fetchImpl });

		const result = await client.fetchAll('wp/v2/posts');

		expect(result.entries).toHaveLength(1);
		expect(result.pages).toBe(1);
		expect(result.truncated).toBe(false);
	});

	it('strips trailing slashes from the base URL', async () => {
		const { fetchImpl, requests } = cannedWp([], 1);
		const client = new WpClient({ baseUrl: 'https://fixture.test///', fetchImpl });

		await client.root();

		expect(requests[0].url).toBe('https://fixture.test/wp-json/');
	});
});
