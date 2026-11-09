// ---------------------------------------------------------------------------
// region-colors.ts — the colour-system gate: chrome and page surfaces.
//
// Covered elsewhere today: the token block (are the profile's tokens in
// global.css?), band flow (does each band paint its baked background?), the
// contrast audit (do the token pairs pass WCAG?). None of them answer "does the
// BUILT header/footer/body actually render the source's colours?" — which is
// exactly where chrome regressions hide (a themed header that ships with the
// template default is invisible to every existing gate).
//
// Method: read the COMPUTED `background-color` and `color` of each region in a
// real browser, normalise to hex, and compare with CIEDE2000 (Sharma, Wu &
// Dalal 2005 — the perceptual standard; ΔE00 ≈ 1 is a just-noticeable
// difference, 3–6 is clearly visible). Computed styles are machine-stable
// where pixels are not, so the baseline is plain data in the profile, the same
// discipline `geometry` uses.
//
// Deliberate limits:
//  * Region detection is a selector LADDER (header/footer markup differs per
//    builder). A region the build drops is reported; `--strict-colors` makes
//    it a failure. Absence is never silently a pass.
//  * Transparent surfaces are SKIPPED, not compared. `transparent` over a white
//    body is visually white, but the gate cannot see the backdrop — guessing
//    would manufacture both false passes and false failures.
//  * Only background + text colour per region. Gradients, images and borders
//    stay with band flow / pixel parity.
// ---------------------------------------------------------------------------

import { hexToRgb, rgbToHex } from './color.ts';
import type { ForgeColorModes, RegionColors } from './types.ts';

/** The colour schemes measured separately — a dark header in light mode and a
 * themed `prefers-color-scheme: dark` are different bugs, and one measurement
 * cannot see both. */
export const COLOR_MODES = [ 'light', 'dark' ] as const;
export type ColorMode = ( typeof COLOR_MODES )[ number ];

export interface ColorRegion {
	name: string;
	/** First selector that matches wins — the builder-agnostic ladder. */
	selectors: string[];
}

/**
 * The regions the colour system is judged on. `body` is the page surface;
 * header/footer are the chrome the template themes separately (chrome.headerBg,
 * chrome.footerBg) and the one place a "themed but not applied" bug shows.
 */
export const DEFAULT_COLOR_REGIONS: ColorRegion[] = [
	{ name: 'body', selectors: [ 'body' ] },
	{
		name: 'header',
		selectors: [
			'header',
			'[role="banner"]',
			'.site-header',
			'#masthead',
			'.elementor-location-header',
			// Brando/WPBakery emit the header as `<nav class="navbar …">` with no
			// `<header>` element at all (virtuallyjamaica, 2026-11-09).
			'nav.navbar',
			'.navbar',
			'.header',
		],
	},
	{
		name: 'footer',
		selectors: [
			'footer',
			'[role="contentinfo"]',
			'.site-footer',
			'#colophon',
			'.elementor-location-footer',
			'.footer',
		],
	},
];

export interface ParsedColor {
	r: number;
	g: number;
	b: number;
	a: number;
}

const TRANSPARENT = 'transparent';

