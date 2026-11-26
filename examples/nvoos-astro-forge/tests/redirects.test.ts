// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// redirects.test.ts — the redirect-map generator: the rebuild's URL rules
// (posts/pages/products, front page → '/') merged with the stored rules the
// Forge Bridge exports (Proposal 132). Hermetic via an injected fetch.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { fetchBridgeRedirects, generateRedirects } from '../lib/redirects.ts';
import type { SiteProfile } from '../lib/types.ts';
import { WpClient } from '../lib/wp-client.ts';
import {
	fetchBridgeRedirects as fetchTemplateRedirects,
	PORTABLE_STATUSES,
	redirectsUrl,
} from '../templates/blog/src/lib/bridge-redirects.ts';

/**
 * A canned REST backend: path → entries (wp/v2 + wc/v3 shapes), plus the
 * bridge's `/redirects` route. `bridge` is the payload to serve, `'throw'` to
 * simulate a network failure, or `undefined` for a 404 (bridge not installed).
 */
function fakeFetch( tables: Record<string, unknown[]>, bridge?: unknown ): typeof fetch {
	return ( async ( input: string | URL | Request ) => {
		const url = new URL( String( input ) );
		const path = url.pathname.replace( /^\/wp-json\//, '' );

		if ( path === 'nvoos-forge/v1/redirects' ) {
			if ( bridge === 'throw' ) {
				throw new Error( 'ECONNREFUSED' );
			}
			if ( bridge === undefined ) {
				return new Response( JSON.stringify( { code: 'rest_no_route' } ), {
					status: 404,
					headers: { 'content-type': 'application/json' },
				} );
			}
			return new Response( JSON.stringify( bridge ), {
				status: 200,
				headers: { 'content-type': 'application/json' },
			} );
		}

		const entries = tables[ path ];
		if ( ! entries ) {
			return new Response( JSON.stringify( { code: 'rest_no_route' } ), {
				status: 404,
				headers: { 'content-type': 'application/json' },
			} );
		}
		const page = Number( url.searchParams.get( 'page' ) ?? '1' );
		const perPage = Number( url.searchParams.get( 'per_page' ) ?? '100' );
		const start = ( page - 1 ) * perPage;
		const body = entries.slice( start, start + perPage );
		return new Response( JSON.stringify( body ), {
			status: 200,
			headers: {
				'content-type': 'application/json',
				'x-wp-totalpages': String( Math.max( 1, Math.ceil( entries.length / perPage ) ) ),
				'x-wp-total': String( entries.length ),
			},
		} );
	} ) as typeof fetch;
}

const PROFILE: SiteProfile = {
	url: 'https://shop.example.com',
	frontPage: 'home',
	collections: [
		{ slug: 'posts', restBase: 'wp/v2/posts', fields: [ 'id', 'slug', 'link' ] },
		{ slug: 'pages', restBase: 'wp/v2/pages', fields: [ 'id', 'slug', 'link' ] },
		{ slug: 'products', restBase: 'wc/v3/products', fields: [ 'id', 'slug', 'permalink' ], kind: 'product' },
	],
};

const TABLES = {
	'wp/v2/posts': [ { id: 2, slug: 'care-guide', link: 'https://shop.example.com/2026/10/31/care-guide/' } ],
	'wp/v2/pages': [
		{ id: 9, slug: 'home', link: 'https://shop.example.com/home/' },
		{ id: 10, slug: 'about', link: 'https://shop.example.com/about/' },
	],
	'wc/v3/products': [ { id: 101, slug: 'sweater', permalink: 'https://shop.example.com/product/sweater/' } ],
};

describe( 'generateRedirects', () => {
	it( 'maps date-based posts, the front page, and wc/v3 products (permalink)', async () => {
		const client = new WpClient( { baseUrl: 'https://shop.example.com', fetchImpl: fakeFetch( TABLES ) } );
		const { redirects, scanned, truncated } = await generateRedirects( PROFILE, client );

		expect( scanned ).toBe( 4 );
		expect( truncated ).toBe( false );
		// /about/ → /about/ is a no-op (excluded); the front page → /.
		expect( redirects ).toEqual( [
			{ from: '/2026/10/31/care-guide/', to: '/posts/2/care-guide/', status: 301 },
			{ from: '/home/', to: '/', status: 301 },
			{ from: '/product/sweater/', to: '/products/101/sweater/', status: 301 },
		] );
	} );

	it( 'never maps a URL onto itself and stays deterministic', async () => {
		const tables = {
			...TABLES,
			'wp/v2/posts': [ { id: 2, slug: 'care-guide', link: 'https://shop.example.com/posts/2/care-guide/' } ],
		};
		const client = new WpClient( { baseUrl: 'https://shop.example.com', fetchImpl: fakeFetch( tables ) } );
		const { redirects } = await generateRedirects( PROFILE, client );

		// A WP permalink identical to the generated path is a no-op.
		const froms = redirects.map( ( entry ) => entry.from );
		expect( froms ).not.toContain( '/posts/2/care-guide/' );
		expect( froms ).not.toContain( '/about/' );
		expect( redirects ).toContainEqual( { from: '/home/', to: '/', status: 301 } );
		expect( redirects ).toContainEqual( { from: '/product/sweater/', to: '/products/101/sweater/', status: 301 } );
		// Deterministic order.
		expect( froms ).toEqual( [ ...froms ].sort() );
	} );

	it( 'is silent (but successful) when the bridge is not installed', async () => {
		const client = new WpClient( { baseUrl: 'https://shop.example.com', fetchImpl: fakeFetch( TABLES ) } );
		const report = await generateRedirects( PROFILE, client );

		expect( report.bridge ).toBe( 0 );
		expect( report.warnings ).toEqual( [] );
		expect( report.redirects ).toHaveLength( 3 );
	} );

	it( 'merges stored bridge rules and lets the rebuild URL structure win conflicts', async () => {
		const tables = {
			'wp/v2/posts': [ { id: 2, slug: 'care-guide', link: 'https://shop.example.com/2026/10/31/care-guide/' } ],
			'wp/v2/pages': [ { id: 10, slug: 'about', link: 'https://shop.example.com/old-about/' } ],
		};
		const profile: SiteProfile = {
			url: 'https://shop.example.com',
			frontPage: 'home',
			collections: [
				{ slug: 'posts', restBase: 'wp/v2/posts', fields: [ 'id', 'slug', 'link' ] },
				{ slug: 'pages', restBase: 'wp/v2/pages', fields: [ 'id', 'slug', 'link' ] },
			],
		};
		const bridge = {
			available: true,
			enabled: true,
			truncated: false,
			redirects: [
				{ source: '/legacy-guide/', target: '/posts/2/care-guide/', type: 302 },
				{ source: '/gone/', target: '', type: 410 },
				// The rebuild serves /about/ from /old-about/ — that wins.
				{ source: '/old-about/', target: '/elsewhere/', type: 301 },
				// Not a valid source (never redirect the home path).
				{ source: '/', target: '/home/', type: 301 },
				// Not a portable status.
				{ source: '/teapot/', target: '/', type: 418 },
			],
		};
		const client = new WpClient( { baseUrl: 'https://shop.example.com', fetchImpl: fakeFetch( tables, bridge ) } );
		const report = await generateRedirects( profile, client );

		expect( report.bridge ).toBe( 2 );
		expect( report.redirects ).toEqual( [
			{ from: '/2026/10/31/care-guide/', to: '/posts/2/care-guide/', status: 301 },
			{ from: '/gone/', to: '', status: 410 },
			{ from: '/legacy-guide/', to: '/posts/2/care-guide/', status: 302 },
			{ from: '/old-about/', to: '/about/', status: 301 },
		] );
		expect( report.warnings ).toEqual( [] );
	} );

	it( 'reports bridge truncation without dropping the collection map', async () => {
		const bridge = { available: true, enabled: true, truncated: true, redirects: [] };
		const client = new WpClient( { baseUrl: 'https://shop.example.com', fetchImpl: fakeFetch( TABLES, bridge ) } );
		const report = await generateRedirects( PROFILE, client );

		expect( report.truncated ).toBe( true );
		expect( report.redirects ).toHaveLength( 3 );
	} );

	it( 'fails soft on a bridge network error — the map still builds', async () => {
		const client = new WpClient( { baseUrl: 'https://shop.example.com', fetchImpl: fakeFetch( TABLES, 'throw' ) } );
		const report = await generateRedirects( PROFILE, client );

		expect( report.bridge ).toBe( 0 );
		expect( report.warnings ).toHaveLength( 1 );
		expect( report.warnings[ 0 ] ).toContain( 'stored redirects unavailable' );
		expect( report.redirects ).toContainEqual( { from: '/home/', to: '/', status: 301 } );
	} );

	it( 'can skip the bridge entirely', async () => {
		const client = new WpClient( { baseUrl: 'https://shop.example.com', fetchImpl: fakeFetch( TABLES, 'throw' ) } );
		const report = await generateRedirects( PROFILE, client, { bridge: false } );

		expect( report.warnings ).toEqual( [] );
		expect( report.bridge ).toBe( 0 );
		expect( report.redirects ).toHaveLength( 3 );
	} );
} );

describe( 'fetchBridgeRedirects', () => {
	it( 'prefers the profile form-bridge endpoint and sends the token', async () => {
		let seenUrl = '';
		let seenToken = '';
		const fetchImpl = ( async ( input: string | URL | Request, init?: RequestInit ) => {
			seenUrl = String( input );
			seenToken = String( ( init?.headers as Record<string, string> )[ 'x-forge-redirects-token' ] ?? '' );
			return new Response( JSON.stringify( { redirects: [ { source: '/a/', target: '/b/', type: 301 } ] } ), {
				status: 200,
				headers: { 'content-type': 'application/json' },
			} );
		} ) as typeof fetch;

		const profile: SiteProfile = {
			url: 'https://shop.example.com',
			forms: { endpoint: 'https://bridge.example.com/wp-json/nvoos-forge/v1' },
			collections: [],
		};
		const result = await fetchBridgeRedirects( profile, { token: 'shh', fetchImpl } );

		expect( seenUrl ).toBe( 'https://bridge.example.com/wp-json/nvoos-forge/v1/redirects' );
		expect( seenToken ).toBe( 'shh' );
		expect( result.redirects ).toEqual( [ { from: '/a/', to: '/b/', status: 301 } ] );
		expect( result.warnings ).toEqual( [] );
	} );

	it( 'warns on a non-JSON payload instead of throwing', async () => {
		const fetchImpl = ( async () =>
			new Response( '<html>not json</html>', {
				status: 200,
				headers: { 'content-type': 'text/html' },
			} ) ) as typeof fetch;

		const result = await fetchBridgeRedirects(
			{ url: 'https://shop.example.com', collections: [] },
			{ fetchImpl }
		);
		expect( result.redirects ).toEqual( [] );
		expect( result.warnings[ 0 ] ).toContain( 'invalid JSON' );
	} );
} );

describe( 'a materialized profile is never contacted', () => {
	it( 'skips the bridge when the CMS is gone', async () => {
		// `materialized` is a promise that the snapshot is the only source. The
		// profile URL is then the REBUILT site, so the request is a self-request
		// that can only reach the rebuild's fallback page.
		let called = false;
		const fetchImpl = ( async () => {
			called = true;
			return new Response( '{}', { headers: { 'content-type': 'application/json' } } );
		} ) as typeof fetch;
		const profile = {
			url: 'https://shop.example.com',
			collections: [],
			content: { source: 'materialized' },
		} as SiteProfile;

		const result = await fetchBridgeRedirects( profile, { fetchImpl } );

		expect( called ).toBe( false );
		expect( result.redirects ).toEqual( [] );
		expect( result.warnings ).toEqual( [] );
	} );
} );

// ---------------------------------------------------------------------------
// The template's own reader (templates/blog/src/lib/bridge-redirects.ts). The
// build calls it, so a crash there kills the build; it must only ever warn.
// ---------------------------------------------------------------------------
describe( 'templates/blog bridge-redirects', () => {
	const jsonOk = ( payload: unknown ): typeof fetch =>
		( async () =>
			new Response( JSON.stringify( payload ), {
				status: 200,
				headers: { 'content-type': 'application/json' },
			} ) ) as typeof fetch;

	it( 'does NOT throw on an HTML body served at 200', async () => {
		// The krishandramika cut-over: a retired CMS origin answers an unknown
		// path with its own index.html and status 200, so `response.ok` is true
		// and `response.json()` threw `Unexpected token '<'`.
		let warned = '';
		const original = console.warn;
		console.warn = ( line: string ) => {
			warned = String( line );
		};
		try {
			const result = await fetchTemplateRedirects( {
				base: 'https://krishandramikasavethe.world',
				fetchImpl: ( async () =>
					new Response( '<!DOCTYPE html><html><body>Not found</body></html>', {
						status: 200,
						headers: { 'content-type': 'text/html' },
					} ) ) as typeof fetch,
			} );
			expect( result.rows ).toEqual( [] );
			expect( result.warning ).toContain( 'non-JSON' );
		} finally {
			console.warn = original;
		}
		expect( warned ).toBe( '' );
	} );

	it( 'does not call the network for a materialized site', async () => {
		let called = false;
		const result = await fetchTemplateRedirects( {
			base: 'https://krishandramikasavethe.world',
			contentSource: 'materialized',
			fetchImpl: ( async () => {
				called = true;
				return new Response( '{}' );
			} ) as typeof fetch,
		} );
		expect( called ).toBe( false );
		expect( result ).toEqual( { rows: [] } );
	} );

	it( 'stays silent when the bridge is not installed', async () => {
		const result = await fetchTemplateRedirects( {
			base: 'https://shop.example.com',
			fetchImpl: ( async () => new Response( '{}', { status: 404 } ) ) as typeof fetch,
		} );
		expect( result ).toEqual( { rows: [] } );
	} );

	it( 'warns and ships the URL-structure map when the origin is down', async () => {
		const result = await fetchTemplateRedirects( {
			base: 'https://shop.example.com',
			fetchImpl: ( async () => {
				throw new Error( 'ECONNREFUSED' );
			} ) as typeof fetch,
		} );
		expect( result.rows ).toEqual( [] );
		expect( result.warning ).toContain( 'ECONNREFUSED' );
	} );

	it( 'maps the bridge payload and drops what a static host cannot say', async () => {
		const result = await fetchTemplateRedirects( {
			base: 'https://shop.example.com',
			fetchImpl: jsonOk( {
				redirects: [
					{ source: '/old/', target: '/new/', type: 301 },
					{ source: '/gone/', target: '', type: 410 },
					{ source: '/temp/', target: '/next/', type: 302 },
					// Unusable rows: no leading slash, front page, identity, a
					// status a static host cannot express, a 3xx with no target.
					{ source: 'old', target: '/new/', type: 301 },
					{ source: '/', target: '/home/', type: 301 },
					{ source: '/same/', target: '/same/', type: 301 },
					{ source: '/weird/', target: '/x/', type: 200 },
					{ source: '/dangling/', target: '', type: 301 },
				],
			} ),
		} );
		expect( result.rows ).toEqual( [
			{ from: '/old/', to: '/new/', status: 301 },
			{ from: '/gone/', to: '', status: 410 },
			{ from: '/temp/', to: '/next/', status: 302 },
		] );
		expect( result.warning ).toBeUndefined();
	} );

	it( 'resolves the endpoint from an origin or an already-qualified endpoint', () => {
		expect( redirectsUrl( 'https://shop.example.com' ) ).toBe(
			'https://shop.example.com/wp-json/nvoos-forge/v1/redirects'
		);
		expect( redirectsUrl( 'https://shop.example.com/' ) ).toBe(
			'https://shop.example.com/wp-json/nvoos-forge/v1/redirects'
		);
		expect( redirectsUrl( 'https://bridge.example.com/wp-json/nvoos-forge/v1' ) ).toBe(
			'https://bridge.example.com/wp-json/nvoos-forge/v1/redirects'
		);
		expect( redirectsUrl( '' ) ).toBe( '' );
	} );

	it( 'keeps its status set in step with the forge library', () => {
		expect( [ ...PORTABLE_STATUSES ].sort() ).toEqual( [ 301, 302, 303, 307, 308, 404, 410 ].sort() );
	} );
} );

describe( 'CPT entries redirect to their OWN route, not /posts/', () => {
	// The scaffold builds a CPT collection under its `basePath`
	// (`/eca/<id>/<slug>/`); the map used to send every non-core collection to
	// `/posts/<id>/<slug>/`, which 404s (eca.directory, 2026-11-23).
	const profile: SiteProfile = {
		url: 'https://eca.example',
		frontPage: 'home',
		collections: [
			{ slug: 'eca', restBase: 'wp/v2/mcp_ai_eca', basePath: '/eca', fields: [ 'id', 'slug', 'link' ] },
			{ slug: 'case-studies', restBase: 'wp/v2/case_studies', fields: [ 'id', 'slug', 'link' ] },
		],
	};
	const tables = {
		'wp/v2/mcp_ai_eca': [ { id: 10784, slug: 'art-and-craft', link: 'https://eca.example/eca/art-and-craft/' } ],
		'wp/v2/case_studies': [ { id: 5, slug: 'acme', link: 'https://eca.example/case-studies/acme/' } ],
	};

	it( 'uses basePath when declared and /<slug>/ otherwise', async () => {
		const client = new WpClient( { baseUrl: 'https://eca.example', fetchImpl: fakeFetch( tables ) } );
		const { redirects } = await generateRedirects( profile, client );
		expect( redirects ).toContainEqual( { from: '/eca/art-and-craft/', to: '/eca/10784/art-and-craft/', status: 301 } );
		expect( redirects ).toContainEqual( { from: '/case-studies/acme/', to: '/case-studies/5/acme/', status: 301 } );
		expect( redirects.every( ( entry ) => ! entry.to.startsWith( '/posts/' ) ) ).toBe( true );
	} );
} );
