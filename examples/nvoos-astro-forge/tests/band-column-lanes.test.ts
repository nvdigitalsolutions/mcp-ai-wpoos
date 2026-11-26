// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// band-column-lanes.test.ts — a multi-column band keeps its LANES.
//
// `columnItems` skips a column with no copy and no media (a photo published as
// a CSS `background-image`), and the grid then clamped its track count to the
// item count — so virtuallysrilanka's services band (red / photo / black)
// rebuilt as two plain cards with none of its surfaces, and its `h2` column
// headings rendered at a level no measured type rule matches.
//
// The profile's `design.sections[].columns` states the builder's INTENT
// (`col-33` on all three); the geometry pass holds what the browser RENDERS
// (432 / 432 / 571, a photo lane, and a black slab scoped to a nested wrapper
// that no static parse can attribute). Both are pinned here.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { renderSectionsGenerated } from '../lib/scaffold.ts';
import type { ForgeBandFacts, ForgeDesign, SiteProfile } from '../lib/types.ts';

/** One band whose measured lanes carry real widths and painted surfaces. */
const profileWith = ( facts: ForgeBandFacts, declared?: Array< Record< string, unknown > > ): SiteProfile =>
	( {
		name: 'vs',
		url: 'https://virtuallysrilanka.test',
		design: {
			sections: [ { id: 'abc1234', anchor: 'services', ...( declared ? { columns: declared } : {} ) } ],
			bandSource: 'sections',
		} as unknown as ForgeDesign,
		bandFacts: { abc1234: facts },
	} as unknown as SiteProfile );

const entriesOf = ( module: string ): string =>
	module.slice( module.indexOf( 'export const sections: SectionTheme[] = [' ) );

describe( 'sections.generated — measured lanes', () => {
	it( 'keeps all three lanes, their real widths, and their surfaces', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith(
					{
						columns: {
							desktop: [
								{ selector: 'a', width: 432, bg: 'rgb(221, 51, 51)' },
								{ selector: 'b', width: 432, image: true },
								{ selector: 'c', width: 571, bg: 'rgb(0, 0, 0)' },
							],
						},
					},
					[
						{ width: 33, bgColor: '#DD3333' },
						{ width: 33, bgImage: 'https://example.test/phone-2.jpg' },
						{ width: 33 },
					]
				)
			)
		);
		// 432/1435 and 571/1435 — the declaration's equal thirds lose.
		expect( module ).toContain( 'width: 30.1' );
		expect( module ).toContain( 'width: 39.8' );
		expect( module ).toContain( "bgColor: 'rgb(221, 51, 51)'" );
		expect( module ).toContain( "bgColor: 'rgb(0, 0, 0)'" );
		// The measured fact says an image is painted; the URL comes from the declaration.
		expect( module ).toContain( 'bgImage: \'https://example.test/phone-2.jpg\'' );
	} );

	it( 'derives readable ink for a painted lane', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( {
					columns: {
						desktop: [
							{ selector: 'a', width: 500, bg: 'rgb(0, 0, 0)' },
							{ selector: 'b', width: 500, bg: 'rgb(255, 255, 255)' },
						],
					},
				} )
			)
		);
		// White on black, dark on white — never one flat value.
		expect( module ).toContain( "ink: '#ffffff'" );
		expect( module ).toContain( "ink: '#111111'" );
	} );

	it( 'falls back to the declared columns when nothing useful was measured', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( { columns: { desktop: [ { selector: 'a', height: 400 }, { selector: 'b', height: 400 } ] } }, [ { width: 50 }, { width: 50, bgColor: '#DD3333' } ] )
			)
		);
		expect( module ).toContain( "bgColor: '#DD3333'" );
		expect( module ).not.toContain( 'columns: [{}, {}]' );
	} );

	it( 'carries the declared per-device intent through a measured override', () => {
		// The measurement replaces the lane list (real widths beat the declared
		// split), but a visibility class is not measurable from the desktop render
		// — dropping it silently un-hid a lane the source hides on phones.
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith(
					{
						columns: {
							desktop: [
								{ selector: 'a', width: 700 },
								{ selector: 'b', width: 300 },
							],
						},
					},
					[ { width: 70 }, { width: 30, hiddenMobile: true, widthTablet: 100 } ]
				)
			)
		);
		expect( module ).toContain( 'width: 70' );
		expect( module ).toContain( 'hiddenMobile: true' );
		expect( module ).toContain( 'widthTablet: 100' );
	} );

	it( 'treats lanes that each FILL the content column as a stack', () => {
		// eca.directory's about-us CTA measures two 1280px lanes inside a 1280px
		// content column: normalising them to 50/50 laid them side by side.
		const profile = profileWith( {
			columns: {
				desktop: [
					{ selector: 'a', width: 1280 },
					{ selector: 'b', width: 1280 },
				],
			},
		} );
		( profile as unknown as { geometry: Record< string, unknown > } ).geometry = {
			abc1234: { desktop: { content: { width: 1280 } } },
		};
		const module = entriesOf( renderSectionsGenerated( profile ) );
		expect( module ).toContain( 'columns: [{ width: 100 }, { width: 100 }]' );
	} );

	it( 'keeps the normalised share for a real row', () => {
		const profile = profileWith( {
			columns: {
				desktop: [
					{ selector: 'a', width: 432 },
					{ selector: 'b', width: 432 },
					{ selector: 'c', width: 571 },
				],
			},
		} );
		( profile as unknown as { geometry: Record< string, unknown > } ).geometry = {
			abc1234: { desktop: { content: { width: 1435 } } },
		};
		const module = entriesOf( renderSectionsGenerated( profile ) );
		expect( module ).toContain( 'width: 30.1' );
		expect( module ).not.toContain( 'width: 100' );
	} );
} );
