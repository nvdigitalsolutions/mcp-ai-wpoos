// ---------------------------------------------------------------------------
// agent-surfaces.test.ts — Proposal 136 in the storefront.
//
// Three contracts:
//   1. llms.txt keeps the v2 shape (H1, blockquote, fixed section order) and
//      links only URLs that exist (velocity has no `.md` variants yet, so the
//      default variant is `html`).
//   2. The Portable Text allowlist accepts the three new structural kinds and
//      still rejects unknown types loudly (the 107 D3 discipline).
//   3. The structured price the JSON-LD publishes is the SAME string the page
//      renders — the source-side half of the forge's visible-text gate.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { composeLlmsTxt, markdownUrl, pageUrl } from '../../src/lib/llms';
import { parsePortableText } from '../../src/lib/content/portable-text';
import { productJsonLd } from '../../src/lib/seo/jsonld';

const base = { name: 'Storefront', summary: 'Products and editorial.', origin: 'https://shop.example' };

describe( 'composeLlmsTxt (velocity)', () => {
	it( 'emits the H1 first and the blockquote second', () => {
		const lines = composeLlmsTxt( base ).split( '\n' ).filter( Boolean );
		expect( lines[ 0 ] ).toBe( '# Storefront' );
		expect( lines[ 1 ] ).toBe( '> Products and editorial.' );
	} );

	it( 'links canonical pages by default — never a .md URL that does not exist', () => {
		const text = composeLlmsTxt( { ...base, products: [ { path: '/products/1/thing/', title: 'Thing' } ] } );
		expect( text ).toContain( '- [Thing](https://shop.example/products/1/thing/)' );
		expect( text ).not.toContain( 'index.md' );
	} );

	it( 'can link markdown variants once they exist', () => {
		const text = composeLlmsTxt( {
			...base,
			products: [ { path: '/products/1/thing/', title: 'Thing' } ],
			variant: 'markdown',
		} );
		expect( text ).toContain( 'https://shop.example/products/1/thing/index.md' );
	} );

	it( 'omits empty sections and keeps Optional last', () => {
		const text = composeLlmsTxt( { ...base, posts: [ { path: '/blog/1/a/', title: 'A' } ], optional: [ { path: '/search/', title: 'Search' } ] } );
		expect( text ).not.toContain( '## Products' );
		expect( text.indexOf( '## Posts' ) ).toBeLessThan( text.indexOf( '## Optional' ) );
	} );

	it( 'resolves both URL forms', () => {
		expect( pageUrl( 'https://shop.example', '/about/' ) ).toBe( 'https://shop.example/about/' );
		expect( markdownUrl( 'https://shop.example', '/about/' ) ).toBe( 'https://shop.example/about/index.md' );
	} );
} );

describe( 'Portable Text — the 136 block types', () => {
	it( 'accepts a comparison block', () => {
		const blocks = parsePortableText( [
			{
				_type: 'comparison',
				caption: 'Plans',
				columns: [ 'Starter', 'Pro' ],
				rows: [ { label: 'Seats', values: [ '1', '10' ] } ],
			},
		] );
		expect( blocks[ 0 ]._type ).toBe( 'comparison' );
	} );

	it( 'accepts a steps block', () => {
		const blocks = parsePortableText( [
			{ _type: 'steps', items: [ { title: 'Brief' }, { title: 'Build', body: 'We build.' } ] },
		] );
		expect( blocks[ 0 ]._type ).toBe( 'steps' );
	} );

	it( 'accepts a team block', () => {
		const blocks = parsePortableText( [
			{ _type: 'team', people: [ { name: 'Ada', role: 'CEO' }, { name: 'Grace' } ] },
		] );
		expect( blocks[ 0 ]._type ).toBe( 'team' );
	} );

	it( 'still rejects an unknown type loudly', () => {
		expect( () => parsePortableText( [ { _type: 'pricing', plans: [] } ] ) ).toThrow( /invalid blocks/ );
	} );

	it( 'requires at least two steps and two people', () => {
		expect( () => parsePortableText( [ { _type: 'steps', items: [ { title: 'Only one' } ] } ] ) ).toThrow();
		expect( () => parsePortableText( [ { _type: 'team', people: [ { name: 'Ada' } ] } ] ) ).toThrow();
	} );

	it( 'only accepts one comparison column set', () => {
		expect( () =>
			parsePortableText( [ { _type: 'comparison', columns: [ 'Only' ], rows: [ { label: 'x', values: [ 'y' ] } ] } ] )
		).toThrow();
	} );
} );

describe( 'productJsonLd — the published price is the rendered price', () => {
	it( 'carries the product price verbatim', () => {
		const product = {
			id: 7,
			name: 'Field Jacket',
			slug: 'field-jacket',
			description: '<p>A jacket.</p>',
			image: null,
			images: [],
			price: '128.00',
			oldPrice: null,
			variants: [],
			rating: null,
			brand: null,
		} as unknown as Parameters< typeof productJsonLd >[ 0 ];

		const node = productJsonLd( product, 'https://shop.example', 'USD' );
		const offer = node.offers as Record< string, unknown >;

		// The JSON-LD price must be the string the page shows — never reformatted.
		expect( offer.price ).toBe( '128.00' );
		expect( offer.priceCurrency ).toBe( 'USD' );
	} );
} );
