import { describe, expect, it } from 'vitest';

import { promoteOutlineBands, renderableBands } from '../lib/design.ts';
import { renderSectionsGenerated } from '../lib/scaffold.ts';
import type { BandCandidate } from '../lib/bands.ts';
import type { ForgeDesign, ForgeSection, SiteProfile } from '../lib/types.ts';

const OUTLINE: BandCandidate[] = [
	{ id: '45f5e88', anchor: 'home', bgColor: '#dd3333', via: 'visual' },
	{ id: '1d5804b', anchor: 'about', bgColor: '#ededed', via: 'visual', columns: [ { width: 50 }, { width: 50 } ] },
];

const ELEMENTOR_SECTIONS: ForgeSection[] = [ { id: 'a1b2c3d', anchor: 'hero', bgColor: '#081a43' } ];

describe( 'outline bands are renderable (Proposal 137 promotion)', () => {
	it( 'promotes an outline band into the renderable shape', () => {
		const [ home ] = promoteOutlineBands( OUTLINE );
		expect( home ).toEqual( {
			id: '45f5e88',
			anchor: 'home',
			bgColor: '#dd3333',
			bgImage: undefined,
			bgGradient: undefined,
			measure: undefined,
			paddingX: undefined,
			columns: undefined,
		} );
	} );

	it( 'carries the measured columns through', () => {
		const [ , about ] = promoteOutlineBands( OUTLINE );
		expect( about.columns ).toEqual( [ { width: 50 }, { width: 50 } ] );
	} );

	it( 'returns nothing for a design with neither sections nor outline', () => {
		expect( promoteOutlineBands( undefined ) ).toEqual( [] );
	} );

	it( 'prefers Elementor sections when present', () => {
		expect( renderableBands( { sections: ELEMENTOR_SECTIONS, outline: OUTLINE } ) ).toEqual(
			ELEMENTOR_SECTIONS
		);
	} );

	it( 'falls back to the promoted outline for a WPBakery-style profile', () => {
		const bands = renderableBands( { sections: [], outline: OUTLINE } );
		expect( bands ).toHaveLength( 2 );
		expect( bands[ 0 ].bgColor ).toBe( '#dd3333' );
	} );
} );

describe( 'sections.generated records where the bands came from', () => {
	const profile = ( design: Partial< ForgeDesign > ): SiteProfile =>
		( { name: 'vj', url: 'https://virtuallyjamaica.test', design } as unknown as SiteProfile );

	it( 'bakes the outline band surfaces and the positional flag', () => {
		const module = renderSectionsGenerated( profile( { sections: [], outline: OUTLINE, bandSource: 'outline' } ) );
		expect( module ).toContain( "export const bandSource: 'sections' | 'outline' = 'outline';" );
		// The source hero surface — a rebuild that shipped #f5f5f4 instead was
		// the "site is not getting any styling" report.
		expect( module ).toContain( "anchor: 'home', bg: '#dd3333'" );
		expect( module ).toContain( "anchor: 'about', bg: '#ededed'" );
	} );

	it( 'keeps the positional flag for a PROMOTED outline profile', () => {
		// `forge analyze` promotes the outline INTO `sections` while recording
		// `bandSource: 'outline'`. Deriving the flag from `sections.length`
		// flipped these to id-matching — and WPBakery builds have no ids, so
		// every band surface was dropped (virtuallyjamaica, 2026-11-10).
		const promoted = promoteOutlineBands( OUTLINE );
		const module = renderSectionsGenerated(
			profile( { sections: promoted, outline: OUTLINE, bandSource: 'outline' } )
		);
		expect( promoted.length ).toBeGreaterThan( 0 );
		expect( module ).toContain( "export const bandSource: 'sections' | 'outline' = 'outline';" );
		expect( module ).toContain( "anchor: 'home', bg: '#dd3333'" );
	} );

	it( 'keeps Elementor profiles on id matching', () => {
		const module = renderSectionsGenerated(
			profile( { sections: ELEMENTOR_SECTIONS, bandSource: 'sections' } )
		);
		expect( module ).toContain( "export const bandSource: 'sections' | 'outline' = 'sections';" );
		expect( module ).toContain( "id: 'a1b2c3d'" );
	} );
} );
