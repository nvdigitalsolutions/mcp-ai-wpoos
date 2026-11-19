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

import { applyMeasuredHeader } from '../lib/design.ts';
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
