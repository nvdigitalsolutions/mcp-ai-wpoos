// ---------------------------------------------------------------------------
// measured-header-reconcile.test.ts — the measured sticky header outranks the
// stylesheet harvest.
//
// virtuallyjamaica's Brando header declares a red `#dd3333` `.navbar` background
// that a more specific transparent variation overrides, so `analyzeChrome`'s
// stylesheet read picked a strip the source never paints — the built header
// rendered bright red over the hero while the live one is transparent. The
// browser measurement is the tie-breaker, and the container ink is separate from
// the nav links' colour (container #626262, links #ffffff).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { applyMeasuredChromeSize, applyMeasuredHeader, applyMeasuredNavStyle } from '../lib/design.ts';
import type { ForgeChrome, ForgeStickyHeader } from '../lib/types.ts';

const chrome = (): ForgeChrome => ( { headerBg: '#dd3333', headerInk: '#ffffff', navInk: '#ffffff' } );

describe( 'applyMeasuredHeader', () => {
	it( 'clears a harvested background when the measured resting header is transparent', () => {
		const target = chrome();
		applyMeasuredHeader( target, {
			sticky: true,
			restInk: 'rgb(98, 98, 98)',
			pinnedBg: 'rgba(0, 0, 0, 0.7)',
			bgChanged: true,
		} );

		// No strip at rest — the measurement says the header floats over the hero.
		expect( target.headerBg ).toBeUndefined();
		expect( target.headerTransparent ).toBe( true );
	} );

	it( 'takes the container ink from the measurement but leaves the nav link colour alone', () => {
		const target = chrome();
		applyMeasuredHeader( target, { sticky: true, restInk: 'rgb(98, 98, 98)' } );

		expect( target.headerInk ).toBe( 'rgb(98, 98, 98)' );
		expect( target.navInk ).toBe( '#ffffff' );
	} );

	it( 'uses a measured opaque resting background over the harvest', () => {
		const target = chrome();
		applyMeasuredHeader( target, { sticky: true, restBg: '#04132b', restInk: '#ffffff' } );

		expect( target.headerBg ).toBe( '#04132b' );
		expect( target.headerTransparent ).toBeUndefined();
	} );

	it( 'does nothing when the header does not pin', () => {
		const target = chrome();
		applyMeasuredHeader( target, { sticky: false, restBg: '#000000' } );

		expect( target.headerBg ).toBe( '#dd3333' );
	} );

	// OUT OF FLOW is not PINNED. An `absolute` header sits at y=0 with the first
	// band flowing beneath it; it never pins. The overlay facts must survive the
	// old `!sticky` early return, or the rebuild keeps the header in the flow and
	// a white wordmark lands on the page background (virtuallysrilanka).
	it( 'records a transparent OVERLAY header that does not pin', () => {
		const target = chrome();
		applyMeasuredHeader( target, {
			sticky: false,
			position: 'absolute',
			restInk: 'rgb(255, 255, 255)',
			restHeight: 79,
		} );

		expect( target.headerBg ).toBeUndefined();
		expect( target.headerTransparent ).toBe( true );
		expect( target.headerInk ).toBe( 'rgb(255, 255, 255)' );
	} );

	it( 'keeps a measured opaque surface on an absolute header', () => {
		const target = chrome();
		applyMeasuredHeader( target, { sticky: false, position: 'absolute', restBg: '#ffffff' } );

		expect( target.headerBg ).toBe( '#ffffff' );
		expect( target.headerTransparent ).toBeUndefined();
	} );

	it( 'leaves a STATIC header alone even when it measures transparent', () => {
		const target = chrome();
		applyMeasuredHeader( target, { sticky: false, position: 'static', restInk: 'rgb(1, 2, 3)' } );

		expect( target.headerBg ).toBe( '#dd3333' );
		expect( target.headerTransparent ).toBeUndefined();
		expect( target.headerInk ).toBe( '#ffffff' );
	} );

	it( 'does nothing without a chrome block', () => {
		expect( () => applyMeasuredHeader( undefined, { sticky: true, restBg: '#000000' } ) ).not.toThrow();
	} );
} );

