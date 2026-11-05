// ---------------------------------------------------------------------------
// wireframes.test.ts — Proposal 115 §3/§4: captured wireframes from rendered
// source HTML (sanitize → classify → responsive enrichment) vs. content-first
// placeholders for dead URLs, bounded and polite.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { slugFromPath, titleFromSlug, extractWireframes } from '../lib/wireframes.ts';
import type { SiteProfile } from '../lib/types.ts';

const PROFILE: SiteProfile = {
	url: 'https://example.com',
	name: 'Example',
	collections: [
		{ slug: 'pages', restBase: 'wp/v2/pages', fields: [ 'id', 'slug' ], kind: 'page' },
	],
};

const ELEMENTOR_PAGE = [
	'<div data-elementor-type="wp-page">',
	'<div class="elementor-section elementor-top-section"><div class="elementor-container"><div class="elementor-column"><div class="elementor-widget-wrap">',
	'<div class="elementor-widget elementor-widget-heading"><div class="elementor-widget-container"><h2>Meet the team</h2></div></div>',
	'<div class="elementor-widget elementor-widget-icon-box"><div class="elementor-widget-container"><h3>One</h3><p>First.</p></div></div>',
	'<div class="elementor-widget elementor-widget-icon-box"><div class="elementor-widget-container"><h3>Two</h3><p>Second.</p></div></div>',
	'<div class="elementor-widget elementor-widget-icon-box"><div class="elementor-widget-container"><h3>Three</h3><p>Third.</p></div></div>',
	'<div class="elementor-widget elementor-widget-icon-box"><div class="elementor-widget-container"><h3>Four</h3><p>Fourth.</p></div></div>',
	'</div></div></div></div>',
	'</div>',
].join( '' );

function fakeFetch( routes: Record<string, { status: number; body: string }> ) {
	return ( async ( url: string | URL | Request ) => {
		const raw = String( url instanceof Request ? url.url : url );
		const u = new URL( raw );
		const route = routes[ u.pathname ];
		if ( ! route ) {
			return new Response( '', { status: 404 } );
		}
		return new Response( route.body, { status: route.status } );
	} ) as typeof fetch;
}

describe( 'slug helpers', () => {
	it( 'derives slugs and titles from paths', () => {
		expect( slugFromPath( '/about-us/' ) ).toBe( 'about-us' );
		expect( slugFromPath( '/contact/' ) ).toBe( 'contact' );
		expect( titleFromSlug( 'about-us' ) ).toBe( 'About Us' );
	} );
} );

describe( 'extractWireframes', () => {
	it( 'captures a served page: sections + inferred responsive columns', async () => {
		const wireframes = await extractWireframes( PROFILE, [ '/team/' ], {
			fetch: fakeFetch( { '/team/': { status: 200, body: ELEMENTOR_PAGE } } ),
		} );
		expect( Object.keys( wireframes ) ).toEqual( [ 'team' ] );
		const wf = wireframes.team;
		expect( wf.status ).toBe( 'captured' );
		expect( wf.sections.length ).toBeGreaterThan( 0 );
		const grid = wf.sections.find( ( s ) => s.section.slots.items?.length === 4 );
		expect( grid ).toBeDefined();
		expect( grid?.responsive.columns ).toEqual( { desktop: 4, tablet: 2, mobile: 1 } );
		expect( grid?.responsive.columnSource ).toBe( 'inferred' );
		expect( grid?.responsive.breakpoints ).toEqual( [ 'mobile', 'tablet', 'desktop' ] );
		// Never source markup: no elementor classes in the captured slots.
		expect( JSON.stringify( wf ) ).not.toContain( 'elementor-section' );
	} );

	it( 'emits a content-first placeholder for a dead URL', async () => {
		const wireframes = await extractWireframes( PROFILE, [ '/contact/' ], {
			fetch: fakeFetch( { '/contact/': { status: 404, body: '' } } ),
		} );
		expect( wireframes.contact.status ).toBe( 'placeholder' );
		expect( wireframes.contact.sections ).toHaveLength( 1 );
		const section = wireframes.contact.sections[ 0 ];
		expect( section.section.kind ).toBe( 'cta' );
		expect( section.section.slots.title ).toBe( 'Contact' );
		expect( section.section.slots.body?.[ 0 ] ).toContain( 'no longer serves' );
	} );

	it( 'bounds the pages fetched (politeness)', async () => {
		const missing = Array.from( { length: 10 }, ( _, i ) => `/p${ i }/` );
		const wireframes = await extractWireframes( PROFILE, missing, {
			fetch: fakeFetch( {} ),
			maxPages: 3,
		} );
		expect( Object.keys( wireframes ) ).toHaveLength( 3 );
	} );
} );
