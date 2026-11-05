// ---------------------------------------------------------------------------
// inventory.test.ts — Proposal 115 §1/§2: multi-source inventory (sitemap
// index + homepage links + optional Wayback), the REST diff, the site-shape
// verdict, and anchor reconciliation.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { discoverInventory, normalizePath } from '../lib/inventory.ts';
import type { SiteProfile } from '../lib/types.ts';

const PROFILE: SiteProfile = {
	url: 'https://example.com',
	name: 'Example',
	frontPage: 'home',
	collections: [
		{
			slug: 'pages',
			restBase: 'wp/v2/pages',
			fields: [ 'id', 'slug', 'link' ],
			kind: 'page',
			exclude: [ 'login-customizer' ],
		},
		{ slug: 'posts', restBase: 'wp/v2/posts', fields: [ 'id', 'slug' ], kind: 'post' },
	],
};

function fakeFetch( routes: Record<string, { status: number; body: string }> ) {
	return ( async ( url: string | URL | Request ) => {
		const raw = String( url instanceof Request ? url.url : url );
		if ( raw.includes( 'web.archive.org/cdx' ) ) {
			return new Response( routes[ '__cdx__' ]?.body ?? '', { status: routes[ '__cdx__' ]?.status ?? 404 } );
		}
		const u = new URL( raw );
		const key = `${ u.pathname }${ u.search }`;
		const route = routes[ key ];
		if ( ! route ) {
			return new Response( '', { status: 404 } );
		}
		return new Response( route.body, { status: route.status } );
	} ) as typeof fetch;
}

const INDEX_XML = [
	'<?xml version="1.0" encoding="UTF-8"?>',
	'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
	'<sitemap><loc>https://example.com/wp-sitemap-pages-1.xml</loc></sitemap>',
	'</sitemapindex>',
].join( '' );

const SUB_XML = [
	'<?xml version="1.0" encoding="UTF-8"?>',
	'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
	'<url><loc>https://example.com/team/</loc></url>',
	'<url><loc>https://example.com/about/</loc></url>',
	'</urlset>',
].join( '' );

const HOME_HTML = [
	'<html><body>',
	'<nav><a href="#about">About</a><a href="/services/">Services</a></nav>',
	'<a href="/contact/">Contact</a>',
	'<a href="/wp-content/uploads/x.png">img</a>',
	'</body></html>',
].join( '' );

const REST_PAGES = JSON.stringify( [
	{ slug: 'home', link: 'https://example.com/home/' },
	{ slug: 'services', link: 'https://example.com/services/' },
	{ slug: 'login-customizer', link: 'https://example.com/login-customizer/' },
] );

describe( 'normalizePath', () => {
	it( 'normalizes, filters assets and skips cross-origin URLs', () => {
		expect( normalizePath( '/About/', 'https://example.com' ) ).toBe( '/about/' );
		expect( normalizePath( 'https://example.com/team/?x=1#top', 'https://example.com' ) ).toBe( '/team/' );
		expect( normalizePath( '/wp-content/uploads/x.png' ) ).toBeNull();
		expect( normalizePath( 'https://other.com/x/' , 'https://example.com' ) ).toBeNull();
		expect( normalizePath( 'https://example.com/' , 'https://example.com' ) ).toBe( '/' );
	} );
} );

describe( 'discoverInventory', () => {
	it( 'merges sitemap + links, diffs against REST, and verifies a hybrid shape', async () => {
		const inventory = await discoverInventory( PROFILE, {
			fetch: fakeFetch( {
				'/wp-sitemap.xml': { status: 200, body: INDEX_XML },
				'/wp-sitemap-pages-1.xml': { status: 200, body: SUB_XML },
				'/': { status: 200, body: HOME_HTML },
				'/wp-json/wp/v2/pages?_fields=slug,link&per_page=100&page=1': { status: 200, body: REST_PAGES },
			} ),
		} );

		expect( inventory.shape ).toBe( 'hybrid' );
		expect( inventory.navAnchors ).toEqual( [ 'about' ] );
		expect( inventory.navPages ).toEqual( [ '/services/' ] );
		expect( inventory.covered ).toContain( '/services/' );
		expect( inventory.excluded ).toContain( '/login-customizer/' );
		expect( inventory.missing.sort() ).toEqual( [ '/about/', '/contact/', '/team/' ] );
		expect( inventory.danglingAnchors ).toEqual( [ 'about' ] );
	} );

	it( 'verifies a one-pager when the nav is all anchors', async () => {
		const home = '<nav><a href="#services">Services</a><a href="#products">Products</a></nav>';
		const inventory = await discoverInventory( PROFILE, {
			fetch: fakeFetch( {
				'/': { status: 200, body: home },
				'/wp-json/wp/v2/pages?_fields=slug,link&per_page=100&page=1': { status: 200, body: '[]' },
			} ),
		} );
		expect( inventory.shape ).toBe( 'one-pager' );
		expect( inventory.navAnchors ).toEqual( [ 'products', 'services' ] );
	} );

	it( 'adds Wayback discovery when enabled', async () => {
		const cdx = JSON.stringify( [
			[ 'original' ],
			[ 'https://example.com/legacy-page/' ],
			[ 'https://example.com/wp-content/uploads/x.png' ],
		] );
		const inventory = await discoverInventory( PROFILE, {
			fetch: fakeFetch( {
				'/': { status: 200, body: '<nav><a href="/services/">S</a></nav>' },
				'/wp-json/wp/v2/pages?_fields=slug,link&per_page=100&page=1': { status: 200, body: '[]' },
				'__cdx__': { status: 200, body: cdx },
			} ),
			wayback: true,
		} );
		expect( inventory.missing ).toContain( '/legacy-page/' );
		expect( inventory.missing.some( ( p ) => p.includes( 'uploads' ) ) ).toBe( false );
	} );
} );
