// ---------------------------------------------------------------------------
// redirects.test.ts — the live 301-map generator: the template's exact
// rules (posts/pages/products, front page → '/'), hermetic via an injected
// fetch implementation.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { generateRedirects } from '../lib/redirects.ts';
import type { SiteProfile } from '../lib/types.ts';
import { WpClient } from '../lib/wp-client.ts';

/** A canned REST backend: path → entries (wp/v2 + wc/v3 shapes). */
function fakeFetch( tables: Record<string, unknown[]> ): typeof fetch {
	return ( async ( input: string | URL | Request ) => {
		const url = new URL( String( input ) );
		const path = url.pathname.replace( /^\/wp-json\//, '' );
		const entries = tables[ path ] ?? [];
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

describe( 'generateRedirects', () => {
	it( 'maps date-based posts, the front page, and wc/v3 products (permalink)', async () => {
		const tables = {
			'wp/v2/posts': [ { id: 2, slug: 'care-guide', link: 'https://shop.example.com/2026/10/31/care-guide/' } ],
			'wp/v2/pages': [
				{ id: 9, slug: 'home', link: 'https://shop.example.com/home/' },
				{ id: 10, slug: 'about', link: 'https://shop.example.com/about/' },
			],
			'wc/v3/products': [ { id: 101, slug: 'sweater', permalink: 'https://shop.example.com/product/sweater/' } ],
		};
		const client = new WpClient( { baseUrl: 'https://shop.example.com', fetchImpl: fakeFetch( tables ) } );
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
			'wp/v2/posts': [ { id: 2, slug: 'care-guide', link: 'https://shop.example.com/posts/2/care-guide/' } ],
			'wp/v2/pages': [
				{ id: 9, slug: 'home', link: 'https://shop.example.com/home/' },
				{ id: 10, slug: 'about', link: 'https://shop.example.com/about/' },
			],
			'wc/v3/products': [ { id: 101, slug: 'sweater', permalink: 'https://shop.example.com/product/sweater/' } ],
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
} );