/** Parse the computed-style colour forms a browser emits. */
export function parseCssColor( input: string ): ParsedColor | undefined {
	const value = input.trim().toLowerCase();
	if ( value === '' || value === TRANSPARENT ) {
		return { r: 0, g: 0, b: 0, a: 0 };
	}
	if ( value.startsWith( '#' ) ) {
		const hex = value.slice( 1 );
		const full =
			hex.length === 3 || hex.length === 4
				? hex
						.split( '' )
						.map( ( char ) => char + char )
						.join( '' )
				: hex;
		if ( full.length !== 6 && full.length !== 8 ) {
			return undefined;
		}
		const [ r, g, b ] = hexToRgb( `#${ full.slice( 0, 6 ) }` );
		const a = full.length === 8 ? parseInt( full.slice( 6, 8 ), 16 ) / 255 : 1;
		return { r, g, b, a };
	}
	const match = /^rgba?\(\s*([^)]+)\)$/.exec( value );
	if ( ! match ) {
		return undefined;
	}
	const parts = match[ 1 ].split( /[\s,/]+/ ).filter( Boolean );
	if ( parts.length < 3 ) {
		return undefined;
	}
	const channel = ( part: string ): number => {
		const number = parseFloat( part );
		if ( Number.isNaN( number ) ) {
			return 0;
		}
		return part.includes( '%' ) ? Math.round( ( number / 100 ) * 255 ) : number;
	};
	const alpha = parts[ 3 ] === undefined ? 1 : parts[ 3 ].includes( '%' ) ? parseFloat( parts[ 3 ] ) / 100 : parseFloat( parts[ 3 ] );
	return {
		r: channel( parts[ 0 ] ),
		g: channel( parts[ 1 ] ),
		b: channel( parts[ 2 ] ),
		a: Number.isNaN( alpha ) ? 1 : alpha,
	};
}

/**
 * Canonical surface form: `transparent` below 50% alpha, otherwise `#rrggbb`.
 * Alpha is dropped above the threshold — a 90%-opaque white over an unknown
 * backdrop cannot be compared faithfully, and its nearest honest reading is
 * the surface colour it lets through.
 */
export function normalizeCssColor( input: string ): string {
	const parsed = parseCssColor( input );
	if ( ! parsed || parsed.a < 0.5 ) {
		return TRANSPARENT;
	}
	return rgbToHex( parsed.r, parsed.g, parsed.b );
}

/** sRGB → CIE Lab (D65 white point), the space CIEDE2000 works in. */
export function srgbToLab( r: number, g: number, b: number ): [ number, number, number ] {
	const linear = ( channel: number ): number => {
		const value = channel / 255;
		return value <= 0.04045 ? value / 12.92 : ( ( value + 0.055 ) / 1.055 ) ** 2.4;
	};
	const lr = linear( r );
	const lg = linear( g );
	const lb = linear( b );
	const x = ( lr * 0.4124564 + lg * 0.3575761 + lb * 0.1804375 ) / 0.95047;
	const y = lr * 0.2126729 + lg * 0.7151522 + lb * 0.072175;
	const z = ( lr * 0.0193339 + lg * 0.119192 + lb * 0.9503041 ) / 1.08883;
	const f = ( value: number ): number =>
		value > 0.008856 ? Math.cbrt( value ) : 7.787 * value + 16 / 116;
	const fx = f( x );
	const fy = f( y );
	const fz = f( z );
	return [ 116 * fy - 16, 500 * ( fx - fy ), 200 * ( fy - fz ) ];
}

const DEG = Math.PI / 180;

/**
 * CIEDE2000 colour difference (Sharma, Wu & Dalal, "The CIEDE2000 Color-
 * Difference Formula: Implementation Notes", 2005). ΔE00 ≈ 1 is the
 * just-noticeable difference; ≈ 5 is clearly visible.
 */
