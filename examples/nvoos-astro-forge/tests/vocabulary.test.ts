// ---------------------------------------------------------------------------
// vocabulary.test.ts — Proposal 116 §3: the learned widget/block vocabulary
// (block-types registry keyword rules, widget-name collection, the
// learnVocabulary report).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { collectWidgetNames, fetchBlockTypes, learnVocabulary, roleFromBlockType } from '../lib/vocabulary.ts';
import type { SiteProfile } from '../lib/types.ts';

const PROFILE: SiteProfile = {
	url: 'https://example.com',
	name: 'Example',
	collections: [ { slug: 'pages', restBase: 'wp/v2/pages', fields: [ 'id', 'slug' ], kind: 'page' } ],
};

function fakeFetch( routes: Record<string, { status: number; body: string }> ) {
	return ( async ( url: string | URL | Request ) => {
		const raw = String( url instanceof Request ? url.url : url );
		const u = new URL( raw );
		const route = routes[ u.pathname + u.search ];
		if ( ! route ) {
			return new Response( '', { status: 404 } );
		}
		return new Response( route.body, { status: route.status } );
	} ) as typeof fetch;
}

describe( 'roleFromBlockType', () => {
	it( 'maps the registry metadata with deterministic keyword rules', () => {
		expect( roleFromBlockType( { name: 'core/columns', title: 'Columns' } ) ).toEqual( { kind: 'container' } );
		expect( roleFromBlockType( { name: 'core/heading', title: 'Heading' } ) ).toEqual( { kind: 'title' } );
		expect( roleFromBlockType( { name: 'core/paragraph', title: 'Paragraph' } ) ).toEqual( { kind: 'body' } );
		expect( roleFromBlockType( { name: 'core/gallery', title: 'Gallery' } ) ).toEqual( { kind: 'gallery' } );
		expect( roleFromBlockType( { name: 'core/image', title: 'Image' } ) ).toEqual( { kind: 'media' } );
		expect( roleFromBlockType( { name: 'core/quote', title: 'Quote' } ) ).toEqual( { kind: 'quote' } );
		expect( roleFromBlockType( { name: 'core/buttons', title: 'Buttons' } ) ).toEqual( { kind: 'links' } );
		expect( roleFromBlockType( { name: 'core/embed', title: 'Embed' } ) ).toBeUndefined();
	} );
} );

describe( 'collectWidgetNames', () => {
	it( 'harvests every elementor-widget-* name', () => {
		const html = [
			'<div class="elementor-widget elementor-widget-heading">',
			'<div class="elementor-widget elementor-widget-aux_modern_heading">',
			'<div class="elementor-widget elementor-widget-FUTURE_WIDGET">',
		].join( '' );
		expect( collectWidgetNames( html ) ).toEqual( [ 'aux_modern_heading', 'future_widget', 'heading' ] );
	} );
} );

describe( 'learnVocabulary', () => {
	it( 'learns block roles from the registry and reports unknown names', async () => {
		const blockTypes = JSON.stringify( [
			{ name: 'core/paragraph', title: 'Paragraph' },
			{ name: 'core/heading', title: 'Heading' },
			{ name: 'core/embed', title: 'Embed' },
			{ name: 'vendor/mega-grid', title: 'Mega Grid' },
			{ name: 'vendor/new-thing', title: 'New Thing', keywords: [], category: 'design' },
		] );
		const vocabulary = await learnVocabulary( PROFILE, {
			fetch: fakeFetch( {
				'/wp-json/wp/v2/block-types?_fields=name,title,keywords,category&per_page=100&page=1': {
					status: 200,
					body: blockTypes,
				},
			} ),
			htmls: [
				'<div class="elementor-widget elementor-widget-heading"></div><div class="elementor-widget elementor-widget-aux_modern_heading"></div><div class="elementor-widget elementor-widget-mystery_widget"></div>',
			],
		} );

		// Seeded names are skipped (already in the classifier defaults);
		// unseeded structured blocks learn from the registry metadata.
		expect( vocabulary.blockRoles ).toEqual( { 'mega-grid': 'container' } );
		expect( vocabulary.unknownBlocks ).toContain( 'vendor/new-thing' );
		expect( vocabulary.unknownWidgets ).toEqual( [ 'mystery_widget' ] );
	} );

	it( 'degrades to an empty vocabulary when the endpoint is absent', async () => {
		const vocabulary = await learnVocabulary( PROFILE, {
			fetch: fakeFetch( {} ),
			htmls: [ '' ],
		} );
		expect( vocabulary.blockRoles ).toEqual( {} );
		expect( vocabulary.unknownWidgets ).toEqual( [] );
	} );
} );

describe( 'fetchBlockTypes', () => {
	it( 'paginates and stops at a short page', async () => {
		let calls = 0;
		const wrapped = ( async ( url: string | URL | Request ) => {
			calls++;
			const raw = String( url instanceof Request ? url.url : url );
			const page = new URL( raw ).searchParams.get( 'page' );
			if ( page === '1' ) {
				return new Response( JSON.stringify( [ { name: 'core/heading' }, { name: 'core/image' } ] ), { status: 200 } );
			}
			return new Response( '[]', { status: 200 } );
		} ) as typeof fetch;
		const records = await fetchBlockTypes( 'https://example.com', { fetch: wrapped } );
		expect( records ).toHaveLength( 2 );
		expect( calls ).toBe( 1 ); // short page stops the pagination
	} );
} );
