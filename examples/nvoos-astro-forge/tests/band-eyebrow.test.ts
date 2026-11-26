// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// band-eyebrow.test.ts — the measured eyebrow/title pair, and the single
// `panel:` emission.
//
// A builder publishes a band's kicker as a real HEADING, often the same tag as
// the title at a fraction of the size (virtuallysrilanka's about band is a 14px
// `h2` above a 30px `h2`). No tag-level heuristic can separate them, so the
// classifier demotes the label into body copy and the rebuild printed it UNDER
// the title at body size — every band read wrong. The browser measurement
// records the pair (`ForgeBandFacts.eyebrow` / `.titleText`); these pin that the
// scaffold carries it to the template.
//
// The panel case is a merge artifact: the same `panel:` key was pushed three
// times, so a band carrying the slab on both the SECTION and its band facts
// emitted it twice into one object literal.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { renderSectionsGenerated } from '../lib/scaffold.ts';
import type { ForgeBandFacts, ForgeDesign, SiteProfile } from '../lib/types.ts';

const profileWith = (
	facts: ForgeBandFacts,
	section: Record< string, unknown > = {}
): SiteProfile =>
	( {
		name: 'vs',
		url: 'https://virtuallysrilanka.test',
		design: {
			sections: [ { id: 'abc1234', anchor: 'hardware', ...section } ],
			bandSource: 'sections',
		} as unknown as ForgeDesign,
		bandFacts: { abc1234: facts },
	} as unknown as SiteProfile );

/** The emitted `sections` array only — the module's interface declares every
 *  optional field, so a negative assertion has to ignore the type block. */
const entriesOf = ( module: string ): string =>
	module.slice( module.indexOf( 'export const sections: SectionTheme[] = [' ) );

describe( 'sections.generated — the measured eyebrow', () => {
	it( 'carries the eyebrow and the measured title text', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( {
					eyebrow: { text: 'CHOOSE THE ACCESS POINT', size: '14px', weight: '600', color: 'rgb(0, 0, 0)', level: 2 },
					titleText: 'SUPPORTED HARDWARE',
				} )
			)
		);
		expect( module ).toContain( '"text":"CHOOSE THE ACCESS POINT"' );
		expect( module ).toContain( '"size":"14px"' );
		expect( module ).toContain( "titleText: 'SUPPORTED HARDWARE'" );
	} );

	it( 'emits nothing when the band has no eyebrow', () => {
		const module = entriesOf( renderSectionsGenerated( profileWith( { titleText: 'CONTACT US' } ) ) );
		expect( module ).not.toContain( 'eyebrow:' );
		expect( module ).toContain( "titleText: 'CONTACT US'" );
	} );
} );

describe( 'sections.generated — one panel emission', () => {
	it( 'emits `panel:` once when the slab rides both the section and its facts', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( { panel: { bg: '#DD3333', ink: '#ffffff' } }, { panel: { bg: '#DD3333', ink: '#ffffff' } } )
			)
		);
		const occurrences = module.match( /panel: \{/g ) ?? [];
		expect( occurrences ).toHaveLength( 1 );
	} );

	it( 'still emits the band-facts slab when the section carries none', () => {
		const module = entriesOf(
			renderSectionsGenerated( profileWith( { panel: { bg: '#dd3333', ink: '#ffffff' } } ) )
		);
		expect( module.match( /panel: \{/g ) ?? [] ).toHaveLength( 1 );
		expect( module ).toContain( "bg: '#dd3333'" );
	} );
} );