export function deltaE2000(
	lab1: [ number, number, number ],
	lab2: [ number, number, number ]
): number {
	const [ l1, a1, b1 ] = lab1;
	const [ l2, a2, b2 ] = lab2;
	const kL = 1;
	const kC = 1;
	const kH = 1;
	const c1 = Math.hypot( a1, b1 );
	const c2 = Math.hypot( a2, b2 );
	const cBar = ( c1 + c2 ) / 2;
	const g = 0.5 * ( 1 - Math.sqrt( cBar ** 7 / ( cBar ** 7 + 25 ** 7 ) ) );
	const a1p = ( 1 + g ) * a1;
	const a2p = ( 1 + g ) * a2;
	const c1p = Math.hypot( a1p, b1 );
	const c2p = Math.hypot( a2p, b2 );
	const h1p = Math.atan2( b1, a1p ) / DEG + ( Math.atan2( b1, a1p ) < 0 ? 360 : 0 );
	const h2p = Math.atan2( b2, a2p ) / DEG + ( Math.atan2( b2, a2p ) < 0 ? 360 : 0 );
	const deltaLp = l2 - l1;
	const deltaCp = c2p - c1p;
	let deltahp: number;
	if ( c1p * c2p === 0 ) {
		deltahp = 0;
	} else {
		deltahp = h2p - h1p;
		if ( deltahp > 180 ) {
			deltahp -= 360;
		} else if ( deltahp < -180 ) {
			deltahp += 360;
		}
	}
	const deltaHp = 2 * Math.sqrt( c1p * c2p ) * Math.sin( ( deltahp * DEG ) / 2 );
	const lBarP = ( l1 + l2 ) / 2;
	const cBarP = ( c1p + c2p ) / 2;
	let hBarP: number;
	if ( c1p * c2p === 0 ) {
		hBarP = h1p + h2p;
	} else if ( Math.abs( h1p - h2p ) > 180 ) {
		hBarP = ( h1p + h2p + 360 ) / 2;
	} else {
		hBarP = ( h1p + h2p ) / 2;
	}
	const t =
		1 -
		0.17 * Math.cos( ( hBarP - 30 ) * DEG ) +
		0.24 * Math.cos( 2 * hBarP * DEG ) +
		0.32 * Math.cos( ( 3 * hBarP + 6 ) * DEG ) -
		0.2 * Math.cos( ( 4 * hBarP - 63 ) * DEG );
	const deltaTheta = 30 * Math.exp( -( ( ( hBarP - 275 ) / 25 ) ** 2 ) );
	const rC = 2 * Math.sqrt( cBarP ** 7 / ( cBarP ** 7 + 25 ** 7 ) );
	const sL = 1 + ( 0.015 * ( lBarP - 50 ) ** 2 ) / Math.sqrt( 20 + ( lBarP - 50 ) ** 2 );
	const sC = 1 + 0.045 * cBarP;
	const sH = 1 + 0.015 * cBarP * t;
	const rT = -Math.sin( 2 * deltaTheta * DEG ) * rC;
	const lTerm = deltaLp / ( kL * sL );
	const cTerm = deltaCp / ( kC * sC );
	const hTerm = deltaHp / ( kH * sH );
	return Math.sqrt( lTerm ** 2 + cTerm ** 2 + hTerm ** 2 + rT * cTerm * hTerm );
}

/** ΔE00 between two CSS colours (`transparent` → undefined: not comparable). */
export function colorDeltaE( a: string, b: string ): number | undefined {
	const first = parseCssColor( a );
	const second = parseCssColor( b );
	if ( ! first || ! second || first.a < 0.5 || second.a < 0.5 ) {
		return undefined;
	}
	return deltaE2000(
		srgbToLab( first.r, first.g, first.b ),
		srgbToLab( second.r, second.g, second.b )
	);
}

export interface RegionColorOptions {
	/** ΔE00 at/above this warns (default 3 — clearly visible). */
	warnAt?: number;
	/** ΔE00 at/above this fails (default 6 — a different colour). */
	failAt?: number;
}

export interface RegionColorDeviation {
	region: string;
	mode: string;
	metric: 'background' | 'text' | 'region';
	expected: string | null;
	actual: string | null;
	deltaE: number | null;
	severity: 'warn' | 'fail';
}

export interface RegionColorReport {
	deviations: RegionColorDeviation[];
	missing: string[];
	skipped: string[];
	/** Schemes the SOURCE does not implement (nothing to compare against). */
	unimplemented: string[];
	compared: number;
}

/**
 * Accept both the current shape (mode → region → colours) and the earlier flat
 * shape (region → colours), so a profile recorded before modes existed still
 * verifies — it is treated as light-only.
 */
