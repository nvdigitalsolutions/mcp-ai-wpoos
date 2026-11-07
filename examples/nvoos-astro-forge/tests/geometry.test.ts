// ---------------------------------------------------------------------------
// geometry.test.ts — the box-diff verdict (pass / warn / fail).
//
// Geometry is compared, not pixels: box metrics survive font and
// anti-aliasing differences that make screenshot diffs flaky.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { DEFAULT_GEOMETRY_TOLERANCE, diffGeometry } from '../lib/geometry.ts';
import { mergeProfileDraft } from '../lib/discovery.ts';
import type { BandGeometry, ForgeGeometry, SiteProfile } from '../lib/types.ts';

const band = ( overrides: Partial< BandGeometry > = {} ): BandGeometry => ( {
	height: 400,
	width: 1440,
	paddingTop: 100,
	paddingBottom: 100,
	marginTop: 0,
	marginBottom: 0,
	...overrides,
} );

const geometry = ( bandBox: BandGeometry ): ForgeGeometry => ( { abc: { desktop: bandBox } } );

describe( 'diffGeometry', () => {
	it( 'reports nothing when the boxes match', () => {
		expect( diffGeometry( geometry( band() ), geometry( band() ) ) ).toEqual( [] );
	} );

	it( 'ignores drift inside the tolerance', () => {
		// spacing tolerance is 1px — a 1px rounding is not a regression.
		expect( diffGeometry( geometry( band() ), geometry( band( { marginTop: 1 } ) ) ) ).toEqual( [] );
	} );

	it( 'warns between the tolerance and twice the tolerance', () => {
		const deviations = diffGeometry(
			geometry( band() ),
			geometry( band( { height: 400 + DEFAULT_GEOMETRY_TOLERANCE.height + 1 } ) )
		);

		expect( deviations ).toHaveLength( 1 );
		expect( deviations[ 0 ].severity ).toBe( 'warn' );
		expect( deviations[ 0 ].metric ).toBe( 'height' );
	} );

	it( 'fails beyond twice the tolerance', () => {
		const deviations = diffGeometry(
			geometry( band() ),
			geometry( band( { height: 900 } ) )
		);

		expect( deviations[ 0 ].severity ).toBe( 'fail' );
		expect( deviations[ 0 ].delta ).toBe( 500 );
	} );

	it( 'fails a band missing from the build', () => {
		const deviations = diffGeometry( geometry( band() ), {} );

		expect( deviations ).toHaveLength( 1 );
		expect( deviations[ 0 ] ).toMatchObject( { metric: 'band', actual: null, severity: 'fail' } );
	} );

	it( 'compares every viewport independently', () => {
		const source: ForgeGeometry = {
			abc: { desktop: band(), tablet: band( { height: 600 } ) },
		};
		const built: ForgeGeometry = {
			abc: { desktop: band(), tablet: band( { height: 600 + 15 } ) },
		};

		const deviations = diffGeometry( source, built );

		expect( deviations ).toHaveLength( 1 );
		expect( deviations[ 0 ].viewport ).toBe( 'tablet' );
	} );

	it( 'honours a custom tolerance', () => {
		const deviations = diffGeometry(
			geometry( band() ),
			geometry( band( { height: 430 } ) ),
			{ height: 40, width: 2, spacing: 1 }
		);

		expect( deviations ).toEqual( [] );
	} );
} );

describe( 'geometry survives analyze --write (118 §3)', () => {
	it( 'refreshes the measured baseline when the merge runs', () => {
		const existing = {
			url: 'https://example.com',
			collections: [],
			geometry: { abc: { desktop: band() } },
		} as unknown as SiteProfile;
		const fresh = {
			url: 'https://example.com',
			collections: [],
			geometry: { abc: { desktop: band( { height: 500 } ) } },
		} as unknown as SiteProfile;

		const merged = mergeProfileDraft( existing, fresh );

		expect( merged.geometry?.abc.desktop.height ).toBe( 500 );
	} );
} );
