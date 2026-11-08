// ---------------------------------------------------------------------------
// redirects.test.ts — the redirect-map generator: the rebuild's URL rules
// (posts/pages/products, front page → '/') merged with the stored rules the
// Forge Bridge exports (Proposal 132). Hermetic via an injected fetch.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { fetchBridgeRedirects, generateRedirects } from '../lib/redirects.ts';
import type { SiteProfile } from '../lib/types.ts';
import { WpClient } from '../lib/wp-client.ts';

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
