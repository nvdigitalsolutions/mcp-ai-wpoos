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
//  * Background colour + text colour per region, plus the painted background
//    IMAGE of a BAND (a gradient, or a photo URL) — a builder paints both on a
//    child layer and the sanitizer drops the `<style>` that declared them, so
//    the browser is the only source. Borders stay with pixel parity.
//  * A band's photo `url()` is CAPTURED but not yet WIRED (`ForgeSection.bgImage`
//    makes the band resolve as `hero`, which is wrong for a content band).
//    Gradients are wired; the URL is kept for the future photo-surface pass.
// ---------------------------------------------------------------------------

import { hexToRgb, rgbToHex } from './color.ts';
import type { ForgeColorModes, ForgeSection, RegionColors } from './types.ts';

/** The colour schemes measured separately — a dark header in light mode and a
 * themed `prefers-color-scheme: dark` are different bugs, and one measurement
 * cannot see both. */
export const COLOR_MODES = [ 'light', 'dark' ] as const;
export type ColorMode = ( typeof COLOR_MODES )[ number ];

export interface ColorRegion {
	name: string;
	/** First selector that matches wins — the builder-agnostic ladder. */
	selectors: string[];
	/**
	 * Resolve the surface from the region's OWN fill layer as well as its
	 * ancestors. A builder paints a band's background on a child (`.section-bg`,
	 * `.banner`), never on the band element, so the ancestor walk reads the page
	 * body and every band looks transparent (Flatsome, 2026-11-11). Only set this
	 * for BANDS — a header's child topbar would otherwise lend it the topbar's
	 * colour.
	 */
	descendantFill?: boolean;
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

/** Canonical form of a CSS VALUE (a gradient, a list) for equality: whitespace,
 * quote style and case carry no meaning and differ between the browser's
 * computed serialisation and the style attribute we re-emit. */
export function normalizeCssValue( input: string ): string {
	return input
		.replace( /"+/g, "'" )
		.replace( /\s*([(),\/])\s*/g, '$1' )
		.replace( /\s+/g, ' ' )
		.trim()
		.toLowerCase();
}

/** A gradient painted as the surface — `linear`, `radial` and `conic`, including
 * the `repeating-` forms. Photos (`url(…)`) are surfaced by `RegionColors.image`
 * too, but they are deliberately NOT classified as gradients: wiring a photo
 * into `ForgeSection.bgImage` makes `resolveSectionTheme` return `hero`, which is
 * wrong for a content band. */
export const GRADIENT_SURFACE_RE = /(?:repeating-)?(?:linear|radial|conic)-gradient\(/i;

/**
 * Copy a measured BAND surface onto the section facts.
 *
 * A builder paints a band's surface on a child layer and declares it in a
 * `<style>` block the sanitizer drops, so the browser measurement is the only
 * exact source. Gradients go to the existing `bgGradient` field (the renderer
 * emits it as the `background` shorthand; `resolveSectionTheme` classifies from
 * its last stop). Flat fills go to `bgColor`, unless the composited value is
 * the page body's own — that is "transparent", not a band fact.
 *
 * `pinned` facts win: an existing `bgColor`/`bgGradient`/`bgImage` is never
 * overwritten, so an operator pin survives a re-analysis.
 */
export function applyBandSurfaces(
	sections: ForgeSection[],
	lookup: ( section: ForgeSection ) => RegionColors | undefined,
	bodyBackground?: string
): void {
	for ( const section of sections ) {
		const surface = lookup( section );
		if ( ! surface ) {
			continue;
		}
		if ( surface.image && ! section.bgGradient && ! section.bgImage && GRADIENT_SURFACE_RE.test( surface.image ) ) {
			section.bgGradient = surface.image;
		}
		const background = surface.background;
		if (
			background &&
			background !== TRANSPARENT &&
			background !== bodyBackground &&
			! section.bgColor &&
			! section.bgGradient
		) {
			section.bgColor = background;
		}
	}
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
	metric: 'background' | 'text' | 'region' | 'image';
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
		// The painted IMAGE is invisible to a colour comparison: a gradient band
		// rebuilt flat keeps the same `backgroundColor`. Compare only GRADIENTS
		// — the media localizer rewrites a photo URL (`/media/<hash>-slug.jpg`),
		// so a URL difference is not a deviation. A gradient that vanished is a
		// fail; a differing gradient is a warn (serialisations legitimately vary).
		const fromImage = expected.image;
		if ( fromImage && GRADIENT_SURFACE_RE.test( fromImage ) ) {
			compared++;
			const toImage = actual.image;
			if ( ! toImage ) {
				deviations.push( {
					region,
					mode,
					metric: 'image',
					expected: fromImage,
					actual: null,
					deltaE: null,
					severity: 'fail',
				} );
			} else if ( normalizeCssValue( fromImage ) !== normalizeCssValue( toImage ) ) {
				deviations.push( {
					region,
					mode,
					metric: 'image',
					expected: fromImage,
					actual: toImage,
					deltaE: null,
					severity: 'warn',
				} );
			}
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
	 * background. A band surface is often painted by a wrapper, and reading only
	 * `backgroundColor` reports `transparent` for the very surface that is wrong.
	 *
	 * The walk stops at the PAGE CANVAS: an ancestor more than 2× the region's
	 * area, and always `<html>`. Those paint the page backdrop (Flatsome's
	 * `#main`/`#wrapper` are black so transitions never flash), not the
	 * region's own surface — deriving the surface from them reported `#000000`
	 * for a transparent header over a hero and for every transparent band
	 * (monicarajiv.com, 2026-11-12).
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
						// Alpha is the FOURTH colour component — never the blue channel. A
						// `rgb(r, g, b)` triple has no alpha, so reading the last number treated
						// `rgb(0, 0, 0)` and `rgb(255, 255, 0)` as translucent and skipped the
						// surface entirely (black bands read transparent; 2026-11-12).
						const alphaOf = ( value: string ): number => {
							const match = /rgba?\(([^)]+)\)/.exec( value );
							if ( ! match ) {
								return 1;
							}
							const parts = match[ 1 ].split( /[,/]/ ).map( ( part ) => part.trim() ).filter( Boolean );
							return parts.length >= 4 ? Number( parts[ 3 ] ) : 1;
						};
						const isOpaque = ( value: string ): boolean => {
							if ( ! value || value === 'transparent' ) {
								return false;
							}
							return alphaOf( value ) >= 0.5;
						};
						// A gradient is a SURFACE only when every stop is opaque — a low-alpha
						// gradient is a tint over the layer below, exactly like a translucent
						// background colour. A photo `url()` is always a surface. Chromium
						// serialises every colour inside `background-image` to `rgb()`/`rgba()`,
						// so scanning the colours is sufficient (`transparent` included).
						const imageIsSurface = ( image: string ): boolean => {
							if ( ! image || image === 'none' ) {
								return false;
							}
							if ( image.includes( 'url(' ) ) {
								return true;
							}
							const colors = image.match( /rgba?\([^)]+\)/g ) ?? [];
							return colors.every( ( color ) => alphaOf( color ) >= 0.95 );
						};
						const readBackground = (
							element: Element,
							effective: boolean,
							descendantFill: boolean
						): { color: string; image?: string } => {
							const ownStyle = getComputedStyle( element );
							const own = ownStyle.backgroundColor;
							if ( descendantFill ) {
								// The band's OWN surface outranks its children. `.section {
								// background-image: linear-gradient(…) }` has a TRANSPARENT
								// background-colour, so keying the return on the colour alone missed
								// the most common gradient form.
								const ownImage = ownStyle.backgroundImage;
								const ownHasImage = imageIsSurface( ownImage );
								if ( ownHasImage || isOpaque( own ) ) {
									return { color: own, ...( ownHasImage ? { image: ownImage } : {} ) };
								}
							}
							if ( ! effective ) {
								return { color: own };
							}
							if ( descendantFill ) {
								// The band's fill layer: the largest surface descendant box. A
								// builder paints the surface on a child wrapper, so the ancestor
								// walk alone reports the page body for every band. A node
								// qualifies on an opaque fill OR an opaque background-image —
								// `.section { background-image: linear-gradient(…) }` has a
								// transparent background-colour (Flatsome).
								const bandRect = element.getBoundingClientRect();
								const width = bandRect.width;
								const candidates: Array< { bg: string; image?: string; area: number; w: number } > = [];
								for ( const node of element.querySelectorAll( '*' ) ) {
									const style = getComputedStyle( node );
									const background = style.backgroundColor;
									// A FILL is opaque or unpainted. A TRANSLUCENT colour
									// (`rgba(0,0,0,0.5)`) is a tint over the surface, not the
									// surface — taking it painted every photo hero black
									// (monicarajiv.com, 2026-11-11). Alpha 0 is "no colour": the
									// layer may still paint a gradient, so fall through to the
									// image check below.
									const fillAlpha = background === 'transparent' ? 0 : alphaOf( background );
									if ( fillAlpha > 0 && fillAlpha < 0.95 ) {
										continue;
									}
									const backgroundImage = style.backgroundImage;
									const hasImage = imageIsSurface( backgroundImage );
									if ( fillAlpha === 0 && ! hasImage ) {
										continue;
									}
									const rect = node.getBoundingClientRect();
									if ( rect.height < 20 ) {
										continue;
									}
									candidates.push( {
										bg: background,
										...( hasImage ? { image: backgroundImage } : {} ),
										area: rect.width * rect.height,
										w: rect.width,
									} );
								}
								let best: ( typeof candidates )[ number ] | undefined;
								for ( const candidate of candidates ) {
									if ( ! best || candidate.area > best.area ) {
										best = candidate;
									}
								}
								// Two half-width columns paint the band between them without any
								// SINGLE layer reaching 60% of its width (Flatsome's 2-col banner
								// row, monicarajiv.com). Accept the winning colour when same-coloured
								// siblings TILE at least 90% of the band width.
								if ( best && best.w < width * 0.6 ) {
									const tiled = candidates
										.filter( ( candidate ) => candidate.bg === best!.bg && candidate.image === best!.image )
										.reduce( ( sum, candidate ) => sum + candidate.w, 0 );
									if ( tiled < width * 0.9 ) {
										best = undefined;
									}
								}
								if ( best ) {
									return { color: best.bg, ...( best.image ? { image: best.image } : {} ) };
								}
							}
							// A surface must be comparable in size to the region it backs. An
							// ancestor more than twice the region is the PAGE CANVAS
							// (Flatsome paints `#main`/`#wrapper` black so page transitions never
							// flash), not the region's own surface — taking it reported `#000000`
							// for a transparent header whose hero covers it, and for every
							// transparent band (monicarajiv.com, 2026-11-12). A band nested in a
							// coloured wrapper of similar height still resolves correctly.
							const ownRect = element.getBoundingClientRect();
							const ownArea = Math.max( 1, ownRect.width * ownRect.height );
							let node: Element | null = element;
							while ( node ) {
								const style = getComputedStyle( node );
								const background = style.backgroundColor;
								const rect = node.getBoundingClientRect();
								if ( isOpaque( background ) && rect.width * rect.height <= ownArea * 2 ) {
									const backgroundImage = style.backgroundImage;
									return imageIsSurface( backgroundImage )
										? { color: background, image: backgroundImage }
										: { color: background };
								}
								// `<html>`'s background is the VIEWPORT CANVAS (visible on
								// overscroll and before first paint), not a region's surface.
								node = node.parentElement;
								if ( node && node.tagName === 'HTML' ) {
									break;
								}
							}
							return { color: 'transparent' };
						};
						const out: Record< string, { background: string; text: string; image?: string } > = {};
						// The ink a region RENDERS is not the element's inherited `color` on a
						// builder site: the theme sets a global body colour and paints the
						// visible text from a context class on inner containers (Flatsome's
						// `.dark` — monicarajiv.com computes `body { color: #000 }` while every
						// rendered word is white). The most frequent colour among text-bearing
						// descendants is what the region actually shows.
						const readInk = ( element: Element ): string => {
							const counts = new Map< string, number >();
							let seen = 0;
							const consider = ( node: Element ): void => {
								if ( seen > 1200 ) {
									return;
								}
								seen += 1;
								const carriesText = [ ...node.childNodes ].some(
									( child ) => child.nodeType === 3 && ( child.textContent ?? '' ).trim().length > 0
								);
								if ( ! carriesText ) {
									return;
								}
								const rect = node.getBoundingClientRect();
								if ( rect.width < 2 || rect.height < 2 ) {
									return;
								}
								const color = getComputedStyle( node ).color;
								counts.set( color, ( counts.get( color ) ?? 0 ) + 1 );
							};
							consider( element );
							for ( const node of element.querySelectorAll( '*' ) ) {
								consider( node );
							}
							const best = [ ...counts.entries() ].sort( ( a, b ) => b[ 1 ] - a[ 1 ] )[ 0 ];
							// A region with NO text renders no ink — comparing its inherited
							// colour reports a difference between two invisible values (the hero
							// band is an image + a chevron). `transparent` is skipped by the
							// ΔE comparison, so the metric simply does not apply.
							return best ? best[ 0 ] : 'transparent';
						};
						for ( const region of args.regions ) {
							for ( const selector of region.selectors ) {
								const element = document.querySelector( selector );
								if ( element ) {
									const surface = readBackground(
										element,
										args.effective,
										region.descendantFill === true
									);
									out[ region.name ] = {
										background: surface.color,
										text: readInk( element ),
										...( surface.image ? { image: surface.image } : {} ),
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
						// A gradient/URL is not a colour — pass it through verbatim rather
						// than letting `normalizeCssColor` collapse it to `transparent`.
						...( values.image ? { image: values.image } : {} ),
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
