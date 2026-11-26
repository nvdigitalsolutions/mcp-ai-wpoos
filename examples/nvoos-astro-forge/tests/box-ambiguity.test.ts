// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// box-ambiguity.test.ts — surfacing the classes whose measured box was dropped.
//
// `collectBandBoxFacts` refuses to record a class that measures differently on
// two instances of a band: one rule cannot express both. The refusal is right,
// but it used to leave only a console line, so the value was simply absent and a
// rebuild rendered the template default (monicarajiv's `.col-inner`, 2026-11-20).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { collectAmbiguousBoxes, describeAmbiguousBoxes } from '../lib/box-ambiguity.ts';
import type { SiteProfile } from '../lib/types.ts';

/** A profile carrying only the fact stores this module reads. */
function profile( facts: Record< string, unknown > ): SiteProfile {
	return facts as unknown as SiteProfile;
}

describe( 'collectAmbiguousBoxes', () => {
	it( 'reads the front page store', () => {
		const boxes = collectAmbiguousBoxes(
			profile( { bandFacts: { 'section-a': { boxWarnings: [ 'col-inner' ] } } } )
		);
		expect( boxes ).toEqual( [ { scope: 'front page', band: 'section-a', classes: [ 'col-inner' ] } ] );
	} );

	it( 'reads a sub-page store', () => {
		const boxes = collectAmbiguousBoxes(
			profile( {
				pageBands: {
					accommodations: { bandFacts: { 'section-b': { boxWarnings: [ 'col-inner', 'banner' ] } } },
				},
			} )
		);
		expect( boxes ).toEqual( [
			{ scope: 'accommodations', band: 'section-b', classes: [ 'banner', 'col-inner' ] },
		] );
	} );

	it( 'names both scopes when both stores carry a warning', () => {
		const boxes = collectAmbiguousBoxes(
			profile( {
				bandFacts: { home: { boxWarnings: [ 'a' ] } },
				pageBands: { 'airport-transfer': { bandFacts: { intro: { boxWarnings: [ 'b' ] } } } },
			} )
		);
		expect( boxes.map( ( box ) => box.scope ) ).toEqual( [ 'front page', 'airport-transfer' ] );
	} );

	it( 'stays empty when every box was recorded', () => {
		expect(
			collectAmbiguousBoxes(
				profile( {
					bandFacts: { 'section-a': {} },
					pageBands: { '/accommodations/': { bandFacts: { 'section-b': { boxWarnings: [] } } } },
				} )
			)
		).toEqual( [] );
	} );

	it( 'stays empty for a profile with no fact stores', () => {
		expect( collectAmbiguousBoxes( profile( {} ) ) ).toEqual( [] );
	} );
} );

describe( 'describeAmbiguousBoxes', () => {
	it( 'stays quiet when nothing was dropped', () => {
		expect( describeAmbiguousBoxes( [] ) ).toBeUndefined();
	} );

	it( 'names the class, the scope and the band', () => {
		const line = describeAmbiguousBoxes( [
			{ scope: 'front page', band: 'section-a', classes: [ 'col-inner' ] },
		] );
		expect( line ).toContain( '.col-inner' );
		expect( line ).toContain( 'front page/section-a' );
		expect( line ).toContain( 'per-instance channel' );
	} );

	it( 'counts classes, not bands, and truncates the list', () => {
		const line = describeAmbiguousBoxes( [
			{ scope: 'a', band: '1', classes: [ 'x', 'y' ] },
			{ scope: 'a', band: '2', classes: [ 'z' ] },
			{ scope: 'a', band: '3', classes: [ 'w' ] },
			{ scope: 'a', band: '4', classes: [ 'v' ] },
		] );
		expect( line ).toContain( '5 class box(es)' );
		expect( line ).toContain( '+1 more' );
	} );
} );
