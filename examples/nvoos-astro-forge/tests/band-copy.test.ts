// ---------------------------------------------------------------------------
// band-copy.test.ts — the band's paragraphs, minus the text its heading prints.
//
// The reported drift: virtuallysrilanka's hardware band rendered "SUPPORTED
// HARDWARE" TWICE — once as the measured `<h2>`, once as body copy. The
// classifier names a band's title by picking its largest heading, and the
// measurement can disagree (a long all-caps kicker out-masses the real title),
// so the promoted text stayed behind in `slots.body`.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { bandParagraphs } from '../templates/blog/src/lib/band-copy.ts';

describe( 'bandParagraphs', () => {
	it( 'drops a paragraph the measured TITLE already prints', () => {
		expect(
			bandParagraphs(
				[ 'CHOOSE THE ACCESS POINT THAT WORKS BEST FOR YOU.', 'SUPPORTED HARDWARE' ],
				{ titleText: 'SUPPORTED HARDWARE' }
			)
		).toEqual( [ 'CHOOSE THE ACCESS POINT THAT WORKS BEST FOR YOU.' ] );
	} );

	it( 'drops a paragraph the measured EYEBROW already prints', () => {
		expect(
			bandParagraphs( [ 'POWERFUL AND UNLIMITED DESIGN', 'Real copy.' ], {
				eyebrow: { text: 'POWERFUL AND UNLIMITED DESIGN' },
			} )
		).toEqual( [ 'Real copy.' ] );
	} );

	it( 'leaves the body untouched when no heading fact repeats it', () => {
		const body = [ 'Alpha.', 'Beta.' ];
		expect( bandParagraphs( body, { titleText: 'A Different Heading' } ) ).toEqual( body );
		expect( bandParagraphs( body, undefined ) ).toEqual( body );
	} );

	it( 'returns an empty list for a band with no body', () => {
		expect( bandParagraphs( undefined, { titleText: 'T' } ) ).toEqual( [] );
	} );
} );
