// ---------------------------------------------------------------------------
// interactions.test.ts — measured hover / focus / active states, as emitted CSS.
//
// A rebuilt band is the source's own markup with the vendor sheet gone, so every
// state the builder expressed in CSS disappears: a CTA that darkens on hover, a
// button that presses in, a field that lights up on focus. `lib/interactions.ts`
// measures those states by forcing each pseudo-class in a real browser and
// diffing computed styles; these tests pin the two pure steps that decide what
// is emitted — the rest paint a state is relative to, and the delta itself.
//
// The filters matter more than they look: re-stating a state that REMOVES the
// focus ring is the classic accessibility failure (WCAG F78), and re-stating an
// invisible decoration colour is pure noise.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { interactionSelector, restDeclarations, stateDeclarations } from '../lib/interactions.ts';
import { renderSectionsGenerated } from '../lib/scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';

/** A computed-style snapshot with the properties the module reads. */
function styles( overrides: Record< string, string > = {} ): Record< string, string > {
	return {
		color: 'rgb(0, 0, 0)',
		backgroundColor: 'rgba(0, 0, 0, 0)',
		backgroundImage: 'none',
		borderTopWidth: '0px',
		borderTopStyle: 'none',
		borderTopColor: 'rgb(0, 0, 0)',
		borderRightWidth: '0px',
		borderRightStyle: 'none',
		borderRightColor: 'rgb(0, 0, 0)',
		borderBottomWidth: '0px',
		borderBottomStyle: 'none',
		borderBottomColor: 'rgb(0, 0, 0)',
		borderLeftWidth: '0px',
		borderLeftStyle: 'none',
		borderLeftColor: 'rgb(0, 0, 0)',
		borderRadius: '0px',
		boxShadow: 'none',
		outlineColor: 'rgb(0, 0, 0)',
		outlineStyle: 'none',
		outlineWidth: '0px',
		outlineOffset: '0px',
		textDecorationLine: 'none',
		textDecorationColor: 'rgb(0, 0, 0)',
		textDecorationThickness: 'auto',
		opacity: '1',
		transform: 'none',
		filter: 'none',
		letterSpacing: 'normal',
		fontWeight: '400',
		textTransform: 'none',
		...overrides,
	};
}

describe( 'interactionSelector', () => {
	it( 'builds a whole-token attribute selector from the sorted class set', () => {
		expect(
			interactionSelector( { tag: 'a', classes: [ 'btn', 'btn-small', 'button' ] } )
		).toBe( 'a[class~="btn"][class~="btn-small"][class~="button"]' );
	} );

	it( 'keeps the type on inputs so a field is not styled as a button', () => {
		expect( interactionSelector( { tag: 'input', classes: [], type: 'submit' } ) ).toBe(
			'input[type="submit"]'
		);
	} );

	it( 'skips a class token that would break out of the attribute value', () => {
		expect(
			interactionSelector( { tag: 'a', classes: [ 'ok', 'bad"]' ] } )
		).toBe( 'a[class~="ok"]' );
	} );

	it( 'falls back to the bare tag for an unclassed control', () => {
		expect( interactionSelector( { tag: 'button', classes: [] } ) ).toBe( 'button' );
	} );
} );

describe( 'restDeclarations', () => {
	it( 're-states the source paint a hover delta is relative to', () => {
		const declarations = restDeclarations(
			styles( {
				color: 'rgb(226, 226, 226)',
				backgroundColor: 'rgb(221, 51, 51)',
				fontWeight: '600',
				textTransform: 'uppercase',
				borderTopWidth: '2px',
				borderTopStyle: 'solid',
				borderTopColor: 'rgb(221, 51, 51)',
			} ),
			'pointer'
		);

		expect( declarations ).toBe(
			'cursor:pointer;color:rgb(226, 226, 226);background-color:rgb(221, 51, 51);font-weight:600;text-transform:uppercase;border-top:2px solid rgb(221, 51, 51)'
		);
	} );

	it( 'drops the no-ops a computed style always reports', () => {
		// The control's INK always survives — it is the source's own colour —
		// everything else here is a browser default and must not be re-stated.
		expect( restDeclarations( styles(), 'auto' ) ).toBe( 'color:rgb(0, 0, 0)' );
		expect( restDeclarations( styles( { borderRadius: '4px' } ), 'auto' ) ).toBe(
			'color:rgb(0, 0, 0);border-radius:4px'
		);
	} );

	it( 'never re-states a background image URL (the media gate owns that)', () => {
		expect(
			restDeclarations( styles( { backgroundImage: 'url("https://example.com/a.png")' } ), 'auto' )
		).toBe( 'color:rgb(0, 0, 0)' );
		expect(
			restDeclarations( styles( { backgroundImage: 'linear-gradient(red, blue)' } ), 'auto' )
		).toBe( 'color:rgb(0, 0, 0);background-image:linear-gradient(red, blue)' );
	} );

	it( 'emits a border side only when it is actually drawn', () => {
		expect(
			restDeclarations( styles( { borderLeftWidth: '1px', borderLeftStyle: 'solid' } ), 'auto' )
		).toBe( 'color:rgb(0, 0, 0);border-left:1px solid rgb(0, 0, 0)' );
	} );
} );

