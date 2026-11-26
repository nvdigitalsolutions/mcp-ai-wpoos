// SPDX-License-Identifier: MIT
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
//  * INK is a PALETTE, not one colour (2026-11-12). Each text-bearing element
//    contributes `chars x font-size^2` of rendered glyph area to its colour, and
//    every colour carrying GATED_INK_SHARE of that mass is compared. Counting
//    ELEMENTS instead let a lone caption outvote a paragraph, and recording
//    only the winner hid a band's minority ink turning the wrong colour on the
//    rebuild (virtuallyjamaica's grey service bullets went white). Text the
//    region does not CONTAIN (a legacy descendant rendered under the next band)
//    is not its ink.
//  * A band's photo `url()` is CAPTURED but not yet WIRED (`ForgeSection.bgImage`
//    makes the band resolve as `hero`, which is wrong for a content band).
//    Gradients are wired; the URL is kept for the future photo-surface pass.
// ---------------------------------------------------------------------------

import { hexToRgb, rgbToHex } from './color.ts';
import { gateContextOptions } from './gate.ts';
import { settle } from './page-settle.ts';
import type { ForgeChrome, ForgeColorModes, ForgeSection, InkShare, RegionColors } from './types.ts';

export type { InkShare } from './types.ts';

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
	 * body and every band looks transparent (Flatsome, 2026-11-11).
	 *
	 * Set this where a CHILD layer really is the region's paint: bands, and the
	 * header/footer ELEMENTS — Elementor's `header.elementor-location-header`
	 * is transparent with its inner container painting the strip
	 * (eca.directory, 2026-11-23). The 50%-of-area floor in `readBackground`
	 * keeps a small child (a topbar, a button, a form field) from lending its
	 * colour to the region.
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
		// Chrome is painted on a CHILD layer as often as on the element itself:
		// Elementor's `header.elementor-location-header` is transparent and its
		// inner `.e-con.e-parent` band paints the strip (eca.directory, 2026-11-23).
		// Without the descendant scan the header measured `transparent`, the
		// colour gate SKIPPED it, and the rebuild themed the header from a
		// stylesheet guess.
		descendantFill: true,
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
		// Same CHILD-layer rule as the header: the footer element is transparent
		// and its inner band paints the bar.
		descendantFill: true,
		selectors: [
			'[role="contentinfo"]',
			'.site-footer',
			'#colophon',
			'.elementor-location-footer',
			// Phlox/Auxin ships `footer#sitefooter.aux-site-footer`.
			'#sitefooter',
			'.aux-site-footer',
			// The bare `footer` TAG must come last. WordPress themes emit
			// `footer.entry-meta` / `footer.post-meta` for a post's own meta row, and
			// with the tag first that empty element won the ladder — jayainc's real
			// `footer#sitefooter` was never measured, so the region read
			// `transparent` with no ink and the gate silently skipped the chrome it
			// exists to protect (2026-11-12).
			'footer:not(.entry-meta):not(.post-meta)',
			'.footer',
			'footer',
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
 * A CSS identifier: what `#<value>` may legally contain.
 */
const CSS_IDENTIFIER_RE = /^-?[_a-zA-Z][_a-zA-Z0-9-]*$/;

/**
 * Every selector that can address a DOM id, best first.
 *
 * Builders mint ids like `6daaaef9`, and a CSS identifier cannot start with a
 * digit — `querySelector('#6daaaef9')` throws a SyntaxError, which aborted the
 * whole colour gate on every forged page whose band ids are hashes
 * (eca.directory, 2026-11-24). An attribute selector is always valid, so `#id`
 * is emitted only when the id is a legal identifier.
 */
export function idSelectors( id: string ): string[] {
	const value = String( id ?? '' );
	if ( value === '' ) {
		return [];
	}
	const quoted = `[id="${ value.replace( /"/g, '\\"' ) }"]`;
	return CSS_IDENTIFIER_RE.test( value ) ? [ `#${ value }`, quoted ] : [ quoted ];
}

/** The single best selector for a DOM id (never an invalid one). */
export function cssIdSelector( id: string ): string {
	return idSelectors( id )[ 0 ] ?? '';
}

/**
 * Every selector a REBUILT band can be addressed by, best first.
 *
 * The scaffold wraps each band in `<div data-section-id="<id>" class="ds-section-wrap">`
 * and deliberately emits no `id` attribute (a band id is a builder hash, not a
 * fragment a visitor should land on). So the marker is the ONLY reliable handle
 * — addressing built bands by `id` measured nothing and reported all seven
 * baselines as "dropped" on eca.directory (2026-11-24). `idSelectors` stays as
 * a fallback for a hand-authored wrapper that does carry one.
 */
export function builtBandSelectors( id: string ): string[] {
	const value = String( id ?? '' );
	if ( value === '' ) {
		return [];
	}
	const marker = `[data-section-id="${ value.replace( /"/g, '\\"' ) }"]`;
	return [ marker, ...idSelectors( value ) ];
}

/**
 * The selector a SOURCE band can be addressed by.
 *
 * A nav anchor (`#about`) is the friendliest handle, but most builder bands
 * have none — an Elementor container is a plain div whose only handle is its
 * `elementor-element-<id>` class. Without it the colour/geometry passes resolve
 * nothing and the harvest's guess ships unopposed (eca.directory: 2 of 9 bands
 * addressable, and the unaddressed ones kept invented backgrounds).
 */
export function bandRegionSelector( section: ForgeSection ): string | undefined {
	const anchor = String( section.anchor ?? '' );
	if ( anchor !== '' ) {
		const selector = cssIdSelector( anchor );
		if ( selector !== '' ) {
			return selector;
		}
	}
	if ( section.vendorId ) {
		return `.elementor-element-${ section.vendorId }`;
	}
	return undefined;
}

/**
 * The colour-region name for a source band. Anchored bands keep `band:<anchor>`
 * so baselines recorded before `vendorId` existed still compare; everything else
 * is keyed by the band id.
 */
export function bandRegionName( section: ForgeSection ): string | undefined {
	if ( ! bandRegionSelector( section ) ) {
		return undefined;
	}
	return section.anchor ? `band:${ section.anchor }` : `band:${ section.id }`;
}

export interface ApplyBandSurfaceOptions {
	/**
	 * Bands whose surface facts are OPERATOR-OWNED: skip them entirely.
	 *
	 * The analysis builds this from `profile.pins` (`sections.<id>.bgColor` …), so
	 * a pin beats a measurement while a harvested guess does not. Without it a
	 * pin could only survive by re-application later — and a caller that runs the
	 * measurement AFTER the pins (the per-page pass) would lose them.
	 */
	preserve?: ( section: ForgeSection ) => boolean;
}

/**
 * Copy a measured BAND surface onto the section facts.
 *
 * A builder paints a band's surface on a child layer and declares it in a
 * `<style>` block the sanitizer drops, so the browser measurement is the only
 * exact source. MEASUREMENT WINS over the stylesheet harvest: the harvest reads
 * declarations, and a declaration can belong to a child layer or a variant the
 * page never renders — eca.directory's header was painted magenta from a
 * `#d30c5c` rule while the browser computed `transparent`, and five content
 * bands carried backgrounds from rules for layers inside them.
 *
 * A band whose composited surface equals the page body paints NOTHING: its
 * harvested `bgColor`/`bgGradient` are cleared rather than kept ("transparent"
 * is a fact, and a colour inherited from the body is not the band's). Operator
 * pins are the exception — see `ApplyBandSurfaceOptions.preserve`.
 *
 * A photo `url()` is captured on the region but never wired to `bgImage`:
 * `resolveSectionTheme` would read it as a hero band.
 */
export function applyBandSurfaces(
	sections: ForgeSection[],
	lookup: ( section: ForgeSection ) => RegionColors | undefined,
	bodyBackground?: string,
	options: ApplyBandSurfaceOptions = {}
): void {
	for ( const section of sections ) {
		if ( options.preserve?.( section ) ) {
			continue;
		}
		const surface = lookup( section );
		// Not measured (no selector, or the region did not resolve) — the harvest
		// stands. Clearing on an absent measurement would erase real facts.
		if ( ! surface ) {
			continue;
		}
		const image = surface.image;
		if ( image && GRADIENT_SURFACE_RE.test( image ) ) {
			section.bgGradient = image;
			delete section.bgColor;
			continue;
		}
		const flat = surface.background;
		if ( ! flat || flat === TRANSPARENT || flat === bodyBackground ) {
			// The band paints nothing of its own — inherit the page surface.
			delete section.bgColor;
			delete section.bgGradient;
		} else {
			section.bgColor = flat;
			delete section.bgGradient;
		}
		// Only a band with NO background image whatsoever loses a harvested one.
		// A photo is left unwired (see above), so it must not be deleted either.
		if ( ! image ) {
			delete section.bgImage;
		}
	}
}

export interface ApplyChromeSurfacesOptions {
		/** Operator-owned chrome facts (`chrome.<field>` pins): skip them. */
		preserve?: ( path: string ) => boolean;
	}

	/**
	 * Copy the MEASURED chrome surface and ink onto the design's chrome facts.
	 *
	 * `analyzeChrome` reads DECLARATIONS out of the stylesheets, and a
	 * declaration can belong to a layer or a variant the page never paints.
	 * eca.directory measured worse on every chrome surface than the browser does:
	 * header and footer bar background `#d30c5c` (a Kadence customizer colour
	 * nothing renders), footer ink = the link accent, and the header pills'
	 * fill = Elementor's translucent hover shade. The browser disagrees on all of
	 * them, so measurement WINS; an operator pin survives through `preserve`
	 * (and `applyDesignPins` re-applies it on the merge).
	 *
	 * A region that could not be measured leaves the harvest alone — absence is
	 * not evidence. A header that honestly paints nothing takes the BODY surface:
	 * the strip shows the page behind it, and painting the harvest's guess over
	 * that is the bug this function exists to stop.
	 *
	 * @return The `chrome.<field>` paths that were applied (for logging).
	 */
export function applyChromeSurfaces(
		chrome: ForgeChrome | undefined,
		measured: ForgeColorModes | undefined,
		options: ApplyChromeSurfacesOptions = {}
	): string[] {
		if ( ! chrome ) {
			return [];
		}
		const light = normalizeColorModes( measured ).light ?? {};
		const body = light.body?.background;
		const applied: string[] = [];
		const write = (
			field: 'headerBg' | 'headerInk' | 'navInk' | 'footerBarBg' | 'footerInk' | 'footerBarInk',
			value: string
		): void => {
			if ( options.preserve?.( `chrome.${ field }` ) ) {
				return;
			}
			// The profile stores colours as `#rrggbb` (the token/contrast passes parse
			// hex only); the browser hands back `rgb()`.
			const normalized = normalizeCssColor( value );
			chrome[ field ] = normalized === TRANSPARENT ? value : normalized;
			applied.push( `chrome.${ field }` );
		};
		const dominantInk = ( region: RegionColors | undefined ): string | undefined => {
			const ink = region?.inks?.[ 0 ]?.color;
			return ink && ink !== TRANSPARENT ? ink : undefined;
		};
		const roleInk = ( region: RegionColors | undefined, role: 'heading' | 'body' ): string | undefined => {
			const ink = region?.roles?.[ role ];
			return ink && ink !== TRANSPARENT ? ink : undefined;
		};

		const header = light.header;
		if ( header ) {
			const surface = header.background;
			if ( surface && surface !== TRANSPARENT ) {
				write( 'headerBg', surface );
			} else if ( body && body !== TRANSPARENT ) {
				write( 'headerBg', body );
			}
			const ink = dominantInk( header );
			if ( ink ) {
				write( 'headerInk', ink );
			}
			// The nav ink is a measured palette entry too. A `--design`-only refresh
			// re-reads the stylesheet and can land on `#000000` while the painted nav
			// is the header's dominant ink (eca.directory: #27104a). When the
			// harvested value is absent from the header's OWN palette, the palette
			// wins.
			const inks: Array< { color: string } > = ( header.inks ?? [] ).map( ( entry ) => ( { color: entry.color } ) );
			const firstInk = inks.length > 0 ? inks[ 0 ].color : undefined;
			const navInk = chrome.navInk;
			if ( navInk && firstInk ) {
				let present = false;
				for ( const entry of inks ) {
					// `colorDeltaE` is undefined when either side does not parse — an
					// unresolved builder var is NOT a palette entry, so it never matches.
					const delta = colorDeltaE( entry.color, String( navInk ) );
					if ( delta !== undefined && delta <= 3 ) {
						present = true;
						break;
					}
				}
				if ( ! present ) {
					write( 'navInk', firstInk );
				}
			}
		}

		const footer = light.footer;
		if ( footer ) {
			const surface = footer.background;
			if ( surface && surface !== TRANSPARENT ) {
				write( 'footerBarBg', surface );
			} else if ( body && body !== TRANSPARENT ) {
				write( 'footerBarBg', body );
			}
			const ink = dominantInk( footer );
			// The HEADING slot. The template paints the rebuilt block titles (H3)
			// from `footerInk` and the list copy from `footerBrand.text.ink`, but a
			// region has ONE palette — and its winner is the ink that covers the most
			// glyph AREA, which on a footer is the longer link lists, never the three
			// titles. eca.directory measured 62% `#747474` link items over 38%
			// `#27104a` block titles, so the dominant write recoloured every title
			// grey; the colour gate then reported BOTH source inks as drift, because
			// the rebuild carried only the one it had painted everywhere
			// (2026-11-24). Roles when measured, the palette winner otherwise.
			const headingInk = roleInk( footer, 'heading' ) ?? ink;
			const bodyInk = roleInk( footer, 'body' ) ?? ink;
			if ( headingInk ) {
				write( 'footerInk', headingInk );
			}
			// The © bar is BODY copy, so it takes the body ink.
			if ( bodyInk ) {
				write( 'footerBarInk', bodyInk );
			}
			// A footer whose headings and copy paint DIFFERENT colours needs both
			// slots filled; that is the only way the rebuild can reproduce a
			// two-ink palette the gate compares ink by ink. On a single-ink footer
			// the second slot would repeat the first — noise on every fleet site
			// — so it is written only when the roles measurably differ.
			if ( headingInk && bodyInk ) {
				const delta = colorDeltaE( headingInk, bodyInk );
				const bodyHex = normalizeCssColor( bodyInk );
				if (
					delta !== undefined &&
					delta > 3 &&
					bodyHex !== TRANSPARENT &&
					! options.preserve?.( 'chrome.footerBrand.text.ink' )
				) {
					const brand = chrome.footerBrand ?? {};
					chrome.footerBrand = { ...brand, text: { ...brand.text, ink: bodyHex } };
					applied.push( 'chrome.footerBrand.text.ink' );
				}
			}
		}
		return applied;
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

/** A raw per-colour text measurement from the browser (see `summarizeInk`). */
export interface InkSample {
	color: string;
	mass: number;
}

/**
 * How much text a single element carries, as RENDERED INK AREA.
 *
 * Glyph area scales with the square of the font size, so `chars × px²`
 * approximates how much of the page a run of text paints. Counting ELEMENTS
 * instead made a region's ink snap to whichever colour happened to sit on the
 * most nodes — a lone white caption beat a 2000-character grey body because it
 * was one `<p>` against one `<p>` (virtuallyjamaica's services band measured
 * `#929292` on a source that is dominated by a white 24px lede, 2026-11-12).
 */
export function inkMass( characters: number, fontPx: number ): number {
	return characters * fontPx * fontPx;
}

/** Near-identical inks are one ink — `rgba(255,255,255,0.7)` is white copy. */
const INK_MERGE_DELTA_E = 2;

/**
 * Collapse per-colour measurements into a region's ink PALETTE.
 *
 * A band's ink is not always one colour: virtuallyjamaica's `#services` is a
 * white lede and heading (74% of its text mass) over four grey `li`s (26%).
 * Recording only the winner hides a real regression when the minority ink is
 * the one that breaks, so the palette is recorded and every ink that carries a
 * meaningful share is gated (see `compareRegionColors`).
 *
 * Colours within ΔE00 2 merge onto the heavier one first, so a translucent copy
 * of the dominant ink never splits the vote. `transparent` samples are dropped
 * — invisible text is not ink. Pure and exported so the metric is testable
 * without a browser.
 */
export function summarizeInk(
	samples: InkSample[],
	options: { limit?: number; minShare?: number } = {}
): InkShare[] {
	const limit = options.limit ?? 4;
	const minShare = options.minShare ?? 0.04;
	const merged: InkSample[] = [];
	for ( const sample of samples ) {
		if ( ! Number.isFinite( sample.mass ) || sample.mass <= 0 ) {
			continue;
		}
		const color = normalizeCssColor( sample.color );
		if ( color === TRANSPARENT ) {
			continue;
		}
		const twin = merged.find( ( entry ) => ( colorDeltaE( entry.color, color ) ?? Infinity ) <= INK_MERGE_DELTA_E );
		if ( twin ) {
			twin.mass += sample.mass;
			continue;
		}
		merged.push( { color, mass: sample.mass } );
	}
	const total = merged.reduce( ( sum, entry ) => sum + entry.mass, 0 );
	if ( total <= 0 ) {
		return [];
	}
	return merged
		.map( ( entry ) => ( { color: entry.color, share: entry.mass / total } ) )
		.sort( ( a, b ) => b.share - a.share )
		.slice( 0, limit )
		.filter( ( entry ) => entry.share >= minShare )
		.map( ( entry ) => ( { color: entry.color, share: Math.round( entry.share * 100 ) / 100 } ) );
}

/**
 * An ink must carry at least this share of a region's text to be GATED. Below
 * it the colour is decoration (a caption, a badge) and comparing it makes the
 * gate fire on typographic drift rather than on colour drift.
 */
export const GATED_INK_SHARE = 0.15;

export interface RegionColorOptions {
	/** ΔE00 at/above this warns (default 3 — clearly visible). */
	warnAt?: number;
	/** ΔE00 at/above this fails (default 6 — a different colour). */
	failAt?: number;
}

/**
 * HOW a region resolved: the selector that matched, the element's box and how
 * much ink was on it.
 *
 * A region that resolves to the WRONG element (or to an empty one) used to be
 * indistinguishable from a region that is honestly transparent: jayainc's
 * `footer` ladder matched WordPress's empty `footer.entry-meta`, so the gate
 * measured nothing and *skipped* — a silent blind spot instead of a finding
 * (2026-11-12). Resolution is reported separately from the colours so the gate
 * can say "this region has no ink" without changing the baseline shape.
 */
export interface RegionResolution {
	region: string;
	/** The first selector in the ladder that matched (absent when none did). */
	selector?: string;
	found: boolean;
	width: number;
	height: number;
	/** Text-bearing elements measured inside the region (0 = no ink at all). */
	inkSamples: number;
}

export interface MeasuredColorModes {
	modes: ForgeColorModes;
	resolutions: Record< string, RegionResolution >;
}

export interface RegionColorDeviation {
	region: string;
	mode: string;
	metric: 'background' | 'text' | 'region' | 'image';
	expected: string | null;
	actual: string | null;
	deltaE: number | null;
	severity: 'warn' | 'fail';
	/** For `text`: the share of the region's text mass the expected ink carries. */
	share?: number;
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
 * Chrome regions whose measurement says NOTHING — the signal a selector ladder
 * that matched the wrong element used to hide.
 *
 * jayainc's `footer` ladder matched WordPress's `footer.entry-meta` (an empty
 * post-meta row) because the bare tag came first: the region was found, had a
 * box, carried no text, read `transparent`, and every metric on it was
 * `skipped`. A gate that says "nothing to compare" is telling you it could not
 * see — not that the page is right.
 *
 * `no-ink` is a warning, never a failure: an icon-only header has no text nodes
 * either, and the operator is the one who can tell the two apart.
 */
export function unresolvedChromeRegions(
	resolutions: Record< string, RegionResolution >,
	names: string[] = [ 'body', 'header', 'footer' ]
): Array< { region: string; reason: 'missing' | 'empty' | 'no-ink' } > {
	const out: Array< { region: string; reason: 'missing' | 'empty' | 'no-ink' } > = [];
	for ( const name of names ) {
		const resolution = resolutions[ name ];
		if ( ! resolution || ! resolution.found ) {
			out.push( { region: name, reason: 'missing' } );
			continue;
		}
		if ( resolution.width < 2 || resolution.height < 2 ) {
			out.push( { region: name, reason: 'empty' } );
			continue;
		}
		if ( resolution.inkSamples === 0 ) {
			out.push( { region: name, reason: 'no-ink' } );
		}
	}
	return out;
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
		{
			const delta = colorDeltaE( expected.background, actual.background );
			if ( delta === undefined ) {
				skipped.push( `${ mode }.${ region }.background` );
			} else {
				compared++;
				if ( delta >= warnAt ) {
					deviations.push( {
						region,
						mode,
						metric: 'background',
						expected: expected.background,
						actual: actual.background,
						deltaE: Math.round( delta * 100 ) / 100,
						severity: delta >= failAt ? 'fail' : 'warn',
					} );
				}
			}
		}
		// INK. A region's text is a PALETTE, not one colour: virtuallyjamaica's
		// `#services` is a white lede + heading (74% of its text mass) over four
		// grey `li`s (26%). Gating only the winner hid the grey list items turning
		// white; gating every colour fired on captions. So every ink carrying a
		// GATED_INK_SHARE of the region's text is matched against the build's
		// palette and compared on its own ΔE — a lost minority ink is a failure,
		// a lost caption is not.
		const expectedInks = expected.inks && expected.inks.length > 0 ? expected.inks : undefined;
		const actualInks = actual.inks && actual.inks.length > 0 ? actual.inks : undefined;
		if ( expectedInks && actualInks ) {
			for ( const ink of expectedInks ) {
				if ( ink.share < GATED_INK_SHARE ) {
					continue;
				}
				let best: { color: string; delta: number } | undefined;
				for ( const candidate of actualInks ) {
					const delta = colorDeltaE( ink.color, candidate.color );
					if ( delta === undefined || ( best && delta >= best.delta ) ) {
						continue;
					}
					best = { color: candidate.color, delta };
				}
				if ( ! best ) {
					skipped.push( `${ mode }.${ region }.text` );
					continue;
				}
				compared++;
				if ( best.delta < warnAt ) {
					continue;
				}
				deviations.push( {
					region,
					mode,
					metric: 'text',
					expected: ink.color,
					actual: best.color,
					deltaE: Math.round( best.delta * 100 ) / 100,
					severity: best.delta >= failAt ? 'fail' : 'warn',
					share: ink.share,
				} );
			}
		} else {
			const delta = colorDeltaE( expected.text, actual.text );
			if ( delta === undefined ) {
				skipped.push( `${ mode }.${ region }.text` );
			} else {
				compared++;
				if ( delta >= warnAt ) {
					deviations.push( {
						region,
						mode,
						metric: 'text',
						expected: expected.text,
						actual: actual.text,
						deltaE: Math.round( delta * 100 ) / 100,
						severity: delta >= failAt ? 'fail' : 'warn',
					} );
				}
			}
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
 /**
  * Measure every region's colours, with the resolution of each region.
  *
  * `modes` is the baseline that ships in the profile; `resolutions` is diagnostics
  * (a selector ladder that matched an EMPTY element looks identical to a region
  * that is honestly transparent until you know what it matched).
  */
 export async function measureRegionColorsDetailed(
 	options: MeasureColorOptions
 ): Promise< MeasuredColorModes > {
 	const chromium = await loadChromium();
 	const viewport = options.viewport ?? { width: 1440, height: 900 };
 	const modes = options.modes && options.modes.length > 0 ? options.modes : [ ...COLOR_MODES ];
 	const regions = options.regions ?? DEFAULT_COLOR_REGIONS;
 	const resolutions: Record< string, RegionResolution > = {};
 	const browser = await chromium.launch( { args: [ '--force-device-scale-factor=1' ] } );
	try {
		const measured: ForgeColorModes = {};
		for ( const mode of modes ) {
			const context = await browser.newContext( gateContextOptions( {
				viewport,
				deviceScaleFactor: 1,
				colorScheme: mode === 'dark' ? 'dark' : 'light',
				reducedMotion: 'reduce',
				locale: 'en-US',
				timezoneId: 'UTC',
			} ) );
			try {
				const page = await context.newPage();
				await page.goto( options.url, { waitUntil: 'load', timeout: options.timeout ?? 90000 } );
				// Fonts/lazy images/animations move boxes AND surfaces. Without this the
				// read raced the builder's own stylesheet: eca.directory's form fields
				// and accent button still spanned the full 1440px band row, and the
				// descendant-fill scan took the 53px button for the band surface.
				await settle( page );
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
						//
						// P5 — a builder paints many bands on a PSEUDO layer: a hero's
						// `::before { inset:0; background: linear-gradient(...) }` overlay, a
						// section's tinted `::after`. Computed styles do not chain through
						// pseudos, so the band read as transparent and the gate fell through to
						// the page canvas. Only POSITIONED pseudos qualify: a static pseudo is
						// in-flow decoration (a divider bar), and calling that the band's
						// surface would compare a 125×6 rule against a full-height band.
						const pseudoSurface = ( element: Element ): { color: string; image?: string } | undefined => {
							for ( const name of [ '::before', '::after' ] ) {
								const style = getComputedStyle( element, name );
								if ( ! style || style.content === 'none' || style.content === 'normal' || style.content === '' ) {
									continue;
								}
								if ( style.position !== 'absolute' && style.position !== 'fixed' ) {
									continue;
								}
								// A pseudo at reduced opacity is a VEIL, not a surface: the band's own
								// paint shows through it. eca.directory's phased-delivery band paints
								// `linear-gradient(#27104a 30%, #ffffff 0%)` on the box and a decorative
								// `url(bg-01.png)` on `::before` at opacity 0.2 — reading the pseudo
								// first recorded the PHOTO as the surface, so the gradient was never
								// emitted, the band rendered white, and its white intro copy was then
								// dropped as "invisible on white" (2026-11-24).
								if ( Number.parseFloat( style.opacity ) < 0.5 ) {
									continue;
								}
								const image = style.backgroundImage;
								if ( imageIsSurface( image ) ) {
									return { color: style.backgroundColor, image };
								}
								if ( isOpaque( style.backgroundColor ) ) {
									return { color: style.backgroundColor };
								}
							}
							return undefined;
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
								// the most common gradient form. A positioned overlay pseudo paints
								// ABOVE that surface, so it is read first (P5).
								const ownPseudo = pseudoSurface( element );
								if ( ownPseudo ) {
									return ownPseudo;
								}
								const ownImage = ownStyle.backgroundImage;
								const ownHasImage = imageIsSurface( ownImage );
								if ( ownHasImage || isOpaque( own ) ) {
									return { color: own, ...( ownHasImage ? { image: ownImage } : {} ) };
								}
							}
							if ( ! effective ) {
								return { color: own };
							}
							// A chrome region (header/footer) painted on an overlay pseudo — the
							// same P5 rule, before the ancestor walk can report the page canvas.
							const overlay = pseudoSurface( element );
							if ( overlay ) {
								return overlay;
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
								const bandArea = Math.max( 1, width * bandRect.height );
								const candidates: Array< { bg: string; image?: string; area: number; w: number; h: number } > = [];
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
									const backgroundImage = style.backgroundImage;
									const hasImage = imageIsSurface( backgroundImage );
									const nodePseudo = pseudoSurface( node );
									// A translucent colour is a tint over the layer below — unless an
									// opaque overlay pseudo paints the node (P5).
									if ( fillAlpha > 0 && fillAlpha < 0.95 && ! nodePseudo ) {
										continue;
									}
									if ( fillAlpha === 0 && ! hasImage && ! nodePseudo ) {
										continue;
									}
									const rect = node.getBoundingClientRect();
									if ( rect.height < 20 ) {
										continue;
									}
									candidates.push( {
										bg: nodePseudo?.color ?? background,
										...( nodePseudo?.image || hasImage ? { image: nodePseudo?.image ?? backgroundImage } : {} ),
										area: rect.width * rect.height,
										w: rect.width,
										h: rect.height,
									} );
								}
								let best: ( typeof candidates )[ number ] | undefined;
								for ( const candidate of candidates ) {
									if ( ! best || candidate.area > best.area ) {
										best = candidate;
									}
								}
								// A painted CHILD is the band's SURFACE only when it BANDS the
								// region. A full-width CONTROL is not: eca.directory's
								// "Explore Our Activities" band read #8764a0 because its accent
								// button spanned the row (53px of 250px), and the contact band read
								// #ffffff off a full-width form field — both before the builder's
								// own layout CSS applied. Require half the band's AREA, or
								// same-coloured siblings tiling the width AND the height (a
								// two-column banner).
								if ( best && best.area < bandArea * 0.5 ) {
									const tiled = candidates
										.filter( ( candidate ) => candidate.bg === best!.bg && candidate.image === best!.image )
										.reduce( ( sum, candidate ) => sum + candidate.w, 0 );
									if ( tiled < width * 0.9 || best.h < bandRect.height * 0.5 ) {
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
								const backgroundImage = style.backgroundImage;
								const rect = node.getBoundingClientRect();
								const sameScale = rect.width * rect.height <= ownArea * 2;
								// P6 — an inner-section anchor (`#products` is an Elementor
								// `inner-section`) carries no surface of its own; its parent band
								// does, and that surface is a GRADIENT as often as a colour. The
								// walk used to accept only `background-color`, so it stepped past
								// the gradient band to the page canvas and the gate compared the
								// wrong layer. Accept either paint, and a positioned pseudo.
								if ( sameScale ) {
									const nodePseudo = pseudoSurface( node );
									if ( nodePseudo ) {
										return nodePseudo;
									}
									const hasImage = imageIsSurface( backgroundImage );
									if ( isOpaque( background ) || hasImage ) {
										return hasImage ? { color: background, image: backgroundImage } : { color: background };
									}
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
						const out: Record<
							string,
							{
								background: string;
								image?: string;
								inks: Array< { color: string; mass: number } >;
								heading: Array< { color: string; mass: number } >;
								body: Array< { color: string; mass: number } >;
								selector: string;
								width: number;
								height: number;
							}
						> = {};
						// The ink a region RENDERS is not the element's inherited `color` on a
						// builder site: the theme sets a global body colour and paints the
						// visible text from a context class on inner containers (Flatsome's
						// `.dark` — monicarajiv.com computes `body { color: #000 }` while every
						// rendered word is white). Every text-bearing descendant is measured
						// and weighted by its RENDERED INK AREA (`inkMass`), so the palette is
						// the share of the region's text each colour actually paints. Counting
						// ELEMENTS instead is what made a lone caption outvote a paragraph.
						const readInk = (
							element: Element
						): {
							inks: Array< { color: string; mass: number } >;
							heading: Array< { color: string; mass: number } >;
							body: Array< { color: string; mass: number } >;
						} => {
							const masses = new Map< string, number >();
							// The same mass, split by ROLE. A region's palette cannot say which of
							// its inks is a heading, and the chrome slots need exactly that: the
							// rebuilt footer paints H3s from `footerInk` and list copy from a
							// separate text slot. eca.directory's footer is 62% `#747474` link
							// lists over 38% `#27104a` block titles, so the palette winner put
							// the LINK colour on the titles (2026-11-24).
							const headingMasses = new Map< string, number >();
							const bodyMasses = new Map< string, number >();
							// A builder's heading is frequently NOT a heading element: Elementor
							// publishes its block titles as `div.elementor-heading-title`. The tag
							// alone misses them, so a heading/title class at semibold-or-heavier
							// counts too. Per text-bearing element, never a container: the class
							// sits on the widget and the text sits on its child.
							const headingLike = ( node: Element, weight: string ): boolean => {
								if ( /^h[1-6]$/i.test( node.tagName ) ) {
									return true;
								}
								const className = node.getAttribute( 'class' ) ?? '';
								if ( ! /heading|title/i.test( className ) ) {
									return false;
								}
								return ( Number.parseInt( weight, 10 ) || 400 ) >= 600;
							};
							// The region's OWN painted box. A builder leaves legacy descendants
							// in the DOM that render outside the element and are covered by the
							// next band — jayainc's site-title link sits 23px under its hero, so
							// its cyan ink was counted against a header that never shows it
							// (2026-11-12). Text the region does not contain is not its ink.
							const regionRect = element.getBoundingClientRect();
							let seen = 0;
							const consider = ( node: Element ): void => {
								if ( seen > 1200 ) {
									return;
								}
								seen += 1;
								let characters = 0;
								for ( const child of [ ...node.childNodes ] ) {
									if ( child.nodeType === 3 ) {
										characters += ( child.textContent ?? '' ).trim().length;
									}
								}
								if ( characters === 0 ) {
									return;
								}
								const rect = node.getBoundingClientRect();
								if ( rect.width < 2 || rect.height < 2 ) {
									return;
								}
								const offRegion =
									rect.bottom < regionRect.top + 1 ||
									rect.top > regionRect.bottom - 1 ||
									rect.right < regionRect.left + 1 ||
									rect.left > regionRect.right - 1;
								if ( offRegion ) {
									return;
								}
								const style = getComputedStyle( node );
								const fontPx = Number.parseFloat( style.fontSize ) || 16;
								const mass = characters * fontPx * fontPx;
								masses.set( style.color, ( masses.get( style.color ) ?? 0 ) + mass );
								const role = headingLike( node, style.fontWeight ) ? headingMasses : bodyMasses;
								role.set( style.color, ( role.get( style.color ) ?? 0 ) + mass );
							};
							consider( element );
							for ( const node of element.querySelectorAll( '*' ) ) {
								consider( node );
							}
							// A region with NO text renders no ink — comparing its inherited
							// colour reports a difference between two invisible values (the hero
							// band is an image + a chevron). An empty palette makes the ink
							// metric not apply, exactly like a transparent surface.
							const toList = ( map: Map< string, number > ): Array< { color: string; mass: number } > =>
								[ ...map.entries() ].map( ( [ color, mass ] ) => ( { color, mass } ) );
							return { inks: toList( masses ), heading: toList( headingMasses ), body: toList( bodyMasses ) };
						};
						for ( const region of args.regions ) {
							for ( const selector of region.selectors ) {
								// A malformed selector must cost THIS region its measurement, never
								// the whole gate: `querySelector('#6daaaef9')` throws because a CSS
								// identifier cannot start with a digit, and one unescaped id took
								// down every colour comparison on the page (eca.directory,
								// 2026-11-24). Builders mint digit-leading ids constantly.
								let element = null;
								try {
									element = document.querySelector( selector );
								} catch {
									element = null;
								}
								if ( element ) {
									const surface = readBackground(
										element,
										args.effective,
										region.descendantFill === true
									);
									const box = element.getBoundingClientRect();
									const ink = readInk( element );
									out[ region.name ] = {
										background: surface.color,
										inks: ink.inks,
										heading: ink.heading,
										body: ink.body,
										selector,
										width: box.width,
										height: box.height,
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
					// The palette is summarised in Node, not the page: it is pure maths
					// (alpha collapse, ΔE merge, share rounding) and belongs in a unit test.
					const inks = summarizeInk( values.inks );
					// The role inks are the SAME maths over one bucket each: the single
					// dominant colour of the heading text and of the body text. No share
					// floor — a region whose headings are 4% of its glyphs still HAS a
					// heading ink, and the chrome slots need it.
					const headingInk = summarizeInk( values.heading, { limit: 1, minShare: 0 } )[ 0 ]?.color;
					const bodyInk = summarizeInk( values.body, { limit: 1, minShare: 0 } )[ 0 ]?.color;
					baseline[ region ] = {
						background: normalizeCssColor( values.background ),
						// `text` stays the DOMINANT ink so a baseline recorded before palettes
						// existed still verifies, and the human-readable output keeps one
						// colour per region. `inks` carries the whole measured palette.
						text: inks[ 0 ]?.color ?? TRANSPARENT,
						...( inks.length > 0 ? { inks } : {} ),
						...( headingInk || bodyInk
							? {
									roles: {
										...( headingInk ? { heading: headingInk } : {} ),
										...( bodyInk ? { body: bodyInk } : {} ),
									},
							  }
							: {} ),
						// A gradient/URL is not a colour — pass it through verbatim rather
						// than letting `normalizeCssColor` collapse it to `transparent`.
						...( values.image ? { image: values.image } : {} ),
					};
				}
				// Resolution is a DOM fact, so one mode is enough: the element a ladder
				// matched does not depend on the colour scheme.
				if ( Object.keys( resolutions ).length === 0 ) {
					for ( const [ region, values ] of Object.entries( colors ) ) {
						resolutions[ region ] = {
							region,
							selector: values.selector,
							found: true,
							width: Math.round( values.width ),
							height: Math.round( values.height ),
							inkSamples: values.inks.length,
						};
					}
					for ( const region of regions ) {
						if ( ! resolutions[ region.name ] ) {
							resolutions[ region.name ] = {
								region: region.name,
								found: false,
								width: 0,
								height: 0,
								inkSamples: 0,
							};
						}
					}
				}
				measured[ mode ] = baseline;
			} finally {
				await context.close();
			}
		}
		return { modes: measured, resolutions };
	} finally {
		await browser.close();
	}
}

/** The baseline alone — what goes into the profile. */
export async function measureRegionColors( options: MeasureColorOptions ): Promise< ForgeColorModes > {
	return ( await measureRegionColorsDetailed( options ) ).modes;
}

/** Convenience for callers building a profile draft. */
export function regionColors( background: string, text: string ): RegionColors {
	return { background: normalizeCssColor( background ), text: normalizeCssColor( text ) };
}
