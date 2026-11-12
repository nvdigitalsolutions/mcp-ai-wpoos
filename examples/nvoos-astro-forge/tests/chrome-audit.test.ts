// ---------------------------------------------------------------------------
// chrome-audit.test.ts — the opened mobile menu, source vs build.
//
// The case that matters is the one no other gate catches: a build whose panel
// has NO surface. virtuallyjamaica's rebuilt menu painted white links straight
// onto the photo hero, and every gate passed because nothing measured an opened
// disclosure (2026-11-12).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { compareMobileNav } from '../lib/chrome-audit.ts';
import type { ForgeMobileNav } from '../lib/types.ts';

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
