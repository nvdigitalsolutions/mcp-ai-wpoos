// ---------------------------------------------------------------------------
// band-box-facts.test.ts — the measured box of a builder's classes.
//
// A builder's spacing scale cannot be transcribed from class names: Brando's
// `margin-four-bottom` is 7.8px on an icon and 21.6px on a paragraph, and
// `padding-four-all` is 3.4rem on desktop but `40px 15.5938px` at 390px (both
// measured on virtuallyjamaica, 2026-11-11). The computed box is the only
// faithful source, so `analyze --geometry` records it per class and per device
// and the rebuild re-states it. These tests pin the declaration rendering and
// the device merge.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { boxDeclarations, mergeBoxFacts } from '../lib/geometry.ts';

describe( 'boxDeclarations', () => {
	it( 'renders padding, margin and a cropped media height in one declaration list', () => {
		expect( boxDeclarations( { pad: '40px 15.59px 40px 15.59px', mar: '0px 0px 18px 0px', height: '435px' } ) ).toBe(
			'padding:40px 15.59px 40px 15.59px;margin:0px 0px 18px 0px;height:435px'
		);
	} );

	it( 'omits whatever the measurement did not carry', () => {
		expect( boxDeclarations( { mar: '0px 0px 20px 0px' } ) ).toBe( 'margin:0px 0px 20px 0px' );
		expect( boxDeclarations( {} ) ).toBe( '' );
	} );

	it( 're-expresses nothing for grid scaffolding — those classes are never measured', () => {
		// `.container` / `.row` are excluded from measurement (the coupled Bootstrap
		// grid), so a box never carries a frozen centering pair.
		expect( boxDeclarations( { mar: '0px 135px 0px 135px' } ) ).toBe( 'margin:0px 135px 0px 135px' );
	} );
} );

describe( 'mergeBoxFacts', () => {
	it( 'records the desktop value as the base and adds a device only when it differs', () => {
		const box = mergeBoxFacts(
			{ 'padding-four-all': { pad: '54.4px 54.4px 54.4px 54.4px' } },
			{ 'padding-four-all': { pad: '54.4px 54.4px 54.4px 54.4px' } },
			{ 'padding-four-all': { pad: '40px 15.59px 40px 15.59px' } }
		);

		expect( box ).toEqual( {
			'padding-four-all': {
				base: 'padding:54.4px 54.4px 54.4px 54.4px',
				mobile: 'padding:40px 15.59px 40px 15.59px',
			},
		} );
	} );

	it( 'keeps a class measured only at mobile, with no base', () => {
		const box = mergeBoxFacts( undefined, undefined, { 'xs-only': { mar: '0px 0px 24px 0px' } } );

		expect( box ).toEqual( { 'xs-only': { mobile: 'margin:0px 0px 24px 0px' } } );
	} );

	it( 'returns undefined when nothing was measured', () => {
		expect( mergeBoxFacts( {}, {}, {} ) ).toBeUndefined();
		expect( mergeBoxFacts( undefined, undefined, undefined ) ).toBeUndefined();
	} );

	it( 'emits no override when every device agrees', () => {
		const same = { brando_column_1: { pad: '0px 15px 0px 15px' } };
		const box = mergeBoxFacts( same, same, same );

		expect( box ).toEqual( { brando_column_1: { base: 'padding:0px 15px 0px 15px' } } );
	} );

	it( 'carries a cropped media height through the merge', () => {
		const box = mergeBoxFacts(
			{ brando_image_1: { height: '620px' } },
			undefined,
			{ brando_image_1: { height: '435px' } }
		);

		expect( box ).toEqual( {
			brando_image_1: { base: 'height:620px', mobile: 'height:435px' },
		} );
	} );
} );