describe( 'stateDeclarations', () => {
	it( 'keeps only the properties the state CHANGED', () => {
		const rest = styles( { color: 'rgb(254, 108, 97)' } );
		const hover = styles( { color: 'rgb(255, 255, 255)', backgroundColor: 'rgb(0, 0, 0)' } );

		expect( stateDeclarations( rest, hover ) ).toBe( 'color:rgb(255, 255, 255);background-color:rgb(0, 0, 0)' );
	} );

	it( 'returns nothing when the state changes nothing', () => {
		expect( stateDeclarations( styles(), styles() ) ).toBe( '' );
	} );

	it( 'refuses to reproduce a state that REMOVES the focus ring (WCAG F78)', () => {
		// The source's real habit: `a:hover { outline: 0 }`.
		const rest = styles( { outlineStyle: 'auto', outlineWidth: '3px' } );
		const hover = styles( { outlineStyle: 'auto', outlineWidth: '0px' } );

		expect( stateDeclarations( rest, hover ) ).toBe( '' );
	} );

	it( 'keeps a ring a state DRAWS, including its offset', () => {
		const rest = styles();
		const focus = styles( { outlineStyle: 'solid', outlineWidth: '2px', outlineOffset: '2px' } );

		expect( stateDeclarations( rest, focus ) ).toBe(
			'outline-style:solid;outline-width:2px;outline-offset:2px'
		);
	} );

	it( 'ignores a decoration COLOUR with no decoration LINE', () => {
		const rest = styles();
		const hover = styles( { textDecorationColor: 'rgb(255, 0, 0)' } );

		expect( stateDeclarations( rest, hover ) ).toBe( '' );
	} );

	it( 'keeps the decoration colour when the line is drawn', () => {
		const rest = styles();
		const hover = styles( { textDecorationLine: 'underline', textDecorationColor: 'rgb(255, 0, 0)' } );

		expect( stateDeclarations( rest, hover ) ).toBe(
			'text-decoration-line:underline;text-decoration-color:rgb(255, 0, 0)'
		);
	} );

	it( 'drops a state background image that points at a URL', () => {
		const hover = styles( { backgroundImage: 'url("https://example.com/hover.png")' } );

		expect( stateDeclarations( styles(), hover ) ).toBe( '' );
	} );
} );

describe( 'sections.generated.ts — interaction facts', () => {
	it( 'bakes the escaped selector and the state declarations per band', () => {
		const profile = {
			url: 'https://example.com',
			design: {
				sections: [ { id: 'aaa1111', anchor: 'about' } ],
				builders: [],
			},
			bandFacts: {
				aaa1111: {
					interactions: [
						{
							tag: 'a',
							classes: [ 'btn', 'btn-primary' ],
							count: 2,
							base: 'cursor:pointer;background-color:rgb(221, 51, 51)',
							transition: '0.2s ease-in-out',
							hover: 'color:rgb(255, 255, 255)',
							focusVisible: 'outline-offset:2px',
							active: 'box-shadow:rgba(0, 0, 0, 0.125) 0px 3px 5px 0px inset',
						},
					],
				},
			},
		} as unknown as SiteProfile;

		const file = renderSectionsGenerated( profile );

		expect( file ).toContain( "selector: 'a[class~=\"btn\"][class~=\"btn-primary\"]'" );
		expect( file ).toContain( 'count: 2' );
		expect( file ).toContain( "hover: 'color:rgb(255, 255, 255)'" );
		expect( file ).toContain( 'focusVisible: \'outline-offset:2px\'' );
		expect( file ).toContain( "transition: '0.2s ease-in-out'" );
	} );

	it( 'omits the key entirely when the source declares no state', () => {
		const profile = {
			url: 'https://example.com',
			design: { sections: [ { id: 'aaa1111', anchor: 'about' } ], builders: [] },
		} as unknown as SiteProfile;

		expect( renderSectionsGenerated( profile ) ).not.toContain( 'interactions:' );
	} );
} );
