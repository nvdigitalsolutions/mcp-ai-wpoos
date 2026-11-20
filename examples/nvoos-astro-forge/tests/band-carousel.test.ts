// ---------------------------------------------------------------------------
// band-carousel.test.ts — a logo wall the source PAGES is carried to the
// template.
//
// The analyzer records a slider's own slides-per-view and gutter in
// `bandFacts[].carousel`, but the scaffold never emitted them — so the renderer
// could not know the band was a slider at all. virtuallysrilanka's hardware band
// is a 7-slide Elementor Swiper; the rebuild drew all seven marks in one static
// row and the source's dot control had nothing to page.
//
// The collector also read only Owl (`ROOT = '.owl-carousel'`), so a Swiper band
// recorded no facts in the first place.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { renderSectionsGenerated } from '../lib/scaffold.ts';
import type { ForgeBandFacts, ForgeDesign, SiteProfile } from '../lib/types.ts';

const profileWith = ( facts: ForgeBandFacts ): SiteProfile =>
	( {
		name: 'vs',
		url: 'https://virtuallysrilanka.test',
		design: {
			sections: [ { id: 'abc1234', anchor: 'hardware', logos: [ { src: '/media/a.png' }, { src: '/media/b.png' } ] } ],
			bandSource: 'sections',
		} as unknown as ForgeDesign,
		bandFacts: { abc1234: facts },
	} as unknown as SiteProfile );

const entriesOf = ( module: string ): string =>
	module.slice( module.indexOf( 'export const sections: SectionTheme[] = [' ) );

describe( 'sections.generated — the measured carousel', () => {
	it( 'carries slides-per-view, gutter and dot count', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( {
					carousel: { desktop: [ { selector: 'elementor-main-swiper', count: 17, perView: 6, gap: 50, dots: 7 } ] },
				} )
			)
		);
		expect( module ).toContain( '"perView":6' );
		expect( module ).toContain( '"gap":50' );
		expect( module ).toContain( '"dots":7' );
	} );

	it( 'omits a one-up "carousel", which is a static row', () => {
		const module = entriesOf(
			renderSectionsGenerated(
				profileWith( { carousel: { desktop: [ { selector: 'x', count: 3, perView: 1, gap: 0 } ] } } )
			)
		);
		expect( module ).not.toContain( 'carousel:' );
	} );

	it( 'omits the fact when the band recorded none', () => {
		const module = entriesOf( renderSectionsGenerated( profileWith( {} ) ) );
		expect( module ).not.toContain( 'carousel:' );
	} );
} );
