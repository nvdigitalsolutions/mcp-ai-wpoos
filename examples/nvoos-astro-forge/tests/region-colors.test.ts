// ---------------------------------------------------------------------------
// region-colors.test.ts — parsing, CIEDE2000, and the region verdicts.
//
// The perceptual formula is pinned to Sharma/Wu/Dalal's published CIEDE2000
// test data: a regression in the maths is otherwise invisible (ΔE values still
// "look plausible").
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	applyBandSurfaces,
	colorDeltaE,
	compareRegionColorModes,
	compareRegionColors,
	deltaE2000,
	GRADIENT_SURFACE_RE,
	normalizeColorModes,
	normalizeCssColor,
	parseCssColor,
	srgbToLab,
} from '../lib/region-colors.ts';
import type { ForgeSection, RegionColors } from '../lib/types.ts';

/** The single-scheme regions shape the per-mode comparer works on. */
type ForgeColorBaseline = Record< string, RegionColors >;

describe( 'parseCssColor', () => {
	it( 'parses short and long hex', () => {
		expect( parseCssColor( '#fff' ) ).toEqual( { r: 255, g: 255, b: 255, a: 1 } );
		expect( parseCssColor( '#2a2a2a' ) ).toEqual( { r: 42, g: 42, b: 42, a: 1 } );
	} );

	it( 'parses hex with alpha', () => {
		const parsed = parseCssColor( '#ff000080' );
		expect( parsed?.r ).toBe( 255 );
		expect( parsed?.a ).toBeCloseTo( 128 / 255, 5 );
	} );

	it( 'parses rgb()/rgba() in comma and space syntax', () => {
		expect( parseCssColor( 'rgb(254, 108, 97)' ) ).toEqual( { r: 254, g: 108, b: 97, a: 1 } );
		expect( parseCssColor( 'rgba(0 0 0 / 0.5)' ) ).toEqual( { r: 0, g: 0, b: 0, a: 0.5 } );
	} );

	it( 'treats transparent as zero alpha', () => {
		expect( parseCssColor( 'transparent' ) ).toEqual( { r: 0, g: 0, b: 0, a: 0 } );
	} );

	it( 'returns undefined for unparseable values', () => {
		expect( parseCssColor( 'linear-gradient(red, blue)' ) ).toBeUndefined();
	} );
} );

describe( 'normalizeCssColor', () => {
	it( 'canonicalises to #rrggbb', () => {
		expect( normalizeCssColor( 'rgb(254, 108, 97)' ) ).toBe( '#fe6c61' );
		expect( normalizeCssColor( '#FFF' ) ).toBe( '#ffffff' );
	} );

	it( 'collapses near-transparent to transparent', () => {
		expect( normalizeCssColor( 'rgba(0,0,0,0)' ) ).toBe( 'transparent' );
		expect( normalizeCssColor( 'rgba(255,255,255,0.2)' ) ).toBe( 'transparent' );
	} );

	it( 'keeps mostly-opaque surfaces as their colour', () => {
		expect( normalizeCssColor( 'rgba(255,255,255,0.9)' ) ).toBe( '#ffffff' );
	} );
} );

describe( 'CIEDE2000', () => {
	// Sharma, Wu & Dalal (2005) test data — pairs 1 and 2.
	it( 'matches the published reference pairs', () => {
		expect( deltaE2000( [ 50, 2.6772, -79.7751 ], [ 50, 0, -82.7485 ] ) ).toBeCloseTo( 2.0425, 4 );
		expect( deltaE2000( [ 50, 3.1571, -77.2803 ], [ 50, 0, -82.7485 ] ) ).toBeCloseTo( 2.8615, 4 );
	} );

	it( 'is 0 for identical colours', () => {
		expect( deltaE2000( [ 50, 10, -20 ], [ 50, 10, -20 ] ) ).toBe( 0 );
	} );

	it( 'maps sRGB endpoints to the expected L* range', () => {
		const white = srgbToLab( 255, 255, 255 );
		const black = srgbToLab( 0, 0, 0 );
		expect( white[ 0 ] ).toBeCloseTo( 100, 1 );
		expect( black[ 0 ] ).toBeCloseTo( 0, 3 );
		expect( colorDeltaE( '#ffffff', '#000000' )! ).toBeGreaterThan( 50 );
	} );

	it( 'refuses to compare transparent surfaces', () => {
		expect( colorDeltaE( 'transparent', '#ffffff' ) ).toBeUndefined();
	} );
} );

