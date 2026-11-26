// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// property-coverage.test.ts — a measured property that never reaches the CSS.
//
// The band audit checks that declared facts render; it cannot see a property the
// emitter dropped. `line-height` was read at rest and absent from the paint
// allowlist, so an outline button shipped 41px where the source is 43px.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { declarationProperties, describePropertyCoverage, propertyCoverage } from '../lib/property-coverage.ts';

describe( 'declarationProperties', () => {
	it( 'reads every property name in a declaration list', () => {
		expect( declarationProperties( 'background-color:#fff;border-top:2px solid #000;color:#111' ) ).toEqual( [
			'background-color',
			'border-top',
			'color',
		] );
	} );

	it( 'ignores a value that merely contains a colon', () => {
		expect( declarationProperties( 'background-image:url(https://x.test/a.png)' ) ).toEqual( [ 'background-image' ] );
	} );

	it( 'returns nothing for an empty list', () => {
		expect( declarationProperties( '' ) ).toEqual( [] );
	} );
} );

describe( 'propertyCoverage', () => {
	it( 'reports a measured property the stylesheet never declares', () => {
		const coverage = propertyCoverage(
			[ 'background-color:rgba(0, 0, 0, 0.5)' ],
			'.a{background-image:url(x)}'
		);
		expect( coverage.unrendered ).toEqual( [ 'background-color' ] );
	} );

	it( 'passes when every measured property is declared', () => {
		const coverage = propertyCoverage(
			[ 'background-color:#fff', 'line-height:37.7276px' ],
			'.a{background-color:#fff;line-height:37.7px}'
		);
		expect( coverage.unrendered ).toEqual( [] );
	} );

	it( 'is one-directional: extra stylesheet properties are not drift', () => {
		const coverage = propertyCoverage( [ 'color:#fff' ], '.a{color:#fff;gap:1rem;display:grid}' );
		expect( coverage.unrendered ).toEqual( [] );
	} );

	it( 'deduplicates a property measured on several facts', () => {
		const coverage = propertyCoverage( [ 'color:#fff', 'color:#000' ], '' );
		expect( coverage.measured ).toEqual( [ 'color' ] );
	} );
} );

describe( 'describePropertyCoverage', () => {
	it( 'stays quiet on a clean run', () => {
		expect( describePropertyCoverage( { measured: [ 'color' ], unrendered: [] } ) ).toBeUndefined();
	} );

	it( 'names the properties that went missing', () => {
		expect( describePropertyCoverage( { measured: [ 'line-height' ], unrendered: [ 'line-height' ] } ) ).toContain(
			'line-height'
		);
	} );
} );
