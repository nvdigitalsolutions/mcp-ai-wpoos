// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// band-coverage.test.ts — a pass that resolves nothing must say so.
//
// monicarajiv's sub-pages carried `interactions: []` for every band, because
// Flatsome re-issues each `<section id>` per render and the stored anchors had
// gone stale: every pass returned `{}` and the pipeline read that as "a site
// with no controls" instead of "an unmeasured page" (2026-11-20).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { bandCoverage, describeBandCoverage } from '../lib/band-coverage.ts';

describe( 'bandCoverage', () => {
	it( 'counts a band that was measured but produced no state as MATCHED', () => {
		// A band with no interactive element legitimately has zero facts; the root
		// still resolved, so it must not read as missing.
		const coverage = bandCoverage( [ 'a', 'b' ], { a: [], b: [] } );
		expect( coverage ).toEqual( { requested: 2, matched: 2, missing: [] } );
	} );

	it( 'lists the ids that resolved nothing', () => {
		const coverage = bandCoverage( [ 'a', 'b', 'c' ], { a: [] } );
		expect( coverage.matched ).toBe( 1 );
		expect( coverage.missing ).toEqual( [ 'b', 'c' ] );
	} );
} );

describe( 'describeBandCoverage', () => {
	it( 'stays quiet when every root resolved', () => {
		expect( describeBandCoverage( 'band facts', bandCoverage( [ 'a' ], { a: [] } ) ) ).toBeUndefined();
	} );

	it( 'stays quiet when the pass was asked for nothing', () => {
		expect( describeBandCoverage( 'band facts', bandCoverage( [], {} ) ) ).toBeUndefined();
	} );

	it( 'reads a zero-match pass as an ERROR, not a site with no facts', () => {
		const message = describeBandCoverage( 'interaction states (airport-transfer)', bandCoverage( [ '834b9c8' ], {} ) );
		expect( message ).toContain( '✗' );
		expect( message ).toContain( '0 of 1' );
		expect( message ).toContain( 'measured NOTHING' );
	} );

	it( 'names a partial miss as a warning, with a sample of the ids', () => {
		const message = describeBandCoverage( 'band facts (p)', bandCoverage( [ 'a', 'b', 'c' ], { a: [] } ) );
		expect( message ).toContain( '⚠' );
		expect( message ).toContain( '2 of 3' );
		expect( message ).toContain( 'b, c' );
	} );

	it( 'elides a long miss list rather than printing every id', () => {
		const ids = [ 'a', 'b', 'c', 'd', 'e', 'f', 'g' ];
		const message = describeBandCoverage( 'band facts (p)', bandCoverage( ids, {} ) );
		expect( message ).toContain( '+3 more' );
	} );
} );
