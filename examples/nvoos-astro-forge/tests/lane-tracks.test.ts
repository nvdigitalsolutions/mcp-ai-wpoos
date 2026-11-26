// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// lane-tracks.test.ts — the source's per-device ROW, as a grid track count.
//
// Elementor's responsive container width is a flex basis: lanes wrap when the
// running total passes 100%. The rebuild is a grid, so the faithful fact is the
// first row's track count. Two rules keep it from becoming a guess:
//
//  * a device's count is computed only when a lane STATES a width for it —
//    the implicit basis is the desktop split and must not be applied at 390px;
//  * a lane hidden on the device takes no slot (it renders `display:none`).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { laneTracks } from '../templates/blog/src/lib/lane-tracks.ts';

describe( 'laneTracks', () => {
	it( 'stacks the ECA hero at tablet (lane 2 goes full width)', () => {
		const lanes = [ { width: 50 }, { width: 50, widthTablet: 100 } ];
		expect( laneTracks( lanes, 'tablet' ) ).toBe( 1 );
	} );

	it( 'says nothing for a device no lane addresses', () => {
		// The desktop split is not a mobile decision.
		const lanes = [ { width: 50 }, { width: 50, widthTablet: 100 } ];
		expect( laneTracks( lanes, 'mobile' ) ).toBeUndefined();
		expect( laneTracks( [ { width: 50 }, { width: 50 } ], 'tablet' ) ).toBeUndefined();
	} );

	it( 'wraps when the running total passes 100%', () => {
		expect( laneTracks( [ { width: 50 }, { width: 50 }, { width: 50 } ], 'desktop' ) ).toBe( 2 );
		expect( laneTracks( [ { width: 100 }, { width: 100 } ], 'desktop' ) ).toBe( 1 );
	} );

	it( 'tolerates thirds without accepting a real wrap', () => {
		expect( laneTracks( [ { width: 33.3 }, { width: 33.3 }, { width: 33.3 } ], 'desktop' ) ).toBe( 3 );
		expect( laneTracks( [ { width: 33.3 }, { width: 33.3 }, { width: 33.3 }, { width: 33.3 } ], 'desktop' ) ).toBe( 3 );
	} );

	it( 'does not count a lane the source hides on that device', () => {
		const lanes = [
			{ width: 33, widthMobile: 50 },
			{ width: 33, widthMobile: 50, hiddenMobile: true },
			{ width: 33, widthMobile: 50 },
		];
		// Three lanes, one hidden: the phone row is two 50% lanes.
		expect( laneTracks( lanes, 'mobile' ) ).toBe( 2 );
	} );

	it( 'refuses to count when a visible lane has no width to inherit', () => {
		// Lane 2 states nothing at all — there is no basis to place it, so any
		// count would be a guess.
		expect( laneTracks( [ { widthTablet: 50 }, {} ], 'tablet' ) ).toBeUndefined();
		// Lane 1 inherits its DESKTOP width, which is Elementor's own cascade.
		expect( laneTracks( [ { width: 50 }, { widthTablet: 100 } ], 'tablet' ) ).toBe( 1 );
	} );

	it( 'ignores a single-lane band', () => {
		expect( laneTracks( [ { width: 100 } ], 'desktop' ) ).toBeUndefined();
		expect( laneTracks( [], 'desktop' ) ).toBeUndefined();
	} );
} );
