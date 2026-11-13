// ---------------------------------------------------------------------------
// builder-utilities.test.ts — the vendor utility pack (Proposal 137 follow-up).
//
// The sanitizer keeps the source's class names and the builder's sheet never
// ships, so `text-center`, `text-uppercase` and `padding-four-all` were inert and
// had to be hand-written into global.css. They now live as DATA on the builder
// adapter and are emitted only for the classes a measured page carries — vendor
// conventions without a vendor branch in the template.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { BUILDER_ADAPTERS, builderUtilitiesFor } from '../lib/bands.ts';
import { renderSectionsGenerated } from '../lib/scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';

describe( 'builderUtilitiesFor', () => {
	it( 'returns only the utilities the band actually uses', () => {
		const utilities = builderUtilitiesFor( 'wpbakery', [ 'text-center', 'wpb_column', 'text-medium' ] );

		expect( utilities ).toEqual( {
			'text-center': { base: 'text-align:center' },
			'text-medium': { base: 'font-size:14px;font-weight:500;line-height:24px' },
		} );
	} );

	it( 'carries the device-scoped variant of a responsive utility', () => {
		const utilities = builderUtilitiesFor( 'wpbakery', [ 'xs-padding-40px-tb', 'md-text-center' ] );

		expect( utilities ).toEqual( {
			'xs-padding-40px-tb': { mobile: 'padding-top:40px;padding-bottom:40px' },
			'md-text-center': { tablet: 'text-align:center' },
		} );
	} );

	it( 'returns nothing for an unknown builder, a missing class list, or an empty one', () => {
		expect( builderUtilitiesFor( 'not-a-builder', [ 'text-center' ] ) ).toEqual( {} );
		expect( builderUtilitiesFor( undefined, [ 'text-center' ] ) ).toEqual( {} );
		expect( builderUtilitiesFor( 'wpbakery', undefined ) ).toEqual( {} );
		expect( builderUtilitiesFor( 'wpbakery', [] ) ).toEqual( {} );
	} );

	it( 'does not leak one builder\u2019s conventions into another', () => {
		// The Brando set belongs to wpbakery: an Elementor site using a class of
		// the same name inherits nothing from it.
		const elementor = BUILDER_ADAPTERS.find( ( adapter ) => adapter.id === 'elementor' );
		expect( elementor?.utilities ).toBeUndefined();
		expect( builderUtilitiesFor( 'elementor', [ 'text-center' ] ) ).toEqual( {} );
	} );

	it( 'keeps every declared value a non-empty declaration list', () => {
		for ( const adapter of BUILDER_ADAPTERS ) {
			for ( const [ name, utility ] of Object.entries( adapter.utilities ?? {} ) ) {
				expect( name, `${ adapter.id }:${ name }` ).toMatch( /^[a-z0-9-]+$/ );
				const declarations = [ utility.base, utility.tablet, utility.mobile ].filter( Boolean );
				expect( declarations.length, `${ adapter.id }:${ name }` ).toBeGreaterThan( 0 );
				for ( const value of declarations ) {
					expect( value, `${ adapter.id }:${ name }` ).toMatch( /^[a-z-]+:.+$/ );
				}
			}
		}
	} );
} );

describe( 'wpbakery — the semantic utilities a BOX rule can hide', () => {
	// The class-coverage audit asks "does ANY rule mention this class?". A class
	// that got only its measured box (`padding`/`margin`/`color`) answers yes, so
	// the audit cannot see a missing DECLARATION. That is exactly how
	// virtuallyjamaica's `<span class="uppercase …">` rendered lowercase: the
	// class resolved to a box rule and carried no `text-transform` at all.
	// These assertions are the missing half of that audit.
	const DECLARED: Record< string, string > = {
		// Brando's own casing utility (Bootstrap ships only the `text-*` spelling).
		uppercase: 'text-transform:uppercase',
		'text-uppercase': 'text-transform:uppercase',
		'text-lowercase': 'text-transform:lowercase',
		'text-capitalize': 'text-transform:capitalize',
		'text-transform-none': 'text-transform:none',
		'clear-both': 'clear:both',
		'margin-lr-auto': 'margin-left:auto;margin-right:auto',
		'width-50': 'width:50%',
		'width-90': 'width:90%',
		'no-padding-bottom': 'padding-bottom:0',
		'cover-background':
			'position:relative;overflow:hidden;background-size:cover;background-position:center center;background-repeat:no-repeat',
	};

	it( 'declares every standard utility the sheet defines', () => {
		const wpbakery = BUILDER_ADAPTERS.find( ( adapter ) => adapter.id === 'wpbakery' );
		expect( wpbakery?.utilities ).toBeDefined();
		for ( const [ name, declaration ] of Object.entries( DECLARED ) ) {
			expect( wpbakery?.utilities?.[ name ]?.base, `wpbakery:${ name }` ).toBe( declaration );
		}
	} );

	it( 'emits the casing utility for a band that measures it', () => {
		const utilities = builderUtilitiesFor( 'wpbakery', [ 'uppercase', 'alt-font', 'medium-gray-text' ] );

		expect( utilities.uppercase ).toEqual( { base: 'text-transform:uppercase' } );
		// `medium-gray-text` is deliberately NOT a utility: its measured box
		// already carries the source's `color`, so a declaration here would
		// duplicate a measured fact.
		expect( utilities[ 'medium-gray-text' ] ).toBeUndefined();
	} );
} );

describe( 'sections.generated.ts — utility pack', () => {
	const profile = ( builder: string, classes: string[] ): SiteProfile =>
		( {
			url: 'https://example.com',
			name: 'Fixture',
			collections: [],
			design: { builder, sections: [ { id: 'a1b2c3d', bgColor: '#eeeeee' } ] },
			bandFacts: { a1b2c3d: { containerWidth: 1140, headings: {}, classes } },
		} as unknown as SiteProfile );

	it( 'emits the trimmed pack onto the band theme', () => {
		const code = renderSectionsGenerated( profile( 'wpbakery', [ 'text-center', 'padding-four-all' ] ) );

		expect( code ).toContain( "'text-center': { base: 'text-align:center' }" );
		expect( code ).toContain( "'padding-four-all': { base: 'padding:3.4rem' }" );
	} );

	it( 'emits a responsive utility with its device key', () => {
		const code = renderSectionsGenerated( profile( 'wpbakery', [ 'xs-padding-40px-tb' ] ) );

		expect( code ).toContain( "'xs-padding-40px-tb': { mobile: 'padding-top:40px;padding-bottom:40px' }" );
	} );

	it( 'omits the key when the page uses no utility', () => {
		const code = renderSectionsGenerated( profile( 'wpbakery', [ 'wpb_column' ] ) );

		expect( code ).not.toContain( 'utilities: {' );
	} );

	it( 'emits nothing for a builder with no pack', () => {
		const code = renderSectionsGenerated( profile( 'elementor', [ 'text-center' ] ) );

		expect( code ).not.toContain( 'utilities: {' );
	} );
} );