describe( 'compareRegionColors', () => {
	const source: ForgeColorBaseline = {
		body: { background: '#ffffff', text: '#2a2a2a' },
		header: { background: '#ffffff', text: '#2a2a2a' },
		footer: { background: '#ededed', text: '#2a2a2a' },
	};

	it( 'passes identical measurements', () => {
		const report = compareRegionColors( source, source );
		expect( report.deviations ).toEqual( [] );
		expect( report.compared ).toBe( 6 );
	} );

	it( 'fails a clearly different surface colour', () => {
		const built: ForgeColorBaseline = {
			...source,
			footer: { background: '#000000', text: '#2a2a2a' },
		};
		const report = compareRegionColors( source, built );

		expect( report.deviations ).toHaveLength( 1 );
		expect( report.deviations[ 0 ] ).toMatchObject( {
			region: 'footer',
			metric: 'background',
			severity: 'fail',
		} );
	} );

	it( 'honours custom thresholds', () => {
		const built: ForgeColorBaseline = {
			...source,
			body: { background: '#000000', text: '#2a2a2a' },
		};
		expect( compareRegionColors( source, built, { warnAt: 1, failAt: 200 } ).deviations[ 0 ].severity ).toBe( 'warn' );
	} );

	it( 'reports a region the build dropped', () => {
		const built: ForgeColorBaseline = { body: source.body, footer: source.footer };
		const report = compareRegionColors( source, built );

		expect( report.missing ).toEqual( [ 'header' ] );
	} );

	it( 'skips transparent surfaces instead of guessing the backdrop', () => {
		const built: ForgeColorBaseline = {
			...source,
			header: { background: 'transparent', text: '#2a2a2a' },
		};
		const report = compareRegionColors( source, built );

		expect( report.skipped ).toEqual( [ 'light.header.background' ] );
		expect( report.deviations ).toEqual( [] );
	} );
} );

describe( 'band surfaces (gradient / photo / flat)', () => {
	const gradient =
		'linear-gradient(rgb(4, 19, 43) 0%, rgb(8, 26, 67) 100%)';

	it( 'classifies the gradient forms the browser emits', () => {
		expect( GRADIENT_SURFACE_RE.test( gradient ) ).toBe( true );
		expect( GRADIENT_SURFACE_RE.test( 'radial-gradient(circle, #fff, #000)' ) ).toBe( true );
		expect( GRADIENT_SURFACE_RE.test( 'conic-gradient(#fff, #000)' ) ).toBe( true );
		expect( GRADIENT_SURFACE_RE.test( 'repeating-linear-gradient(45deg, #fff 0 10px, #000 10px 20px)' ) ).toBe( true );
		expect( GRADIENT_SURFACE_RE.test( 'url("https://example.com/hero.jpg")' ) ).toBe( false );
		expect( GRADIENT_SURFACE_RE.test( '#dd3333' ) ).toBe( false );
	} );

	it( 'writes a measured gradient into bgGradient, not bgColor', () => {
		const sections: ForgeSection[] = [ { id: 'a1', anchor: 'hero' } ];
		applyBandSurfaces( sections, () => ( { background: '#04132b', text: '#ffffff', image: gradient } ) );

		expect( sections[ 0 ].bgGradient ).toBe( gradient );
		expect( sections[ 0 ].bgColor ).toBeUndefined();
	} );

	it( 'writes a measured flat fill into bgColor', () => {
		const sections: ForgeSection[] = [ { id: 'a1' } ];
		applyBandSurfaces( sections, () => ( { background: '#dd3333', text: '#ffffff' } ) );

		expect( sections[ 0 ].bgColor ).toBe( '#dd3333' );
		expect( sections[ 0 ].bgGradient ).toBeUndefined();
	} );

	it( 'captures a photo url without wiring it (bgImage means hero)', () => {
		const sections: ForgeSection[] = [ { id: 'a1' } ];
		const photo = 'url("https://example.com/hero.jpg")';
		applyBandSurfaces( sections, () => ( { background: '#000000', text: '#ffffff', image: photo } ) );

		expect( sections[ 0 ].bgImage ).toBeUndefined();
		expect( sections[ 0 ].bgGradient ).toBeUndefined();
		expect( sections[ 0 ].bgColor ).toBe( '#000000' );
	} );

	it( 'never overwrites a pinned surface', () => {
		const pinned: ForgeSection = { id: 'a1', bgColor: '#dd3333' };
		applyBandSurfaces( [ pinned ], () => ( { background: '#000000', text: '#ffffff', image: gradient } ) );

		expect( pinned.bgColor ).toBe( '#dd3333' );
		expect( pinned.bgGradient ).toBe( gradient );
	} );

	it( 'skips a transparency reading (the body surface showing through)', () => {
		const sections: ForgeSection[] = [ { id: 'a1' } ];
		applyBandSurfaces( sections, () => ( { background: 'transparent', text: '#ffffff' } ) );
		expect( sections[ 0 ].bgColor ).toBeUndefined();
	} );

	it( 'treats a fill identical to the page body as transparent', () => {
		const sections: ForgeSection[] = [ { id: 'a1' } ];
		applyBandSurfaces( sections, () => ( { background: '#ffffff', text: '#000000' } ), '#ffffff' );
		expect( sections[ 0 ].bgColor ).toBeUndefined();
	} );

	it( 'leaves a band with no measured region untouched', () => {
		const sections: ForgeSection[] = [ { id: 'a1' } ];
		applyBandSurfaces( sections, () => undefined );
		expect( sections[ 0 ] ).toEqual( { id: 'a1' } );
	} );
} );

