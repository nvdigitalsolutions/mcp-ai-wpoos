// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// band-element-veil.test.ts — a band photo's translucent SCRIM.
//
// Flatsome paints a band's photo shade on `.overlay`. Its alpha sits below the
// solid-fill gate on purpose (that gate keeps a photo scrim from becoming the
// band's surface), but dropping the layer entirely left every hero unshaded:
// monicarajiv's source hero computes `rgba(0, 0, 0, 0.5)` and the rebuild
// computed `rgba(0, 0, 0, 0)` (2026-11-19).
//
// The fact is separate from `bg` because the two stack: `bg` is the surface the
// element paints INSTEAD of its parent's, `veil` a layer OVER that surface.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { mergeElementFacts } from '../lib/geometry.ts';

describe( 'mergeElementFacts — veil', () => {
	it( 'carries a translucent overlay through as its own fact', () => {
		const merged = mergeElementFacts( [
			{ path: 'div:nth-child(2) > div:nth-child(1)', veil: 'background-color:rgba(0, 0, 0, 0.5)' },
		] );

		expect( merged ).toEqual( [
			{ path: 'div:nth-child(2) > div:nth-child(1)', veil: 'background-color:rgba(0, 0, 0, 0.5)' },
		] );
	} );

	it( 'keeps the surface and the veil distinct on one element', () => {
		const merged = mergeElementFacts( [
			{
				path: 'div:nth-child(2) > div:nth-child(1)',
				bg: 'background-color:rgb(221, 51, 51)',
				veil: 'background-color:rgba(0, 0, 0, 0.5)',
			},
		] );

		expect( merged?.[ 0 ] ).toMatchObject( {
			bg: 'background-color:rgb(221, 51, 51)',
			veil: 'background-color:rgba(0, 0, 0, 0.5)',
		} );
	} );

	it( 'does not invent a veil for an element that has none', () => {
		const merged = mergeElementFacts( [
			{ path: 'div:nth-child(2) > div:nth-child(1)', bg: 'background-color:rgb(240, 240, 240)' },
		] );

		expect( merged?.[ 0 ] ).not.toHaveProperty( 'veil' );
	} );

	it( 'still merges per-device boxes for an element that only carries a veil', () => {
		const merged = mergeElementFacts(
			[ { path: 'div:nth-child(2)', veil: 'background-color:rgba(0, 0, 0, 0.5)', height: 900 } ],
			[ { path: 'div:nth-child(2)', height: 700 } ]
		);

		expect( merged?.[ 0 ] ).toMatchObject( {
			veil: 'background-color:rgba(0, 0, 0, 0.5)',
			height: { desktop: 900, tablet: 700 },
		} );
	} );
} );
