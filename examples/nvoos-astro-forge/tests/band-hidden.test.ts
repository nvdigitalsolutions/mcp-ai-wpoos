// ---------------------------------------------------------------------------
// band-hidden.test.ts — the source's resting visibility, as emitted CSS.
//
// A builder hides its accordion panels with a class the sanitizer keeps and the
// rebuilt sheet never reaches, so every panel renders open (virtuallyjamaica's
// features band: 2352 characters against the source's 486). The analyzer records
// what the SOURCE hides and turns it into selectors; the renderer emits them as
// scoped `display:none` rules. These tests pin the derivation, especially the
// `:not(.in)` that keeps the OPEN panel visible.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { signaturesHiddenAtBoth } from '../lib/geometry.ts';
import { renderSectionsGenerated } from '../lib/scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';

describe( 'signaturesHiddenAtBoth', () => {
	it( 'emits a plain selector when nothing visible shares the classes', () => {
		const selectors = signaturesHiddenAtBoth(
			{ hidden: [ [ 'collapse', 'panel-collapse' ] ], visible: [ [ 'panel', 'panel-default' ] ] },
			{ hidden: [ [ 'collapse', 'panel-collapse' ] ], visible: [ [ 'panel', 'panel-default' ] ] }
		);

		expect( selectors ).toEqual( [ '[class~="collapse"][class~="panel-collapse"]' ] );
	} );

	it( 'excludes the classes that distinguish the OPEN sibling', () => {
		const selectors = signaturesHiddenAtBoth(
			{
				hidden: [ [ 'collapse', 'panel-collapse' ] ],
				visible: [ [ 'collapse', 'in', 'panel-collapse' ] ],
			},
			{
				hidden: [ [ 'collapse', 'panel-collapse' ] ],
				visible: [ [ 'collapse', 'in', 'panel-collapse' ] ],
			}
		);

		expect( selectors ).toEqual( [
			'[class~="collapse"][class~="panel-collapse"]:not([class~="in"])',
		] );
	} );

	it( 'ignores an element hidden at only ONE breakpoint (a responsive state)', () => {
		const selectors = signaturesHiddenAtBoth(
			{ hidden: [ [ 'nav', 'nav-mobile' ] ], visible: [] },
			{ hidden: [], visible: [ [ 'nav', 'nav-mobile' ] ] }
		);

		expect( selectors ).toEqual( [] );
	} );

	it( 'never hides a signature that is also visible in the same band', () => {
		const selectors = signaturesHiddenAtBoth(
			{ hidden: [ [ 'x', 'y' ] ], visible: [ [ 'x', 'y' ] ] },
			{ hidden: [ [ 'x', 'y' ] ], visible: [] }
		);

		expect( selectors ).toEqual( [] );
	} );

	it( 'is deterministic and de-duplicated', () => {
		const band = {
			hidden: [
				[ 'b', 'a' ],
				[ 'a', 'b' ],
			],
			visible: [],
		};
		const first = signaturesHiddenAtBoth( band, band );
		expect( first ).toEqual( [ '[class~="a"][class~="b"]' ] );
		expect( signaturesHiddenAtBoth( band, band ) ).toEqual( first );
	} );

	it( 'returns nothing when neither viewport was measured', () => {
		expect( signaturesHiddenAtBoth( undefined, undefined ) ).toEqual( [] );
	} );
} );

describe( 'sections.generated.ts — hidden selectors', () => {
	const profile = {
		url: 'https://example.com',
		name: 'Fixture',
		collections: [],
		design: { sections: [ { id: 'a1b2c3d', bgColor: '#eeeeee' } ] },
		bandFacts: {
			a1b2c3d: {
				containerWidth: 1140,
				headings: {},
				hidden: [ '[class~="collapse"][class~="panel-collapse"]:not([class~="in"])' ],
			},
		},
	} as unknown as SiteProfile;

	it( 'carries the measured selectors onto the band theme', () => {
		const code = renderSectionsGenerated( profile );

		expect( code ).toContain( 'hidden: [' );
		expect( code ).toContain( 'collapse' );
		expect( code ).toContain( ':not([class~="in"])' );
	} );

	it( 'omits the key when nothing was measured hidden', () => {
		const code = renderSectionsGenerated( {
			...profile,
			bandFacts: { a1b2c3d: { containerWidth: 1140, headings: {} } },
		} as unknown as SiteProfile );

		expect( code ).not.toContain( 'hidden: [' );
	} );
} );
