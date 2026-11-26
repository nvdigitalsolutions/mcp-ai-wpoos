// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// box-parity.ts — the per-band BOX gate.
//
// A rebuilt band can match every colour and every word and still feel wrong: the
// copy starts 60px high, the row runs 100px wide, the picture is 124px too tall.
// None of that is visible to a colour or text diff — they are BOX facts, and the
// only honest source is the browser on both sides.
//
// The probe measures each band's INK BOX (the union of its VISIBLE text rects)
// plus the band's height. Text is the right proxy for "padding": a builder puts
// the inset on an inner container (`e-con-inner { padding: 60px 0 80px }`) and
// not on the band, so the band's own computed padding is the wrong number, while
// where the words actually start is exactly what a reader perceives.
//
// Two measurement rules, both from the layout-regression practice:
//   - `getBoundingClientRect` is FRACTIONAL and excludes margin, so edges are
//     compared as measured and only rounded for display;
//   - a 2px tolerance is the default: subpixel layout makes an exact 0 noise,
//     while a real drift (a missing 60px inset) is an order of magnitude larger.
//
// Matching is by BAND id: the source's `.elementor-element-<id>` against the
// build's `[data-section-id="<id>"]`.
// ---------------------------------------------------------------------------

import { builtStaticRoot, loadChromium, serveDir } from './geometry.ts';
import { gateContextOptions, openGate } from './gate.ts';
import type { SiteProfile } from './types.ts';

/** One band's measured box, relative to the band's own top-left. */
export interface BandInkBox {
	height: number;
	/** Distance from the band's edge to the text. */
	left: number;
	right: number;
	top: number;
	/** The text block's own width. */
	width: number;
	/** The band's computed side padding, reported (never diffed). */
	padX: [ number, number ];
}

export type InkBoxEdge = 'left' | 'right' | 'top' | 'width' | 'height';

export interface InkBoxDeviation {
	id: string;
	edge: InkBoxEdge;
	source: number;
	built: number;
	/** `built - source`, in px (positive = the build is further in / wider). */
	delta: number;
}

export interface InkBoxPageReport {
	slug: string;
	sourceUrl: string;
	builtUrl: string;
	/** Bands the page lists. */
	bands: number;
	/** Bands measured with visible text on both sides. */
	compared: number;
	/** Bands the build does not render at all. */
	missing: string[];
	/** Photo-only bands (no visible text) — reported, never diffed. */
	textless: string[];
	deviations: InkBoxDeviation[];
	error?: string;
}

export interface InkBoxOptions {
	/** Restrict to these page slugs (default: every page the profile measured). */
	slugs?: string[];
	/** Allowed per-edge difference, px (default 2 — subpixel layout is not a fact). */
	tolerance?: number;
	target: string;
	/** Progress callback, one per page. */
	onPage?: ( report: InkBoxPageReport ) => void;
}

export const DEFAULT_TOLERANCE = 2;

