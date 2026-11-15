// ---------------------------------------------------------------------------
// chrome-audit.test.ts — the opened mobile menu, source vs build.
//
// The case that matters is the one no other gate catches: a build whose panel
// has NO surface. virtuallyjamaica's rebuilt menu painted white links straight
// onto the photo hero, and every gate passed because nothing measured an opened
// disclosure (2026-11-12).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { compareChromeGeometry, compareFooterBrand, compareMobileNav } from '../lib/chrome-audit.ts';
import type { ForgeChromeGeometry, ForgeFooterBrand, ForgeMobileNav } from '../lib/types.ts';

const brando: ForgeMobileNav = {
	background: 'rgba(0, 0, 0, 0.95)',
	ink: 'rgb(255, 255, 255)',
	padX: 15,
	padY: 0,
	itemPitch: 30,
	overlay: true,
	fullWidth: true,
};

describe( 'compareMobileNav', () => {
	it( 'passes the source against itself', () => {
		const report = compareMobileNav( brando, brando );

		expect( report.deviations ).toEqual( [] );
		expect( report.compared ).toBe( 4 );
	} );

	it( 'fails a build panel with no surface (the invisible-menu bug)', () => {
		// The template used to toggle `display` only, so the links landed on
		// whatever band sat behind the header. No contrast check would catch this
		// reliably — white on virtuallyjamaica\'s red hero is 4.57:1.
		const report = compareMobileNav( brando, { ...brando, background: undefined } );

		expect( report.deviations ).toHaveLength( 1 );
		expect( report.deviations[ 0 ] ).toMatchObject( {
			fact: 'mobileNav.background',
			expected: 'rgba(0, 0, 0, 0.95)',
			actual: 'transparent',
			severity: 'fail',
		} );
	} );

	it( 'treats a fully transparent background as no surface', () => {
		const report = compareMobileNav( brando, { ...brando, background: 'rgba(0, 0, 0, 0)' } );
		expect( report.deviations[ 0 ] ).toMatchObject( { fact: 'mobileNav.background', severity: 'fail' } );
	} );

	it( 'fails when the build has no disclosure at all', () => {
		const report = compareMobileNav( brando, undefined );

		expect( report.deviations ).toHaveLength( 1 );
		expect( report.deviations[ 0 ] ).toMatchObject( { fact: 'mobileNav', severity: 'fail' } );
	} );

	it( 'grades a different surface colour on its ΔE', () => {
		// White instead of the source's near-black sheet is a different colour.
		const glaring = compareMobileNav( brando, { ...brando, background: 'rgb(255, 255, 255)' } );
		expect( glaring.deviations[ 0 ] ).toMatchObject( {
			fact: 'mobileNav.background',
			severity: 'fail',
		} );

		// A 20/255 lift clears the default warn threshold (ΔE ≈ 3.7) but is not a
		// different colour; the shared ΔE contract grades it, no bespoke rule.
		const shifted = compareMobileNav( brando, { ...brando, background: 'rgb(20, 20, 20)' } );
		expect( shifted.deviations[ 0 ] ).toMatchObject( { severity: 'warn' } );
	} );

	it( 'grades the panel ink, but only over a surface on both sides', () => {
		const report = compareMobileNav( brando, { ...brando, ink: 'rgb(20, 20, 20)' } );
		expect( report.deviations[ 0 ] ).toMatchObject( { fact: 'mobileNav.ink', severity: 'fail' } );

		// With no surface there is no backdrop to measure ink against.
		const surfaceLess = compareMobileNav( brando, { ...brando, background: undefined, ink: 'rgb(20, 20, 20)' } );
		expect( surfaceLess.deviations.map( ( deviation ) => deviation.fact ) ).toEqual( [ 'mobileNav.background' ] );
	} );

	it( 'warns when the panel stops overlaying the page', () => {
		const report = compareMobileNav( brando, { ...brando, overlay: false } );

		expect( report.deviations ).toHaveLength( 1 );
		expect( report.deviations[ 0 ] ).toMatchObject( { fact: 'mobileNav.overlay', severity: 'warn' } );
	} );

	it( 'warns on rhythm drift but tolerates a pixel or two', () => {
		expect( compareMobileNav( brando, { ...brando, itemPitch: 32 } ).deviations ).toEqual( [] );

		const drifted = compareMobileNav( brando, { ...brando, itemPitch: 44 } );
		expect( drifted.deviations[ 0 ] ).toMatchObject( { fact: 'mobileNav.itemPitch', severity: 'warn' } );
	} );

	it( 'compares gradients as values, not as colours', () => {
		const gradient: ForgeMobileNav = { ...brando, background: 'linear-gradient(rgb(0, 0, 0), rgb(4, 19, 43))' };
		expect( compareMobileNav( gradient, gradient ).deviations ).toEqual( [] );

		const flat = compareMobileNav( gradient, { ...brando, background: 'rgb(0, 0, 0)' } );
		expect( flat.deviations[ 0 ] ).toMatchObject( { fact: 'mobileNav.background', severity: 'warn' } );
	} );

	it( 'skips everything when the source never collapses its nav', () => {
		const report = compareMobileNav( undefined, brando );

		expect( report.deviations ).toEqual( [] );
		expect( report.compared ).toBe( 0 );
		expect( report.skipped ).toEqual( [ 'mobileNav (the source never collapses its nav)' ] );
	} );
} );