// ---------------------------------------------------------------------------
// The measured nav type beats the stylesheet harvest.
//
// `analyzeChrome` resolves the source's CSS statically: a relative `font-size`
// is read against the 16px root and `em` tracking is left unresolved, so
// monicarajiv's `.888em / .02em` menu was recorded as `16px / .02em` and every
// forged page rendered it one step too large. `measureChromeGeometry` reads the
// LINK's computed style instead (14.208px / 0.28416px).
// ---------------------------------------------------------------------------
describe( 'applyMeasuredNavStyle', () => {
	const harvested = (): ForgeChrome => ( {
		navStyle: { size: '16px', weight: '400', transform: 'uppercase', letterSpacing: '.02em' },
		navInk: 'rgba(74, 74, 74, 0.85)',
	} );

	it( 'replaces the guessed type wholesale with the computed one', () => {
		const target = harvested();
		const applied = applyMeasuredNavStyle( target, {
			desktop: { measure: 1080, padX: 15 },
			navStyle: { size: '14.208px', weight: '400', transform: 'uppercase', letterSpacing: '0.28416px' },
		} );

		expect( applied ).toBe( true );
		expect( target.navStyle ).toEqual( {
			size: '14.208px',
			weight: '400',
			transform: 'uppercase',
			letterSpacing: '0.28416px',
		} );
		// The ink is a separate fact and must survive the overlay.
		expect( target.navInk ).toBe( 'rgba(74, 74, 74, 0.85)' );
	} );

	it( 'drops tracking the browser reports as none', () => {
		const target = harvested();
		applyMeasuredNavStyle( target, { navStyle: { size: '15px', weight: '600' } } );
		expect( target.navStyle ).toEqual( { size: '15px', weight: '600' } );
		expect( target.navStyle?.letterSpacing ).toBeUndefined();
	} );

	it( 'leaves the harvest alone when nothing was measured', () => {
		const target = harvested();
		expect( applyMeasuredNavStyle( target, { desktop: { measure: 1080 } } ) ).toBe( false );
		expect( target.navStyle?.size ).toBe( '16px' );
		expect( applyMeasuredNavStyle( undefined, { navStyle: { size: '14px' } } ) ).toBe( false );
	} );
} );

// ---------------------------------------------------------------------------
// The copyright bar's size. `chromeSizeFrom` reads a DECLARATION, not a computed
// value: monicarajiv declares `body { font-size: 1.125rem }`, which the stylesheet
// chain could not resolve, and the rebuilt footer painted 18px where the source's
// `.copyright-footer` paints 15.984px (measured 2026-11-21).
// ---------------------------------------------------------------------------
describe( 'applyMeasuredChromeSize', () => {
	const guessed = (): ForgeChrome => ( { chromeSize: '18px', navInk: '#ffffff' } );

	it( 'replaces the guessed size with the copyright computed one', () => {
		const target = guessed();
		const applied = applyMeasuredChromeSize( target, { chromeSize: '15.984px' } );

		expect( applied ).toBe( true );
		expect( target.chromeSize ).toBe( '15.984px' );
		// Unrelated chrome facts survive the overlay.
		expect( target.navInk ).toBe( '#ffffff' );
	} );

	it( 'reports no change when the measurement already matches the guess', () => {
		const target: ForgeChrome = { chromeSize: '16px' };
		expect( applyMeasuredChromeSize( target, { chromeSize: '16px' } ) ).toBe( false );
		expect( target.chromeSize ).toBe( '16px' );
	} );

	it( 'keeps the guess when the fact is absent or not a px length', () => {
		const target = guessed();
		expect( applyMeasuredChromeSize( target, { desktop: { measure: 1080 } } ) ).toBe( false );
		expect( applyMeasuredChromeSize( target, { chromeSize: 'normal' } ) ).toBe( false );
		expect( target.chromeSize ).toBe( '18px' );
		expect( applyMeasuredChromeSize( undefined, { chromeSize: '15.984px' } ) ).toBe( false );
	} );
} );
