// ---------------------------------------------------------------------------
// chrome-audit.ts — the chrome STATES the colour gate cannot see.
//
// The colour gate measures regions at rest, at 1440px. That is blind to the one
// piece of chrome a reader meets on a phone: the OPENED menu. virtuallyjamaica's
// rebuilt panel had no surface at all, so its white links painted onto the photo
// hero and were unreadable — and every gate passed, because nothing measured an
// opened disclosure (2026-11-12).
//
// `measureMobileNav` reads both sides (it takes a URL), so the gate is a pure
// diff of the two measured facts: did the build paint a panel, is it the right
// colour, and does its ink match. Contrast alone is NOT enough — white on
// virtuallyjamaica's red hero is 4.57:1, so a WCAG-only check would have passed
// the broken menu.
// ---------------------------------------------------------------------------

import { colorDeltaE, GRADIENT_SURFACE_RE, normalizeCssColor, normalizeCssValue } from './region-colors.ts';
import type { ForgeMobileNav } from './types.ts';

export interface ChromeDeviation {
	/** The fact that drifted, e.g. `mobileNav.background`. */
	fact: string;
	expected: string | null;
	actual: string | null;
	deltaE: number | null;
	severity: 'warn' | 'fail';
	/** What the reader loses, in one line. */
	note?: string;
}

export interface ChromeAuditReport {
	deviations: ChromeDeviation[];
	compared: number;
	skipped: string[];
}

export interface ChromeAuditOptions {
	/** ΔE00 at/above this warns (default 3). */
	warnAt?: number;
	/** ΔE00 at/above this fails (default 6). */
	failAt?: number;
	/** Inline length drift (px) that warns (default 4). */
	lengthTolerance?: number;
}

const isGradient = ( value: string | undefined ): boolean => Boolean( value && GRADIENT_SURFACE_RE.test( value ) );

/** A colour, or `undefined` when the value is a gradient. */
const asColor = ( value: string | undefined ): string | undefined => {
	if ( ! value || isGradient( value ) ) {
		return undefined;
	}
	const normalized = normalizeCssColor( value );
	return normalized === 'transparent' ? undefined : normalized;
};

/** Does this fact PAINT something a reader can see? */
const hasSurface = ( value: string | undefined ): boolean => isGradient( value ) || asColor( value ) !== undefined;

/**
 * Diff the opened mobile menu: source vs build.
 *
 * A build panel with NO surface is a hard failure, whatever its ink — that is
 * exactly the "menu is invisible on some bands" bug. A different surface colour
 * is graded like any other colour (ΔE00), and the rhythm facts (inset, row
 * pitch) warn because a few px is typographic drift, not a regression.
 */
export function compareMobileNav(
	expected: ForgeMobileNav | undefined,
	actual: ForgeMobileNav | undefined,
	options: ChromeAuditOptions = {}
): ChromeAuditReport {
	const warnAt = options.warnAt ?? 3;
	const failAt = options.failAt ?? 6;
	const tolerance = options.lengthTolerance ?? 4;
	const deviations: ChromeDeviation[] = [];
	const skipped: string[] = [];
	let compared = 0;

	if ( ! expected ) {
		skipped.push( 'mobileNav (the source never collapses its nav)' );
		return { deviations, compared, skipped };
	}
	if ( ! actual ) {
		deviations.push( {
			fact: 'mobileNav',
			expected: expected.background ?? 'an opened panel',
			actual: null,
			deltaE: null,
			severity: 'fail',
			note: 'the source opens a menu at this width; the build has no disclosure at all',
		} );
		return { deviations, compared: 1, skipped };
	}

	// The surface. A measured panel with no paint is THE failure this gate exists
	// for: the links land on whatever band sits behind the header.
	if ( hasSurface( expected.background ) ) {
		compared++;
		if ( ! hasSurface( actual.background ) ) {
			deviations.push( {
				fact: 'mobileNav.background',
				expected: expected.background ?? null,
				actual: actual.background ?? 'transparent',
				deltaE: null,
				severity: 'fail',
				note: 'the opened menu has no surface — its links paint onto whatever band is behind the header',
			} );
		} else if ( isGradient( expected.background ) || isGradient( actual.background ) ) {
			if ( normalizeCssValue( expected.background ?? '' ) !== normalizeCssValue( actual.background ?? '' ) ) {
				deviations.push( {
					fact: 'mobileNav.background',
					expected: expected.background ?? null,
					actual: actual.background ?? null,
					deltaE: null,
					severity: 'warn',
				} );
			}
		} else {
			const delta = colorDeltaE( expected.background ?? '', actual.background ?? '' );
			if ( delta === undefined ) {
				skipped.push( 'mobileNav.background' );
			} else if ( delta >= warnAt ) {
				deviations.push( {
					fact: 'mobileNav.background',
					expected: expected.background ?? null,
					actual: actual.background ?? null,
					deltaE: Math.round( delta * 100 ) / 100,
					severity: delta >= failAt ? 'fail' : 'warn',
				} );
			}
		}
	} else {
		skipped.push( 'mobileNav.background (the source panel is transparent)' );
	}

	// The ink. Compared only when both sides have a surface, because ink over an
	// unknown backdrop is not a comparable pair.
	if ( hasSurface( expected.background ) && hasSurface( actual.background ) ) {
		const expectedInk = asColor( expected.ink );
		const actualInk = asColor( actual.ink );
		if ( expectedInk && actualInk ) {
			compared++;
			const delta = colorDeltaE( expectedInk, actualInk );
			if ( delta !== undefined && delta >= warnAt ) {
				deviations.push( {
					fact: 'mobileNav.ink',
					expected: expected.ink ?? null,
					actual: actual.ink ?? null,
					deltaE: Math.round( delta * 100 ) / 100,
					severity: delta >= failAt ? 'fail' : 'warn',
				} );
			}
		} else {
			skipped.push( 'mobileNav.ink' );
		}
	}

	if ( typeof expected.overlay === 'boolean' && typeof actual.overlay === 'boolean' && expected.overlay !== actual.overlay ) {
		compared++;
		deviations.push( {
			fact: 'mobileNav.overlay',
			expected: expected.overlay ? 'overlays the page' : 'in flow',
			actual: actual.overlay ? 'overlays the page' : 'in flow',
			deltaE: null,
			severity: 'warn',
			note: expected.overlay
				? 'the source overlays; an in-flow panel reflows the page when it opens'
				: 'the source pushes the page down; an overlay hides content behind it',
		} );
	}

	for ( const key of [ 'padX', 'itemPitch' ] as const ) {
		const from = expected[ key ];
		const to = actual[ key ];
		if ( typeof from !== 'number' || typeof to !== 'number' ) {
			continue;
		}
		compared++;
		if ( Math.abs( from - to ) > tolerance ) {
			deviations.push( {
				fact: `mobileNav.${ key }`,
				expected: `${ from }px`,
				actual: `${ to }px`,
				deltaE: null,
				severity: 'warn',
			} );
		}
	}

	return { deviations, compared, skipped };
}
