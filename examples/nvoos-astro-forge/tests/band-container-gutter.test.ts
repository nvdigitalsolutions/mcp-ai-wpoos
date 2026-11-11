// ---------------------------------------------------------------------------
// band-container-gutter.test.ts — the band's inner container, per device.
//
// The rebuild needs two lengths per band and neither is a constant: the
// container's BORDER-BOX width (its `max-width`, because that includes the
// padding) and the container's own side gutter (its `--ds-pad-x`). Bootstrap
// uses 15px, Elementor 10px, Brando 30px — and each differs again per viewport.
//
// The bug these pin: one desktop width plus a 64px constant applied at every
// breakpoint. A fluid band rendered full-bleed on a phone (0px of the source's
// 15, so 30px too wide and wrapping differently) and a boxed band stayed
// 64px-compensated at 390px, where the template's own gutter is 16.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { renderSectionsGenerated } from '../lib/scaffold.ts';
import type { ForgeBandFacts, ForgeDesign, SiteProfile } from '../lib/types.ts';

/** A one-band profile carrying the measured container under test. */
const profileWith = (
	facts: ForgeBandFacts,
	section: Record< string, unknown > = {}
): SiteProfile =>
	( {
		name: 'vj',
		url: 'https://virtuallyjamaica.test',
		design: {
			sections: [ { id: 'abc1234', anchor: 'about', bgColor: '#ededed', ...section } ],
			bandSource: 'sections',
		} as ForgeDesign,
		bandFacts: { abc1234: facts },
	} as unknown as SiteProfile );

/** The emitted `sections` array only — the module's interface declares every
 *  optional field, so a negative assertion has to ignore the type block. */
const entriesOf = ( module: string ): string =>
	module.slice( module.indexOf( 'export const sections: SectionTheme[] = [' ) );

describe( 'the container measure is the BORDER-BOX width', () => {
	it( 'adds both gutters back so the CONTENT width matches the source', () => {
		// 1140px of content inside a 15px-padded container is a 1170px box.
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( {
					container: {
						desktop: { content: 1140, padX: 15 },
						tablet: { content: 1140, padX: 15 },
						mobile: { content: 1140, padX: 15 },
					},
				} )
			)
		);
		expect( module ).toContain( "measure: '1170px'" );
		expect( module ).toContain( "paddingX: '15px'" );
	} );

	it( 'keeps a fluid container at the viewport width instead of overflowing it', () => {
		// `.container-fluid` measured 1410px of a 1440px viewport: 1410 + 2×15.
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( { container: { desktop: { content: 1410, padX: 15 } } } )
			)
		);
		expect( module ).toContain( "measure: '1440px'" );
	} );
} );

describe( 'the gutter is measured per device, not inherited from desktop', () => {
	it( 'emits a per-device map when the container tightens on a phone', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( {
					container: {
						desktop: { content: 1140, padX: 15 },
						tablet: { content: 720, padX: 15 },
						mobile: { content: 360, padX: 15 },
					},
				} )
			)
		);
		expect( module ).toContain(
			"measure: { desktop: '1170px', tablet: '750px', mobile: '390px' }"
		);
	} );

	it( 'emits a per-device gutter when the source insets less on mobile', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( {
					container: {
						desktop: { content: 1110, padX: 30 },
						tablet: { content: 1110, padX: 30 },
						mobile: { content: 360, padX: 15 },
					},
				} )
			)
		);
		// Once ANY device differs the map carries every measured device — the same
		// shape `measuredBandPadding` and the measured band height emit.
		expect( module ).toContain( "paddingX: { desktop: '30px', tablet: '30px', mobile: '15px' }" );
	} );

	it( 'emits a device the desktop pass missed rather than dropping it', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( { container: { desktop: { content: 1140, padX: 15 }, mobile: { content: 360, padX: 15 } } } )
			)
		);
		expect( module ).toContain( "measure: { desktop: '1170px', mobile: '390px' }" );
	} );
} );

describe( 'a grid that cancels the container gutter renders flush', () => {
	it( 'keeps the border-box width but drops the section padding', () => {
		// Bootstrap-family `.row` has a −15px margin, so the columns span the
		// container's BORDER-BOX: measure 1170, section padding 0.
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( { container: { desktop: { content: 1140, padX: 15, flush: true } } } )
			)
		);
		expect( module ).toContain( "measure: '1170px'" );
		expect( module ).toContain( "paddingX: '0px'" );
	} );

	it( 'does not narrow a fluid band — the 30px that made #wifi taller', () => {
		// Before this, a fluid band kept the template inset OR a 15px gutter, so
		// its columns lost 30px against the source and rewrapped (virtuallyjamaica
		// #wifi: 1076px source → 1105px build).
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( {
					container: {
						desktop: { content: 1410, padX: 15, flush: true },
						tablet: { content: 804, padX: 15, flush: true },
						mobile: { content: 360, padX: 15, flush: true },
					},
				} )
			)
		);
		expect( module ).toContain( "measure: { desktop: '1440px', tablet: '834px', mobile: '390px' }" );
		expect( module ).toContain( "paddingX: '0px'" );
	} );

	it( 'keeps the gutter for a grid that does not cancel it', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( { container: { desktop: { content: 1140, padX: 15, flush: false } } } )
			)
		);
		expect( module ).toContain( "measure: '1170px'" );
		expect( module ).toContain( "paddingX: '15px'" );
	} );
} );

describe( 'a measured zero is a fact', () => {
	it( 'emits 0px rather than falling back to the template clamp', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( { container: { desktop: { content: 1440, padX: 0 } } } )
			)
		);
		expect( module ).toContain( "paddingX: '0px'" );
		expect( module ).toContain( "measure: '1440px'" );
	} );
} );

describe( 'explicit profile facts win', () => {
	it( 'keeps a pinned measure and gutter over the measurement', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( { container: { desktop: { content: 1140, padX: 15 } } }, {
					measure: '1200px',
					paddingX: '20px',
				} )
			)
		);
		expect( module ).toContain( "measure: '1200px'" );
		expect( module ).toContain( "paddingX: '20px'" );
		expect( module ).not.toContain( "'1170px'" );
	} );
} );

describe( 'legacy profiles scaffold unchanged', () => {
	it( 'keeps the desktop-derived compensation when only containerWidth exists', () => {
		// 1140 + BAND_GUTTER_PX (64). No per-device gutter, no measure map.
		const module = entriesOf( renderSectionsGenerated( profileWith( { containerWidth: 1140 } ) ) );
		expect( module ).toContain( "measure: '1204px'" );
		expect( module ).not.toContain( 'paddingX' );
	} );

	it( 'keeps the absolute fluid heuristic for a wide legacy container', () => {
		const module = entriesOf( renderSectionsGenerated( profileWith( { containerWidth: 1410 } ) ) );
		expect( module ).toContain( "paddingX: '0px'" );
	} );

	it( 'prefers the measured container over the legacy width when both exist', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( { containerWidth: 1440, container: { desktop: { content: 1140, padX: 15 } } } )
			)
		);
		expect( module ).toContain( "measure: '1170px'" );
		expect( module ).not.toContain( "'1504px'" );
	} );

	it( 'emits no container facts at all without a measurement', () => {
		const module = entriesOf( renderSectionsGenerated( profileWith( {} ) ) );
		expect( module ).not.toContain( 'measure' );
		expect( module ).not.toContain( 'paddingX' );
	} );
} );
