// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// surface-coverage.ts — the gate that asks "did the page PAINT it?".
//
// Every gate we had checked that a fact was PRESENT: `fact consumption` counted
// an emitted theme as rendered even when the CSS was invalid, and the colour
// gate reported a band that paints nothing as "not comparable (transparent)".
// So eca.directory's about-us and contact-us rebuilt with no band images at all
// and every gate stayed green (2026-11-25).
//
// This pass compares the DECLARED surface facts against the BUILT markup:
// a band that declares a background image or a panel must carry one in the page.
// Deterministic, browser-free, and deliberately narrow — it checks the two
// signals that are never a page default (an image and a panel), not colours,
// which a band may legitimately share with the page.
// ---------------------------------------------------------------------------

import type { SiteProfile } from './types.ts';

/** One surface a band declares, on one page. */
export interface SurfaceExpectation {
	/** `/` for the home, else the page slug as the build writes it. */
	page: string;
	id: string;
	image?: boolean;
	panel?: boolean;
}

export interface SurfaceGap {
	page: string;
	id: string;
	missing: string[];
}

export interface SurfaceCoverageReport {
	checked: number;
	gaps: SurfaceGap[];
	/** Pages whose HTML could not be read (reported, not failed). */
	unreadable: string[];
}

/** What the built band ACTUALLY paints. */
export interface PaintedSurface {
	/** Any `background`/`background-color`/`background-image` on the wrapper. */
	background: boolean;
	/** A background image on the wrapper, or on a scoped `::before` layer. */
	image: boolean;
	/** The wrapper carries the panel class or a panel token. */
	panel: boolean;
}

/**
 * The inline style of the band's wrapper.
 *
 * The wrapper is the LAST occurrence of `data-section-id="<id>"` in the page:
 * the scoped `<style>` rules are printed first, so the markup wins.
 */
function wrapperTag( html: string, id: string ): string | undefined {
	const marker = `data-section-id="${ id }"`;
	let at = -1;
	let i = -1;
	while ( ( i = html.indexOf( marker, i + 1 ) ) !== -1 ) {
		at = i;
	}
	if ( at === -1 ) {
		return undefined;
	}
	const start = html.lastIndexOf( '<', at );
	const end = html.indexOf( '>', at );
	return start === -1 || end === -1 ? undefined : html.slice( start, end + 1 );
}

/** What the built page paints for one band. */
export function paintedSurface( html: string, id: string ): PaintedSurface | undefined {
	const tag = wrapperTag( html, id );
	if ( ! tag ) {
		return undefined;
	}
	const style = ( /style="([^"]*)"/.exec( tag ) ?? [ '', '' ] )[ 1 ];
	// A LAYER image lives in a scoped rule, not the wrapper's inline style.
	const scoped = new RegExp( `\\[data-section-id="${ id }"\\][^{]*\\{[^}]*background-image`, 'i' );
	return {
		background: /background(?:-color|-image)?\s*:/i.test( style ) || /--ds-panel-bg\s*:/i.test( style ),
		image: /background-image\s*:/i.test( style ) || scoped.test( html ),
		panel: /ds-section-wrap--panel/.test( tag ) || /--ds-panel-bg\s*:/i.test( style ),
	};
}

/**
 * The surfaces each page DECLARES, from the profile's measured facts.
 *
 * Only facts that are never a page default are collected: a band image and a
 * panel. A band colour is left out on purpose — a band that shares the page
 * background paints nothing and is still correct.
 */
export function collectSurfaceExpectations( profile: SiteProfile ): SurfaceExpectation[] {
	const out: SurfaceExpectation[] = [];
	const add = (
		page: string,
		sections: ReadonlyArray< {
			id?: string;
			bgImage?: string;
			bgGradient?: string;
			bgColor?: string;
			panel?: unknown;
		} >
	): void => {
		for ( const section of sections ) {
			if ( ! section.id ) {
				continue;
			}
			// Three shapes mean "this band paints an image": a bare URL, and a
			// declaration stored in the gradient/colour slot (the older data shape —
			// 19 of eca.directory's home bands carry one).
			const image =
				( typeof section.bgImage === 'string' && section.bgImage.trim() !== '' ) ||
				/background-image\s*:/i.test( section.bgGradient ?? '' ) ||
				/background-image\s*:/i.test( section.bgColor ?? '' );
			const panel = section.panel !== undefined && section.panel !== null;
			if ( image || panel ) {
				out.push( { page, id: section.id, ...( image ? { image: true } : {} ), ...( panel ? { panel: true } : {} ) } );
			}
		}
	};
	add( '/', profile.design?.sections ?? [] );
	for ( const [ slug, entry ] of Object.entries( profile.pageBands ?? {} ) ) {
		add( slug, entry.sections ?? [] );
	}
	return out;
}

/**
 * Compare the declarations against the built pages.
 *
 * `readPage` returns the page's HTML, or undefined when it is not in the build
 * (a page the project does not render is reported as unreadable, never failed).
 */
export function auditSurfaces(
	profile: SiteProfile,
	readPage: ( page: string ) => string | undefined
): SurfaceCoverageReport {
	const expectations = collectSurfaceExpectations( profile );
	const cache = new Map< string, string | undefined >();
	const gaps: SurfaceGap[] = [];
	const unreadable = new Set< string >();
	for ( const expectation of expectations ) {
		if ( ! cache.has( expectation.page ) ) {
			cache.set( expectation.page, readPage( expectation.page ) );
		}
		const html = cache.get( expectation.page );
		if ( html === undefined ) {
			unreadable.add( expectation.page );
			continue;
		}
		const painted = paintedSurface( html, expectation.id );
		if ( ! painted ) {
			gaps.push( { page: expectation.page, id: expectation.id, missing: [ 'band' ] } );
			continue;
		}
		const missing: string[] = [];
		if ( expectation.image && ! painted.image ) {
			missing.push( 'background-image' );
		}
		if ( expectation.panel && ! painted.panel ) {
			missing.push( 'panel' );
		}
		if ( missing.length > 0 ) {
			gaps.push( { page: expectation.page, id: expectation.id, missing } );
		}
	}
	return { checked: expectations.length, gaps, unreadable: [ ...unreadable ] };
}

/** One line per gap, in the shape the other gates print. */
export function describeSurfaceCoverage( report: SurfaceCoverageReport ): string[] {
	const lines = report.gaps.map(
		( gap ) => `  \u2717 ${ gap.page } band ${ gap.id } declares ${ gap.missing.join( ' + ' ) } \u2014 the build paints nothing`
	);
	if ( report.unreadable.length > 0 ) {
		lines.push( `  \u2013 not in this build: ${ report.unreadable.join( ', ' ) }` );
	}
	return lines;
}