// jayainc's measured box: a 1670px cap visible only at 1920, with a 35px
// gutter at every viewport.
const jayaBox: ForgeChromeGeometry = {
	wide: { measure: 1670, padX: 35 },
	desktop: { padX: 35 },
	tablet: { padX: 35 },
	mobile: { padX: 35 },
};

describe( 'compareChromeGeometry', () => {
	it( 'passes the source against itself', () => {
		const report = compareChromeGeometry( jayaBox, jayaBox );

		expect( report.deviations ).toEqual( [] );
		// wide: measure + padX; desktop/tablet/mobile: padX each.
		expect( report.compared ).toBe( 5 );
	} );

	it( 'FAILS when the source boxes the row and the build runs full-bleed', () => {
		// The reported drift: the build keeps one full-width flex row, so at 1920
		// there is no container to measure.
		const build: ForgeChromeGeometry = {
			desktop: { padX: 35 },
			tablet: { padX: 35 },
			mobile: { padX: 35 },
		};
		const report = compareChromeGeometry( jayaBox, build );
		const failure = report.deviations.find( ( deviation ) => deviation.severity === 'fail' );

		expect( failure ).toMatchObject( {
			fact: 'header box [wide].measure',
			expected: '1670px box',
			actual: 'full-bleed',
		} );
	} );

	it( 'warns (not fails) on a small cap drift — the box is a design decision', () => {
		const report = compareChromeGeometry( jayaBox, { ...jayaBox, wide: { measure: 1640, padX: 35 } } );

		expect( report.deviations ).toHaveLength( 1 );
		expect( report.deviations[ 0 ] ).toMatchObject( {
			fact: 'header box [wide].measure',
			severity: 'warn',
		} );
	} );

	it( 'warns when the gutter drifts between the source and the build', () => {
		const report = compareChromeGeometry( jayaBox, { ...jayaBox, desktop: { measure: 1670, padX: 16 } } );

		expect( report.deviations[ 0 ] ).toMatchObject( {
			fact: 'header box [desktop].padX',
			expected: '35px',
			actual: '16px',
			severity: 'warn',
		} );
	} );

	it( 'warns when the build records no gutter at all', () => {
		const report = compareChromeGeometry( jayaBox, { ...jayaBox, mobile: {} } );

		expect( report.deviations[ 0 ] ).toMatchObject( {
			fact: 'header box [mobile].padX',
			actual: null,
			severity: 'warn',
		} );
	} );

	it( 'skips when the source never measured a box', () => {
		const report = compareChromeGeometry( undefined, jayaBox );

		expect( report.deviations ).toEqual( [] );
		expect( report.compared ).toBe( 0 );
		expect( report.skipped.join( ' ' ) ).toContain( 'not measured on the source' );
	} );

	it( 'fails when the build has no measurable header at all', () => {
		const report = compareChromeGeometry( jayaBox, undefined );

		expect( report.deviations[ 0 ] ).toMatchObject( { fact: 'header box', severity: 'fail' } );
	} );
} );

