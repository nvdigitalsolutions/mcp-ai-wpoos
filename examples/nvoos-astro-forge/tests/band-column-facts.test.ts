// ---------------------------------------------------------------------------
// band-column-facts.test.ts — the measured height of a builder's COLUMNS.
//
// A builder row is a grid, and grid items stretch to their tallest sibling. The
// source's rows are floats, so its columns are free to differ — virtuallyjamaica
// paints a 700px fixed photo beside 946px of copy. The sanitizer drops the
// inline `min-height` that sized the photo column and the vendor sheet that
// declared `background-attachment: fixed` never ships, so `analyze --geometry`
// measures both per device and the rebuild re-states them.
//
// Two properties are load-bearing and easy to regress:
//
//   1. `--ds-col-min-h` must ride with `min-height`. The builder shim in
//      `global.css` guesses a `clamp()` floor for a photo panel and its selector
//      out-weighs any band-scoped rule; the shim reads the property instead, so
//      a measured height wins on VALUE rather than on specificity.
//   2. A device must be able to UNDO the base. A media query that omits
//      `background-attachment` (the source is `scroll` at tablet, `fixed` at
//      desktop) leaves the desktop `fixed` in force; a device that measures no
//      height must reset the property as well as the floor.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { columnBoxFacts, columnDeclarations } from '../lib/geometry.ts';

describe( 'columnDeclarations', () => {
	it( 're-states a measured height as both a floor and the shim property', () => {
		expect( columnDeclarations( { selector: 'brando_column_a', height: 700 }, false ) ).toBe(
			'--ds-col-min-h:700px;min-height:700px'
		);
	} );

	it( 'pins a column to the START of an unequal row instead of letting it stretch', () => {
		// virtuallyjamaica #wifi: 700 beside 946. Stretched, the photo panel grew
		// to the copy column's height and the band gained 246px of blank photo.
		expect( columnDeclarations( { selector: 'brando_column_a', height: 700 }, true ) ).toBe(
			'--ds-col-min-h:700px;min-height:700px;align-self:start'
		);
	} );

	it( 'carries the photo paint, including `scroll` at a device where it is not fixed', () => {
		expect(
			columnDeclarations( { selector: 'c', height: 700, image: true, attachment: 'fixed', size: 'cover' }, false )
		).toBe( '--ds-col-min-h:700px;min-height:700px;background-attachment:fixed;background-size:cover' );

		// iOS ignores fixed panels, so the source switches to `scroll` below the
		// desktop breakpoint. Omitting the declaration would leak `fixed` down.
		expect(
			columnDeclarations( { selector: 'c', height: 700, image: true, attachment: 'scroll', size: 'cover' }, false )
		).toBe( '--ds-col-min-h:700px;min-height:700px;background-attachment:scroll;background-size:cover' );
	} );

	it( 'writes `auto` for a photo whose computed size is the initial value', () => {
		// The collector only records `size` when it differs from `auto`, so a
		// missing size must render explicitly or a base `cover` would persist.
		expect( columnDeclarations( { selector: 'c', image: true, attachment: 'scroll' }, false ) ).toBe(
			'background-attachment:scroll;background-size:auto'
		);
	} );

	it( 'renders nothing for a device with no fact', () => {
		expect( columnDeclarations( undefined, false ) ).toBe( '' );
	} );
} );

describe( 'columnBoxFacts', () => {
	it( 'emits one band-scoped entry per column, keyed by its unique class', () => {
		const box = columnBoxFacts( {
			desktop: [
				{ selector: 'brando_column_photo', height: 700, image: true, attachment: 'fixed', size: 'cover' },
				{ selector: 'brando_column_copy', height: 946 },
			],
		} );

		expect( box ).toEqual( {
			brando_column_photo: {
				base: '--ds-col-min-h:700px;min-height:700px;align-self:start;background-attachment:fixed;background-size:cover',
				// Desktop-only facts: the other devices must not inherit the floor.
				tablet: '--ds-col-min-h:initial;min-height:0',
				mobile: '--ds-col-min-h:initial;min-height:0',
			},
			brando_column_copy: {
				base: '--ds-col-min-h:946px;min-height:946px;align-self:start',
				tablet: '--ds-col-min-h:initial;min-height:0',
				mobile: '--ds-col-min-h:initial;min-height:0',
			},
		} );
	} );

	it( 'adds a device only when the measured height differs from the base', () => {
		const box = columnBoxFacts( {
			desktop: [ { selector: 'a', height: 500 }, { selector: 'b', height: 500 } ],
			tablet: [ { selector: 'a', height: 500 }, { selector: 'b', height: 500 } ],
			mobile: [ { selector: 'a', height: 320 }, { selector: 'b', height: 320 } ],
		} );

		// Equal heights at tablet → no `align-self`, and tablet is not repeated.
		expect( box.a ).toEqual( {
			base: '--ds-col-min-h:500px;min-height:500px',
			mobile: '--ds-col-min-h:320px;min-height:320px',
		} );
	} );

	it( 'resets BOTH the floor and the property on a device that measured nothing', () => {
		// Stacks at mobile in the source? Then no column fact exists there, and
		// the desktop floor must not leak into the stack — nor may the custom
		// property, which the shim's clamp would otherwise read as 700px.
		const box = columnBoxFacts( {
			desktop: [ { selector: 'a', height: 700 }, { selector: 'b', height: 946 } ],
			mobile: [],
		} );

		expect( box.a.mobile ).toBe( '--ds-col-min-h:initial;min-height:0' );
	} );

	it( 'compares only the columns present at that device when deciding "unequal"', () => {
		// A single-lane device is never "unequal" — nothing to align against.
		const box = columnBoxFacts( {
			desktop: [ { selector: 'a', height: 700 }, { selector: 'b', height: 946 } ],
			mobile: [ { selector: 'a', height: 320 } ],
		} );

		expect( box.a.base ).toBe( '--ds-col-min-h:700px;min-height:700px;align-self:start' );
		expect( box.a.mobile ).toBe( '--ds-col-min-h:320px;min-height:320px' );
	} );

	it( 'returns an empty map when the band measured no columns', () => {
		expect( columnBoxFacts( {} ) ).toEqual( {} );
	} );
} );