/** The browser-side probe. Self-contained: it is serialised into the page. */
function collectInkBoxes( input: { ids: string[] } ): Record< string, BandInkBox | null > {
	const out: Record< string, BandInkBox | null > = {};
	const isVisible = ( element: Element ): boolean => {
		for ( let node: Element | null = element; node; node = node.parentElement ) {
			const style = getComputedStyle( node );
			if ( style.display === 'none' || style.visibility === 'hidden' || Number( style.opacity ) === 0 ) {
				return false;
			}
			if ( node.classList.contains( 'elementor-screen-only' ) || node.classList.contains( 'visually-hidden' ) ) {
				return false;
			}
		}
		return true;
	};
	const inkOf = ( root: Element, band: DOMRect ) => {
		const rects: DOMRect[] = [];
		const walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT );
		let node: Node | null;
		while ( ( node = walker.nextNode() ) ) {
			const text = node.textContent;
			if ( ! text || text.trim() === '' ) {
				continue;
			}
			const parent = ( node as Text ).parentElement;
			if ( ! parent || ! isVisible( parent ) ) {
				continue;
			}
			const range = document.createRange();
			range.selectNodeContents( node );
			for ( const rect of range.getClientRects() ) {
				// Off-canvas builder leftovers (hidden slides, marquees) produce rects
				// nowhere near the band; they are not the band's copy.
				if ( rect.width <= 1 || rect.height <= 1 ) {
					continue;
				}
				if ( rect.bottom < band.top - 4 || rect.top > band.bottom + 4 ) {
					continue;
				}
				rects.push( rect );
			}
		}
		if ( rects.length === 0 ) {
			return undefined;
		}
		let left = Infinity;
		let top = Infinity;
		let right = -Infinity;
		for ( const rect of rects ) {
			left = Math.min( left, rect.left );
			top = Math.min( top, rect.top );
			right = Math.max( right, rect.right );
		}
		return {
			left: Math.round( left - band.left ),
			right: Math.round( band.right - right ),
			top: Math.round( top - band.top ),
			width: Math.round( right - left ),
		};
	};
	for ( const id of input.ids ) {
		const band =
			document.querySelector( `[data-section-id="${ id }"]` ) ??
			document.querySelector( `.elementor-element-${ id }` );
		if ( ! band ) {
			out[ id ] = null;
			continue;
		}
		const box = band.getBoundingClientRect();
		if ( box.width <= 0 ) {
			out[ id ] = null;
			continue;
		}
		const style = getComputedStyle( band );
		const padX: [ number, number ] = [
			Math.round( Number.parseFloat( style.paddingLeft ) || 0 ),
			Math.round( Number.parseFloat( style.paddingRight ) || 0 ),
		];
		const ink = inkOf( band, box );
		// `width: -1` marks a TEXTLESS band: there is no ink to diff, and scoring it
		// as 0 would report a phantom deviation on every edge.
		out[ id ] = ink
			? { height: Math.round( box.height ), padX, ...ink }
			: { height: Math.round( box.height ), left: -1, right: -1, top: -1, width: -1, padX };
	}
	return out;
}

/** Measure one side (the source or the built site) at the desktop viewport. */
async function measureSide(
	chromium: Awaited< ReturnType< typeof loadChromium > >,
	url: string,
	ids: string[]
): Promise< Record< string, BandInkBox | null > > {
	const browser = await chromium.launch();
	try {
		const context = await browser.newContext(
			gateContextOptions( { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 } )
		);
		const page = await context.newPage();
		await page.goto( url, { waitUntil: 'load', timeout: 90000 } );
		// A resize forces the source's media queries to settle at the measured width.
		await page.setViewportSize( { width: 1440, height: 901 } );
		await page.setViewportSize( { width: 1440, height: 900 } );
		await page.waitForTimeout( 600 );
		return await page.evaluate( collectInkBoxes, { ids } );
	} finally {
		await browser.close();
	}
}

/** The per-edge comparison. Pure: the gate and the CLI both use it. */
export function diffInkBoxes(
	source: Record< string, BandInkBox | null >,
	built: Record< string, BandInkBox | null >,
	tolerance = DEFAULT_TOLERANCE
): { deviations: InkBoxDeviation[]; compared: number; missing: string[]; textless: string[] } {
	const deviations: InkBoxDeviation[] = [];
	const missing: string[] = [];
	const textless: string[] = [];
	let compared = 0;
	for ( const [ id, a ] of Object.entries( source ) ) {
		const b = built[ id ];
		if ( ! b ) {
			missing.push( id );
			continue;
		}
		if ( ! a ) {
			continue;
		}
		if ( a.width < 0 || b.width < 0 ) {
			textless.push( id );
			continue;
		}
		compared += 1;
		for ( const edge of [ 'left', 'right', 'top', 'width', 'height' ] as const ) {
			const delta = b[ edge ] - a[ edge ];
			if ( Math.abs( delta ) > tolerance ) {
				deviations.push( { id, edge, source: a[ edge ], built: b[ edge ], delta } );
			}
		}
	}
	return { deviations, compared, missing, textless };
}