export function normalizeColorModes( value: unknown ): ForgeColorModes {
	if ( ! value || typeof value !== 'object' ) {
		return {};
	}
	const record = value as Record< string, unknown >;
	const looksFlat = Object.values( record ).some(
		( entry ) => entry && typeof entry === 'object' && 'background' in ( entry as object )
	);
	if ( looksFlat ) {
		return { light: record as Record< string, RegionColors > };
	}
	return record as ForgeColorModes;
}

/** Diff the measured build colours against the recorded source baseline. */
export function compareRegionColors(
	source: Record< string, RegionColors >,
	built: Record< string, RegionColors >,
	options: RegionColorOptions = {},
	mode: string = 'light'
): RegionColorReport {
	const warnAt = options.warnAt ?? 3;
	const failAt = options.failAt ?? 6;
	const deviations: RegionColorDeviation[] = [];
	const missing: string[] = [];
	const skipped: string[] = [];
	let compared = 0;
	for ( const [ region, expected ] of Object.entries( source ) ) {
		const actual = built[ region ];
		if ( ! actual ) {
			missing.push( region );
			continue;
		}
		for ( const metric of [ 'background', 'text' ] as const ) {
			const from = expected[ metric ];
			const to = actual[ metric ];
			const delta = colorDeltaE( from, to );
			if ( delta === undefined ) {
				skipped.push( `${ mode }.${ region }.${ metric }` );
				continue;
			}
			compared++;
			if ( delta < warnAt ) {
				continue;
			}
			deviations.push( {
				region,
				mode,
				metric,
				expected: from,
				actual: to,
				deltaE: Math.round( delta * 100 ) / 100,
				severity: delta >= failAt ? 'fail' : 'warn',
			} );
		}
	}
	return { deviations, missing, skipped, unimplemented: [], compared };
}

/**
 * Does the source actually define colours for this scheme?
 *
 * A site with no `prefers-color-scheme` support measures identically in light
 * and dark. The build may add a dark theme (the forge's `auto` policy does) —
 * that is an ENHANCEMENT, not a parity regression, and there is nothing in the
 * source to compare it against. Gating it would make 100% unreachable for
 * every source without dark mode.
 */
export function implementsScheme( modes: ForgeColorModes, mode: string ): boolean {
	const normalized = normalizeColorModes( modes );
	const light = normalized.light;
	const other = normalized[ mode ];
	if ( ! other ) {
		return false;
	}
	if ( ! light || mode === 'light' ) {
		return true;
	}
	const regions = new Set( [ ...Object.keys( light ), ...Object.keys( other ) ] );
	for ( const region of regions ) {
		const a = light[ region ];
		const b = other[ region ];
		if ( ! a || ! b ) {
			return true;
		}
		if ( a.background !== b.background || a.text !== b.text ) {
			return true;
		}
	}
	return false;
}

/**
 * Compare every measured mode. A mode present in the baseline but absent from
 * the build is reported — that is how "the build dropped dark mode" surfaces.
 */
export function compareRegionColorModes(
	source: ForgeColorModes,
	built: ForgeColorModes,
	options: RegionColorOptions = {}
): RegionColorReport {
	const combined: RegionColorReport = { deviations: [], missing: [], skipped: [], unimplemented: [], compared: 0 };
	for ( const [ mode, sourceRegions ] of Object.entries( normalizeColorModes( source ) ) ) {
		if ( ! implementsScheme( source, mode ) ) {
			combined.unimplemented.push( mode );
			continue;
		}
		const builtRegions = built[ mode ];
		if ( ! builtRegions ) {
			combined.missing.push( `${ mode } (scheme)` );
			continue;
		}
		const report = compareRegionColors( sourceRegions, builtRegions, options, mode );
		combined.deviations.push( ...report.deviations );
		combined.missing.push( ...report.missing.map( ( region ) => `${ mode }.${ region }` ) );
		combined.skipped.push( ...report.skipped );
		combined.compared += report.compared;
	}
	return combined;
}

// -- browser measurement ------------------------------------------------------

type PlaywrightChromium = typeof import('playwright').chromium;

