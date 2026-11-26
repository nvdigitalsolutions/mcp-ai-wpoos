// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// band-row-layout.test.ts — the band's ROW as a measured fact.
//
// The column facts state each lane's WIDTH, but width alone cannot say whether
// the lanes sit side by side or stacked. eca.directory's about-us CTA measures
// two 1280px lanes inside a 1280px content column — a COLUMN with a 40px gap —
// and normalising those into a two-track grid laid the copy beside itself and
// moved it 319px left (2026-11-25). The same pass now records:
//
//   • `rowLayout`  — direction, gap, and a spaced row's justify;
//   • `copyWidth`  — a split's copy lane pinned narrower than the lane;
//   • `statsWidths` — the per-item widths a `space-between` row distributes.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { renderSectionsGenerated } from '../lib/scaffold.ts';
import type { ForgeBandFacts, ForgeDesign, SiteProfile } from '../lib/types.ts';

const profileWith = (
	facts: ForgeBandFacts,
	section?: Record< string, unknown >,
	contentWidth?: number
): SiteProfile =>
	( {
		name: 'eca',
		url: 'https://eca.test',
		design: {
			sections: [ { id: 'abc1234', ...( section ?? {} ) } ],
			bandSource: 'sections',
		} as unknown as ForgeDesign,
		bandFacts: { abc1234: facts },
		...( contentWidth
			? { geometry: { abc1234: { desktop: { content: { width: contentWidth, x: 80 } } } } }
			: {} ),
	} as unknown as SiteProfile );

const entriesOf = ( module: string ): string =>
	module.slice( module.indexOf( 'export const sections: SectionTheme[] = [' ) );

describe( 'sections.generated — the band row layout', () => {
	it( 'records a stacked row as a column with its gap', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( {
					columns: {
						desktop: [
							{ selector: 'copy', width: 1280 },
							{ selector: 'action', width: 1280 },
						],
					},
					rowLayout: { desktop: { direction: 'column', gap: 40 } },
				}, undefined, 1280 )
			)
		);
		// A stack of full-width lanes records each as 100% (no fabricated split) …
		expect( module ).toContain( 'width: 100' );
		// … and the row fact that stops the renderer gridding it.
		expect( module ).toContain( "rowLayout: { direction: 'column', gap: 40 }" );
	} );

	it( 'keeps a spaced row and its per-item widths', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( {
					columns: {
						desktop: [
							{ selector: 'heading', width: 1100 },
							{ selector: 'one', width: 151 },
							{ selector: 'two', width: 253 },
							{ selector: 'three', width: 197 },
							{ selector: 'four', width: 163 },
						],
					},
					rowLayout: { desktop: { direction: 'row', gap: 60, justify: 'space-between' } },
					containerWidth: 1100,
				} )
			)
		);
		expect( module ).toContain( "justify: 'space-between'" );
		// The full-width heading is the component's own heading, not an item.
		expect( module ).toContain( 'statsWidths: [151, 253, 197, 163]' );
	} );

	it( 'carries the narrow inset boxes and the label ink', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( {
					rowLayout: { desktop: { direction: 'row', gap: 60, labelInk: 'rgb(255, 255, 255)' } },
					narrowBoxes: [ { parentId: 'abc999', width: 500, align: 'center', alignItems: 'center' } ],
				} as ForgeBandFacts )
			)
		);
		// The counters' labels are white on the source's dark panel; the rebuild's
		// muted default would be unreadable.
		expect( module ).toContain( "labelInk: 'rgb(255, 255, 255)'" );
		// The wrapper has no id of its own, so the fact names its parent.
		expect( module ).toContain(
			"narrowBoxes: [{ parentId: 'abc999', width: 500, align: 'center', alignItems: 'center' }]"
		);
	} );

	it( 'carries a split copy measure', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( {
					columns: {
						desktop: [
							{ selector: 'copy', width: 811 },
							{ selector: 'media', width: 409, image: true },
						],
					},
					rowLayout: { desktop: { direction: 'row', gap: 60 } },
					splitColumns: [ 0.665, 0.335 ],
					copyWidth: 610,
				} )
			)
		);
		expect( module ).toContain( 'copyWidth: 610' );
		expect( module ).toContain( 'width: 66.5' );
		expect( module ).toContain( 'width: 33.5' );
	} );
} );