// virtuallyjamaica's footer: socials left, brand mark right, a hairline above
// the copyright bar; both groups centred at mobile.
const brandoFooter: ForgeFooterBrand = {
	wide: { logo: 'end', socials: 'start' },
	desktop: { logo: 'end', socials: 'start' },
	tablet: { logo: 'end', socials: 'start' },
	mobile: { logo: 'center', socials: 'center' },
	divider: true,
};

describe( 'compareFooterBrand', () => {
	it( 'passes the source against itself', () => {
		const report = compareFooterBrand( brandoFooter, brandoFooter );

		expect( report.deviations ).toEqual( [] );
		// Four viewports × logo + socials, plus the divider.
		expect( report.compared ).toBe( 9 );
	} );

	it( 'FAILS when the brand mark sits on the wrong side', () => {
		// The reported drift: the rebuild stacked the logo left under the icons.
		const report = compareFooterBrand( brandoFooter, {
			...brandoFooter,
			desktop: { logo: 'start', socials: 'start' },
		} );

		expect( report.deviations[ 0 ] ).toMatchObject( {
			fact: 'footer brand [desktop].logo',
			expected: 'end',
			actual: 'start',
			severity: 'fail',
		} );
		 expect( report.deviations[ 0 ].note ).toContain( 'wrong side' );
	} );

	it( 'warns when a centred group moves to a side', () => {
		const report = compareFooterBrand( brandoFooter, {
			...brandoFooter,
			mobile: { logo: 'start', socials: 'center' },
		} );

		expect( report.deviations[ 0 ] ).toMatchObject( {
			fact: 'footer brand [mobile].logo',
			severity: 'warn',
		} );
	} );

	it( 'warns when the build records no position', () => {
		const report = compareFooterBrand( brandoFooter, {
			...brandoFooter,
			desktop: {},
		} );

		expect( report.deviations[ 0 ] ).toMatchObject( {
			fact: 'footer brand [desktop].logo',
			actual: null,
			severity: 'warn',
		} );
	} );

	it( 'warns when the divider disappears', () => {
		const report = compareFooterBrand( brandoFooter, { ...brandoFooter, divider: false } );

		expect( report.deviations[ 0 ] ).toMatchObject( {
			fact: 'footer brand.divider',
			expected: 'a hairline',
			severity: 'warn',
		} );
	} );

	it( 'warns when the mark’s rendered height drifts', () => {
		const expected: ForgeFooterBrand = {
			...brandoFooter,
			desktop: { logo: 'end', socials: 'start', logoHeight: '62px' },
		};
		const report = compareFooterBrand( expected, {
			...expected,
			desktop: { logo: 'end', socials: 'start', logoHeight: '48px' },
		} );

		expect( report.deviations[ 0 ] ).toMatchObject( {
			fact: 'footer brand [desktop].logoHeight',
			expected: '62px',
			actual: '48px',
			severity: 'warn',
		} );
	} );

	it( 'skips when the source has no measured footer row', () => {
		const report = compareFooterBrand( undefined, brandoFooter );

		expect( report.deviations ).toEqual( [] );
		expect( report.compared ).toBe( 0 );
		expect( report.skipped.join( ' ' ) ).toContain( 'not measured on the source' );
	} );

	it( 'fails when the build has no measurable social row', () => {
		const report = compareFooterBrand( brandoFooter, undefined );

		expect( report.deviations[ 0 ] ).toMatchObject( { fact: 'footer brand row', severity: 'fail' } );
	} );
} );