async function loadChromium(): Promise< PlaywrightChromium > {
	try {
		const playwright = await import('playwright');
		return playwright.chromium;
	} catch {
		throw new Error(
			'Colour measurement needs Playwright. Install it in the forge (npm i -D playwright).'
		);
	}
}

export interface MeasureColorOptions {
	url: string;
	regions?: ColorRegion[];
	/** Colour schemes to measure (default: light and dark). */
	modes?: string[];
	viewport?: { width: number; height: number };
	timeout?: number;
	/**
	 * Resolve the COMPOSITED background instead of the element's own one: when
	 * a region is transparent, walk its ancestors to the first opaque
	 * background. A transparent header sitting on a coloured hero is that
	 * colour to the eye, and band surfaces are usually painted by a wrapper —
	 * reading only `backgroundColor` reports `transparent` and the gate skips
	 * the very surface that is wrong.
	 */
	effective?: boolean;
}

/**
 * Read the computed surface/text colour of each region, PER COLOUR SCHEME.
 * Each mode gets its own browser context, so `prefers-color-scheme` is a real
 * media query — the only way to see a dark header or a themed dark mode.
 * Regions whose ladder matches nothing are omitted — the caller reports them
 * (absence is data, not a silent pass).
 */
export async function measureRegionColors(
	options: MeasureColorOptions
): Promise< ForgeColorModes > {
	const chromium = await loadChromium();
	const viewport = options.viewport ?? { width: 1440, height: 900 };
	const modes = options.modes && options.modes.length > 0 ? options.modes : [ ...COLOR_MODES ];
	const regions = options.regions ?? DEFAULT_COLOR_REGIONS;
	const browser = await chromium.launch( { args: [ '--force-device-scale-factor=1' ] } );
	try {
		const measured: ForgeColorModes = {};
		for ( const mode of modes ) {
			const context = await browser.newContext( {
				viewport,
				deviceScaleFactor: 1,
				colorScheme: mode === 'dark' ? 'dark' : 'light',
				reducedMotion: 'reduce',
				locale: 'en-US',
				timezoneId: 'UTC',
			} );
			try {
				const page = await context.newPage();
				await page.goto( options.url, { waitUntil: 'load', timeout: options.timeout ?? 90000 } );
				const colors = await page.evaluate(
					( args ) => {
						const isOpaque = ( value: string ): boolean => {
							if ( ! value || value === 'transparent' ) {
								return false;
							}
							const alpha = /rgba?\([^)]*,\s*([0-9.]+)\s*\)/.exec( value );
							return ! alpha || Number( alpha[ 1 ] ) >= 0.5;
						};
						const readBackground = ( element: Element, effective: boolean ): string => {
							if ( ! effective ) {
								return getComputedStyle( element ).backgroundColor;
							}
							let node: Element | null = element;
							while ( node ) {
								const background = getComputedStyle( node ).backgroundColor;
								if ( isOpaque( background ) ) {
									return background;
								}
								node = node.parentElement;
							}
							return 'transparent';
						};
						const out: Record< string, { background: string; text: string } > = {};
						for ( const region of args.regions ) {
							for ( const selector of region.selectors ) {
								const element = document.querySelector( selector );
								if ( element ) {
									out[ region.name ] = {
										background: readBackground( element, args.effective ),
										text: getComputedStyle( element ).color,
									};
									break;
								}
							}
						}
						return out;
					},
					{ regions, effective: options.effective === true }
				);
				const baseline: Record< string, RegionColors > = {};
				for ( const [ region, values ] of Object.entries( colors ) ) {
					baseline[ region ] = {
						background: normalizeCssColor( values.background ),
						text: normalizeCssColor( values.text ),
					};
				}
				measured[ mode ] = baseline;
			} finally {
				await context.close();
			}
		}
		return measured;
	} finally {
		await browser.close();
	}
}

/** Convenience for callers building a profile draft. */
export function regionColors( background: string, text: string ): RegionColors {
	return { background: normalizeCssColor( background ), text: normalizeCssColor( text ) };
}
