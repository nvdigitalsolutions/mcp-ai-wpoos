// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// compare.test.ts — the source-vs-rebuild box comparison.
//
// Every defect of 2026-11-19/20 was found by hand with this probe: the banner
// photo at 900px of a 1200px layer, `.col-inner` padding at 0 instead of
// 50/180/60/108, the disclosure at x=524 instead of x=195. Pin the maths so the
// command reports the same way every time.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	compareFacts,
	deltaOf,
	describeCompare,
	describeCompareVerdict,
	mismatches,
	normaliseValue,
	type CompareSpec,
} from '../lib/compare.ts';

const spec = ( properties: string[] ): CompareSpec[] => [ { name: 'col-inner', selector: '.col-inner', properties } ];

describe( 'normaliseValue', () => {
	it( 'collapses the whitespace a computed shorthand carries', () => {
		expect( normaliseValue( ' 50px   180px 60px  108px ' ) ).toBe( '50px 180px 60px 108px' );
	} );

	it( 'treats a missing reading as empty', () => {
		expect( normaliseValue( undefined ) ).toBe( '' );
	} );
} );

describe( 'deltaOf', () => {
	it( 'differences two px lengths', () => {
		expect( deltaOf( '195px', '524px' ) ).toBe( 329 );
		expect( deltaOf( '900px', '1200px' ) ).toBe( 300 );
	} );

	it( 'rounds to two places so float noise does not read as a delta', () => {
		expect( deltaOf( '37.7276px', '41.3453px' ) ).toBe( 3.62 );
	} );

	it( 'refuses a shorthand, a colour or a font stack', () => {
		// A padding shorthand is FOUR lengths; differencing them is meaningless.
		expect( deltaOf( '50px 180px 60px 108px', '0px' ) ).toBeUndefined();
		expect( deltaOf( 'rgb(221, 51, 51)', 'rgb(0, 0, 0)' ) ).toBeUndefined();
		expect( deltaOf( 'Lato, sans-serif', 'Arial, sans-serif' ) ).toBeUndefined();
	} );
} );

describe( 'compareFacts', () => {
	it( 'reports a dropped shorthand as a difference, not a delta', () => {
		const rows = compareFacts(
			spec( [ 'padding' ] ),
			{ 'col-inner': { padding: '50px 180px 60px 108px' } },
			{ 'col-inner': { padding: '0px' } }
		);
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toMatchObject( {
			source: '50px 180px 60px 108px',
			rebuilt: '0px',
		} );
		expect( rows[ 0 ].delta ).toBeUndefined();
	} );

	it( 'carries a delta for a px metric', () => {
		const rows = compareFacts( spec( [ 'rect.left' ] ), { 'col-inner': { 'rect.left': '195px' } }, { 'col-inner': { 'rect.left': '524px' } } );
		expect( rows[ 0 ].delta ).toBe( 329 );
	} );

	it( 'skips a property neither page reports', () => {
		expect( compareFacts( spec( [ 'padding', 'gap' ] ), { 'col-inner': {} }, { 'col-inner': {} } ) ).toEqual( [] );
	} );

	it( 'reports an element that exists on only one page', () => {
		// The source has the envelope; the rebuild rendered nothing.
		const rows = compareFacts(
			[ { name: 'email', selector: '.icon-envelop', properties: [ 'rect.width' ] } ],
			{ email: { 'rect.width': '18px' } },
			{}
		);
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].rebuilt ).toBe( '' );
	} );
} );

describe( 'mismatches', () => {
	it( 'keeps only the rows that disagree', () => {
		const rows = compareFacts(
			spec( [ 'padding', 'margin' ] ),
			{ 'col-inner': { padding: '0px', margin: '0px' } },
			{ 'col-inner': { padding: '0px', margin: '10px' } }
		);
		expect( mismatches( rows ).map( ( row ) => row.property ) ).toEqual( [ 'margin' ] );
	} );
} );

describe( 'describeCompare', () => {
	it( 'marks agreement and disagreement, and shows the delta', () => {
		const lines = describeCompare( [
			{ element: 'toggle', property: 'rect.left', source: '195px', rebuilt: '524px', delta: 329 },
			{ element: 'toggle', property: 'rect.height', source: '44px', rebuilt: '44px', delta: 0 },
		] );
		expect( lines[ 0 ] ).toContain( '\u2260' );
		expect( lines[ 0 ] ).toContain( '+329' );
		expect( lines[ 1 ] ).toContain( '\u2713' );
	} );

	it( 'treats sub-tolerance noise as agreement', () => {
		const lines = describeCompare( [ { element: 'col', property: 'rect.width', source: '720px', rebuilt: '721px', delta: 1 } ] );
		expect( lines[ 0 ] ).toContain( '\u2713' );
	} );
} );

describe( 'describeCompareVerdict', () => {
	it( 'is quiet when everything agrees within tolerance', () => {
		expect(
			describeCompareVerdict( [ { element: 'col', property: 'rect.width', source: '720px', rebuilt: '721px', delta: 1 } ] )
		).toBeUndefined();
	} );

	it( 'names the elements that disagree', () => {
		const verdict = describeCompareVerdict( [
			{ element: 'toggle', property: 'rect.left', source: '195px', rebuilt: '524px', delta: 329 },
			{ element: 'toggle', property: 'rect.height', source: '44px', rebuilt: '44px', delta: 0 },
		] );
		expect( verdict ).toContain( '1 propert' );
		expect( verdict ).toContain( 'toggle' );
	} );

	it( 'counts a non-numeric difference regardless of tolerance', () => {
		const verdict = describeCompareVerdict( [
			{ element: 'col-inner', property: 'padding', source: '50px 180px 60px 108px', rebuilt: '0px' },
		] );
		expect( verdict ).toContain( 'col-inner' );
	} );
} );
