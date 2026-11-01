// ---------------------------------------------------------------------------
// discovery.test.ts — site_analyze over a canned WordPress backend.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { analyzeSite, profileFromDiscovery, renderReport } from '../lib/discovery.ts';
import { WpClient } from '../lib/wp-client.ts';

interface Route {
	path: string;
	/** All of these query params must be present for the route to match. */
	query?: Record<string, string>;
	body: unknown;
	headers?: Record<string, string>;
	status?: number;
}

/** A canned backend: root doc, types, counts, front-page slug, junk pages. */
function cannedWp(routes: Route[]) {
	const fetchImpl = async (input: string | URL | Request): Promise<Response> => {
		const url = new URL(String(input));
		const path = url.pathname.replace('/wp-json', '') || '/';
		const route = routes
			.filter(
				(r) =>
					r.path === path &&
					Object.entries(r.query ?? {}).every(([key, value]) => url.searchParams.get(key) === value)
			)
			// Most query-constrained route wins — a `per_page=100` route must
			// shadow the unconstrained collection route.
			.sort((a, b) => Object.keys(b.query ?? {}).length - Object.keys(a.query ?? {}).length)[0];
		if (!route) {
			return new Response(JSON.stringify({ code: 'rest_no_route', message: 'no route' }), { status: 404 });
		}
		return new Response(JSON.stringify(route.body), {
			status: route.status ?? 200,
			headers: { 'content-type': 'application/json', ...(route.headers ?? {}) },
		});
	};
	return new WpClient({ baseUrl: 'https://fixture.test', fetchImpl });
}

const HEALTHY_ROUTES: Route[] = [
	{
		path: '/',
		body: {
			name: 'Fixture Wares',
			description: 'Test store',
			url: 'https://fixture.test',
			namespaces: ['wp/v2', 'wc/v3', 'rankmath/v1'],
			show_on_front: 'page',
			page_on_front: 41,
		},
	},
	{
		path: '/wp/v2/types',
		body: {
			post: { name: 'Posts', rest_base: 'posts' },
			page: { name: 'Pages', rest_base: 'pages' },
			product: { name: 'Products', rest_base: 'product' },
		},
	},
	{ path: '/wp/v2/posts', body: [{ id: 1 }], headers: { 'x-wp-total': '2' } },
	{ path: '/wp/v2/pages', body: [{ id: 1 }], headers: { 'x-wp-total': '24' } },
	// wc/v3 requires consumer keys — the count falls back to the public CPT.
	{ path: '/wc/v3/products', body: { code: 'rest_forbidden' }, status: 401 },
	{ path: '/wp/v2/product', body: [{ id: 1 }], headers: { 'x-wp-total': '31' } },
	{ path: '/wp/v2/pages/41', body: { slug: 'home' } },
	{
		path: '/wp/v2/pages',
		query: { per_page: '100' },
		body: [
			{ slug: 'about' },
			{ slug: 'checkout' },
			{ slug: 'manage-profile-2' },
			{ slug: 'terms-and-conditions' },
		],
		headers: { 'x-wp-total': '24' },
	},
];

describe('analyzeSite', () => {
	it('maps a healthy Woo store into a discovery report', async () => {
		const report = await analyzeSite(cannedWp(HEALTHY_ROUTES));

		expect(report.name).toBe('Fixture Wares');
		expect(report.woocommerce).toBe(true);
		expect(report.wooPublic).toBe(false); // wc/v3 401 → keys needed
		expect(report.seoPlugins).toContain('rankmath/v1');
		expect(report.frontPageSlug).toBe('home');
		expect(report.collections).toEqual([
			expect.objectContaining({ slug: 'posts', kind: 'post', count: 2, restBase: 'wp/v2/posts' }),
			expect.objectContaining({ slug: 'pages', kind: 'page', count: 24, restBase: 'wp/v2/pages' }),
			expect.objectContaining({ slug: 'products', kind: 'product', count: 31, restBase: 'wc/v3/products' }),
		]);
		expect(report.junkPages).toEqual(['checkout', 'manage-profile-2']);
		expect(report.lockedDown).toBe(false);
	});

	it('detects REST lockdown when wp/v2/types 404s', async () => {
		const report = await analyzeSite(
			cannedWp([
				{
					path: '/',
					body: { name: 'Locked', description: '', url: 'https://fixture.test', namespaces: [] },
				},
			])
		);
		expect(report.lockedDown).toBe(true);
		expect(report.collections).toEqual([]);
		expect(renderReport(report)).toContain('locked down');
	});

	it('falls back to the wp/v2 CPT when Woo is absent', async () => {
		const routes = HEALTHY_ROUTES.map((route) =>
			route.path === '/'
				? { ...route, body: { ...(route.body as object), namespaces: ['wp/v2'] } }
				: route
		);
		const report = await analyzeSite(cannedWp(routes));
		expect(report.woocommerce).toBe(false);
		expect(report.collections.find((c) => c.slug === 'products')?.restBase).toBe('wp/v2/product');
	});
});

describe('profileFromDiscovery', () => {
	it('drafts a complete scaffold-ready profile', async () => {
		const report = await analyzeSite(cannedWp(HEALTHY_ROUTES));
		const profile = profileFromDiscovery(report);

		expect(profile.url).toBe('https://fixture.test');
		expect(profile.name).toBe('Fixture Wares');
		expect(profile.frontPage).toBe('home');

		const pages = profile.collections.find((c) => c.slug === 'pages');
		expect(pages?.exclude).toEqual(['checkout', 'manage-profile-2']);
		expect(pages?.embed).toBe(true);

		const products = profile.collections.find((c) => c.slug === 'products');
		expect(products?.kind).toBe('product');
		expect(products?.authMode).toBe('wc-consumer');
		expect(products?.fields).toContain('regular_price');
	});

	it('leaves non-product collections without an authMode', async () => {
		const report = await analyzeSite(cannedWp(HEALTHY_ROUTES));
		const profile = profileFromDiscovery(report);
		expect(profile.collections.find((c) => c.slug === 'posts')?.authMode).toBeUndefined();
	});
});
