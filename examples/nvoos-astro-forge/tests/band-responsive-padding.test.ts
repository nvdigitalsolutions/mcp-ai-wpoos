// ---------------------------------------------------------------------------
// band-responsive-padding.test.ts — an explicit padding fact must not flatten
// the source's per-viewport rhythm.
//
// `section.padding` is a single DESKTOP shorthand and it wins by policy. A
// string is emitted inline by `wrapStyle`, and an inline `padding` outranks the
// media queries that would narrow it — so a source that tightens on a phone
// kept its desktop padding all the way down.
//
// virtuallysrilanka's hardware band is the case that exposed it: `100px 0 0 0`
// at desktop, flush at tablet and mobile, pinned as the desktop shorthand in the
// profile. The rebuild carried the 100px to both narrow viewports and ran 161px
// / 233px taller than its source, which the box-geometry gate reported as four
// failures.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { renderSectionsGenerated, resolveBandPadding } from '../lib/scaffold.ts';
import type { ForgeDesign, ForgeGeometry, SiteProfile } from '../lib/types.ts';

/** One band whose MEASURED box differs per viewport. */
const geometryWith = ( pad: {
	desktop: [ number, number, number, number ];
	tablet: [ number, number, number, number ];
	mobile: [ number, number, number, number ];
} ): ForgeGeometry => {
	const boxFor = ( [ top, right, bottom, left ]: [ number, number, number, number ] ) => ( {
		height: 400,
		width: 1440,
		paddingTop: top,
		paddingRight: right,
		paddingBottom: bottom,
		paddingLeft: left,
		marginTop: 0,
		marginBottom: 0,
	} );
	return {
		abc1234: {
			desktop: boxFor( pad.desktop ),
			tablet: boxFor( pad.tablet ),
			mobile: boxFor( pad.mobile ),
		},
	} as unknown as ForgeGeometry;
};

const profileWith = (
	padding: string | Record< string, string > | undefined,
	geometry: ForgeGeometry
): SiteProfile =>
	( {
		name: 'vs',
		url: 'https://virtuallysrilanka.test',
		design: {
			sections: [ { id: 'abc1234', anchor: 'hardware', ...( padding ? { padding } : {} ) } ],
			bandSource: 'sections',
		} as unknown as ForgeDesign,
		geometry,
	} as unknown as SiteProfile );

/** The emitted `sections` array only — the module's interface declares every
 *  optional field, so a negative assertion has to ignore the type block. */
const entriesOf = ( module: string ): string =>
	module.slice( module.indexOf( 'export const sections: SectionTheme[] = [' ) );

describe( 'resolveBandPadding', () => {
	it( 'keeps the explicit value for the viewport it describes', () => {
		expect( resolveBandPadding( '100px 0px 0px 0px', { desktop: '100px 0px 0px 0px', tablet: '0', mobile: '0' } ) ).toEqual( {
			desktop: '100px 0px 0px 0px',
			tablet: '0',
			mobile: '0',
		} );
	} );

	it( 'leaves a fully responsive explicit map untouched', () => {
		const explicit = { desktop: '100px 0px 0px 0px', tablet: '0', mobile: '0' };
		expect( resolveBandPadding( explicit, { desktop: '0', tablet: '0', mobile: '0' } ) ).toBe( explicit );
	} );

	it( 'falls back to the measurement when nothing is explicit', () => {
		expect( resolveBandPadding( undefined, { desktop: '100px', tablet: '0' } ) ).toEqual( {
			desktop: '100px',
			tablet: '0',
		} );
	} );

	it( 'keeps the plain string when the viewports already agree', () => {
		expect( resolveBandPadding( '100px 0px 0px 0px', '100px 0px 0px 0px' ) ).toBe( '100px 0px 0px 0px' );
	} );
} );

describe( 'sections.generated — responsive band padding', () => {
	it( 'narrows an explicit desktop shorthand to the measured tablet/mobile', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith(
					'100px 0px 0px 0px',
					geometryWith( {
						desktop: [ 100, 0, 0, 0 ],
						tablet: [ 0, 0, 0, 0 ],
						mobile: [ 0, 0, 0, 0 ],
					} )
				)
			)
		);
		expect( module ).toContain( "padding: { desktop: '100px 0px 0px 0px', tablet: '0', mobile: '0' }" );
	} );

	it( 'keeps the flat string when the source is genuinely flush all the way down', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith(
					'0',
					geometryWith( {
						desktop: [ 0, 0, 0, 0 ],
						tablet: [ 0, 0, 0, 0 ],
						mobile: [ 0, 0, 0, 0 ],
					} )
				)
			)
		);
		expect( module ).toContain( "padding: '0'" );
	} );
} );