/** Join a served base URL and a page path without doubling or dropping a slash. */
function pageUrl( base: string, path: string ): string {
	return `${ base.replace( /\/+$/, '' ) }/${ path.replace( /^\/+/, '' ) }`;
}

/** The page targets to compare: the home page plus the profile's measured pages. */
function pageTargets(
	profile: SiteProfile,
	slugs: string[] | undefined
): Array< { slug: string; path: string; sourceUrl: string } > {
	const pages = profile.pageBands ?? {};
	const wanted = slugs && slugs.length > 0 ? slugs : Object.keys( pages );
	const out: Array< { slug: string; path: string; sourceUrl: string } > = [];
	if ( wanted.length === 0 || wanted.includes( 'home' ) ) {
		out.push( { slug: 'home', path: '/', sourceUrl: profile.url } );
	}
	for ( const slug of wanted ) {
		if ( slug === 'home' ) {
			continue;
		}
		const entry = pages[ slug ];
		if ( ! entry ) {
			continue;
		}
		const sourceUrl =
			typeof entry.url === 'string' ? entry.url : `${ profile.url.replace( /\/+$/, '' ) }/${ slug }/`;
		out.push( { slug, path: new URL( sourceUrl ).pathname, sourceUrl } );
	}
	return out;
}

/**
 * Run the box gate over a profile's pages.
 *
 * The source is measured through the site's own gate (a maintenance-mode page has
 * no bands to measure); the build is served from `<target>/dist`. Never throws per
 * page: a page that fails is reported with `error` and the rest still run.
 */
export async function runBoxGate( profile: SiteProfile, options: InkBoxOptions ): Promise< InkBoxPageReport[] > {
	const tolerance = options.tolerance ?? DEFAULT_TOLERANCE;
	const chromium = await loadChromium();
	const gate = await openGate( profile, {} );
	const reports: InkBoxPageReport[] = [];
	const served = await serveDir( await builtStaticRoot( `${ options.target.replace( /[\\/]+$/, '' ) }/dist` ) );
	try {
		for ( const target of pageTargets( profile, options.slugs ) ) {
			const entry = profile.pageBands?.[ target.slug ];
			const ids = ( target.slug === 'home' ? ( profile.design?.sections ?? [] ) : ( entry?.sections ?? [] ) )
				.map( ( section ) => section.id )
				.filter( ( id ): id is string => Boolean( id ) );
			const base: InkBoxPageReport = {
				slug: target.slug,
				sourceUrl: target.sourceUrl,
				builtUrl: pageUrl( served.url, target.path ),
				bands: ids.length,
				compared: 0,
				missing: [],
				textless: [],
				deviations: [],
			};
			if ( ids.length === 0 ) {
				reports.push( base );
				options.onPage?.( base );
				continue;
			}
			try {
				const source = await measureSide( chromium, target.sourceUrl, ids );
				const built = await measureSide( chromium, pageUrl( served.url, target.path ), ids );
				const report: InkBoxPageReport = { ...base, ...diffInkBoxes( source, built, tolerance ) };
				reports.push( report );
				options.onPage?.( report );
			} catch ( error ) {
				const report: InkBoxPageReport = {
					...base,
					error: error instanceof Error ? error.message : String( error ),
				};
				reports.push( report );
				options.onPage?.( report );
			}
		}
	} finally {
		served.server.close();
		gate.close();
	}
	return reports;
}

/** Total deviations across a run (the gate's exit signal). */
export function boxGateFailures( reports: InkBoxPageReport[] ): number {
	return reports.reduce( ( total, report ) => total + report.deviations.length + ( report.error ? 1 : 0 ), 0 );
}