describe( 'compareRegionColors with a band image', () => {
	const gradient = 'linear-gradient(rgb(0, 0, 0), rgb(4, 19, 43))';

	it( 'fails when the source painted a gradient and the build did not', () => {
		const source: ForgeColorBaseline = { band: { background: '#04132b', text: '#ffffff', image: gradient } };
		const built: ForgeColorBaseline = { band: { background: '#04132b', text: '#ffffff' } };
		const report = compareRegionColors( source, built );

		expect( report.compared ).toBe( 3 );
		expect( report.deviations ).toEqual( [
			expect.objectContaining( { region: 'band', metric: 'image', severity: 'fail', actual: null, deltaE: null } ),
		] );
	} );

	it( 'ignores whitespace and quote differences in the same gradient', () => {
		const source: ForgeColorBaseline = { band: { background: '#000000', text: '#ffffff', image: gradient } };
		const built: ForgeColorBaseline = {
			band: { background: '#000000', text: '#ffffff', image: 'linear-gradient( rgb(0, 0, 0), rgb(4, 19, 43) )' },
		};
		const report = compareRegionColors( source, built );

		expect( report.deviations ).toEqual( [] );
	} );

	it( 'warns (does not fail) on a differing gradient', () => {
		const source: ForgeColorBaseline = { band: { background: '#000000', text: '#ffffff', image: gradient } };
		const built: ForgeColorBaseline = {
			band: { background: '#000000', text: '#ffffff', image: 'linear-gradient(rgb(0, 0, 0), rgb(255, 0, 0))' },
		};
		const report = compareRegionColors( source, built );

		expect( report.deviations[ 0 ] ).toMatchObject( { metric: 'image', severity: 'warn', deltaE: null } );
	} );

	it( 'does not compare a photo url (the localizer rewrites it)', () => {
		const source: ForgeColorBaseline = {
			band: { background: '#000000', text: '#ffffff', image: 'url("https://source.example/hero.jpg")' },
		};
		const built: ForgeColorBaseline = {
			band: { background: '#000000', text: '#ffffff', image: 'url("/media/abc-hero.jpg")' },
		};
		const report = compareRegionColors( source, built );

		expect( report.deviations ).toEqual( [] );
		expect( report.compared ).toBe( 2 );
	} );
} );

describe( 'colour schemes (light/dark)', () => {
	const light = {
		body: { background: '#ffffff', text: '#2a2a2a' },
		header: { background: '#ffffff', text: '#2a2a2a' },
	};
	const dark = {
		body: { background: '#111111', text: '#f5f5f5' },
		header: { background: '#0a0a0a', text: '#ffffff' },
	};

	it( 'compares each mode independently', () => {
		const source = { light, dark };
		const built = { light, dark: { ...dark, header: { background: '#ffffff', text: '#ffffff' } } };
		const report = compareRegionColorModes( source, built );

		expect( report.deviations ).toHaveLength( 1 );
		expect( report.deviations[ 0 ] ).toMatchObject( {
			mode: 'dark',
			region: 'header',
			metric: 'background',
		} );
	} );

	it( 'reports a scheme the build dropped entirely', () => {
		const report = compareRegionColorModes( { light, dark }, { light } );
		expect( report.missing ).toContain( 'dark (scheme)' );
	} );

	it( 'treats a legacy flat baseline as light-only', () => {
		expect( normalizeColorModes( light ) ).toEqual( { light } );
		expect( normalizeColorModes( { light, dark } ) ).toEqual( { light, dark } );
	} );

	it( 'does not gate a scheme the source never implemented', () => {
		// Source: dark identical to light (no prefers-color-scheme support).
		// The build adds a dark theme — an enhancement, not a regression.
		const report = compareRegionColorModes( { light, dark: light }, { light, dark } );

		expect( report.unimplemented ).toContain( 'dark' );
		expect( report.deviations ).toEqual( [] );
		expect( report.missing ).toEqual( [] );
	} );

	it( 'gates dark when the source does define it', () => {
		const report = compareRegionColorModes( { light, dark }, { light } );
		expect( report.unimplemented ).toEqual( [] );
	} );
} );
