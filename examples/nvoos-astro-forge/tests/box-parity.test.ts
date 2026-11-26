// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// box-parity.test.ts — the per-band BOX gate's comparison rules.
//
// The gate exists because a band can match every colour and every word and still
// be visibly wrong: the copy 60px high, the row 100px wide, the picture 124px
// too tall. Pinned here:
//
//  * a deviation is reported PER EDGE, so "the right inset shrank" is not mixed
//    up with "the row got wider";
//  * the tolerance is inclusive (subpixel layout is not a fact);
//  * a textless band is reported, never scored as 0 — that would fake a
//    deviation on every edge;
//  * a band the build does not render is `missing`, not silently skipped.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { diffInkBoxes } from '../lib/box-parity.ts';
import type { BandInkBox } from '../lib/box-parity.ts';

const box = ( overrides: Partial< BandInkBox > = {} ): BandInkBox => ( {
	height: 400,
	left: 80,
	right: 80,
	top: 60,
	width: 1280,
	padX: [ 0, 0 ],
	...overrides,
} );

describe( 'diffInkBoxes', () => {
	it( 'accepts an exact match', () => {
		const diff = diffInkBoxes( { a: box() }, { a: box() } );
		expect( diff.deviations ).toEqual( [] );
		expect( diff.compared ).toBe( 1 );
	} );

	it( 'accepts a subpixel difference inside the tolerance', () => {
		const diff = diffInkBoxes( { a: box() }, { a: box( { left: 82, height: 402 } ) }, 2 );
		expect( diff.deviations ).toEqual( [] );
	} );

	it( 'reports each edge that drifts, with its signed delta', () => {
		const diff = diffInkBoxes(
			{ a: box() },
			{ a: box( { left: 180, right: 140, height: 524 } ) },
			2
		);
		expect( diff.deviations.map( ( entry ) => entry.edge ).sort() ).toEqual( [ 'height', 'left', 'right' ] );
		const left = diff.deviations.find( ( entry ) => entry.edge === 'left' );
		expect( left ).toMatchObject( { source: 80, built: 180, delta: 100 } );
	} );

	it( 'reports a band the build never rendered', () => {
		const diff = diffInkBoxes( { a: box(), b: box() }, { a: box() } );
		expect( diff.missing ).toEqual( [ 'b' ] );
		expect( diff.compared ).toBe( 1 );
	} );

	it( 'reports a textless band instead of scoring it as zero', () => {
		const photoOnly = box( { left: -1, right: -1, top: -1, width: -1 } );
		const diff = diffInkBoxes( { a: photoOnly }, { a: photoOnly } );
		expect( diff.textless ).toEqual( [ 'a' ] );
		expect( diff.deviations ).toEqual( [] );
		expect( diff.compared ).toBe( 0 );
	} );

	it( 'ignores a band the source itself could not measure', () => {
		const diff = diffInkBoxes( { a: null }, { a: box() } );
		expect( diff.deviations ).toEqual( [] );
		expect( diff.compared ).toBe( 0 );
	} );
} );
