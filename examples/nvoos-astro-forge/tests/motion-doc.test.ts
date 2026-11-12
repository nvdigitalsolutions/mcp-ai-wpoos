// ---------------------------------------------------------------------------
// motion-doc.test.ts — MOTION.md (Proposal 136 §4.4).
//
// The behaviours are already baked into behaviors.generated.ts; this doc is the
// same facts written for a human or an agent to review before approving a
// rebuild. It must stay deterministic and must never invent a behaviour.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { renderMotionDoc } from '../lib/scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';

const profile = ( behavior?: unknown ): SiteProfile =>
	( { url: 'https://example.com', name: 'Fixture', collections: [], design: behavior ? { behavior } : {} } as unknown as SiteProfile );

describe( 'renderMotionDoc', () => {
	it( 'states the at-rest rule when the source declares no motion', () => {
		const doc = renderMotionDoc( profile() );
		expect( doc ).toContain( '# Motion contract' );
		expect( doc ).toContain( 'no scroll behaviour' );
		expect( doc ).not.toContain( '| Band |' );
	} );

	it( 'renders one row per behaviour, in source order', () => {
		const doc = renderMotionDoc(
			profile( {
				entries: [
					{ id: '5e78df1', kind: 'parallax', speed: 2 },
					{ id: 'fd63522', kind: 'reveal', effect: 'fade-up' },
				],
			} )
		);

		expect( doc ).toContain( '| `5e78df1` | parallax | ±48px | static |' );
		expect( doc ).toContain( '| `fd63522` | reveal (fade-up) | — | static |' );
		expect( doc.indexOf( '5e78df1' ) ).toBeLessThan( doc.indexOf( 'fd63522' ) );
	} );

	it( 'is deterministic and carries no timestamps', () => {
		const input = profile( { entries: [ { id: 'a1b2c3d', kind: 'sticky' } ] } );
		const first = renderMotionDoc( input );
		expect( renderMotionDoc( input ) ).toBe( first );
		expect( first ).not.toMatch( /\d{4}-\d{2}-\d{2}T/ );
	} );

	it( 'names the piece count on a piece-level parallax band', () => {
		const doc = renderMotionDoc(
			profile( {
				entries: [
					{
						id: 'd5844b9',
						kind: 'parallax',
						speed: 2,
						pieces: [ { depth: -0.1, origin: 'bottom' }, { depth: 0.1, origin: 'bottom' } ],
					},
				],
			} )
		);
		expect( doc ).toContain( '| `d5844b9` | parallax | ±48px × 2 pieces | static |' );
	} );
} );
