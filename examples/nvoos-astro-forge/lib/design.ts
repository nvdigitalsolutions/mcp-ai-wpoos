// ---------------------------------------------------------------------------
// design.ts — the forge design-import module (Proposal 114 P1/P5).
//
// The extraction half of the rebuild pipeline: a deterministic, static,
// WordPress-mode analyzer that turns a source site's HTML + same-origin CSS
// into a `ForgeDesign` — palette roles with confidence + an unassigned pool
// (098's fail-loud discipline), typography, spacing/radii/shadow rhythm,
// component vocabulary, a concept summary, and the mandatory adaptation
// brief. Never a clone: no source CSS, markup, copy, or assets are emitted —
// token data + prose only (098 §3.2 contract).
//
// The same heuristics also analyze a BUILT site's CSS bundle, which is what
// `forge verify` diffs against the stored source design for the
// language-parity score (114 §3.6). Pure where possible; the fetch step is
// bounded (homepage + ≤6 same-origin stylesheets) and SSRF-guarded by the
// caller (assertSafeHttpUrl / isFetchAllowed before this module is reached).
//
// Node type-stripping safe: no enums, no namespaces, no parameter properties.
// ---------------------------------------------------------------------------

import { Parser, DomHandler } from 'htmlparser2';
import { createRequire } from 'node:module';

import { expandHex, hexToRgb, hslToHex, rgbToHex } from './color.ts';
import { contrastRatio, relativeLuminance } from './contrast.ts';
import { assertSafeHttpUrl } from './ssrf.ts';
import { extractBandsFromHtml } from './bands.ts';
import type { BandCandidate } from './bands.ts';
import { scopedPaddingOf } from './scoped-css.ts';
import type { ForgeChrome, ForgeChromeStyle, ForgeDesign, ForgeSection, ForgeSectionColumn, ForgeStickyHeader, ResponsiveLength, ScrollBehaviorEntry, ScrollBehaviorPass } from './types.ts';

const nodeRequire = createRequire( import.meta.url );

/** Bounded fetch input: the homepage HTML plus same-origin CSS bodies. */
export interface DesignFetchInput {
	url: string;
	html: string;
	css: string[];
	/** Logo-variant dominant colors by absolute src (the contrast-based
	 * logo assignment, 124 §1) — absent when the assets were not fetched. */
	logoColors?: Record<string, string[]>;
}

export type RoleConfidence = 'high' | 'medium' | 'low';

// -- color helpers -----------------------------------------------------------

const HEX_RE = /#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})\b/gi;
const RGB_RE = /rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*[\d.]+)?\s*\)/gi;
const HSL_RE = /hsla?\(\s*([\d.]+)\s*,\s*([\d.]+)%\s*,\s*([\d.]+)%(?:\s*,\s*[\d.]+)?\s*\)/gi;
const CSS_VAR_RE = /(--[\w-]+)\s*:\s*([^;}]+)/g;
const COLOR_VALUE_RE = /^(?:#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})\b|rgba?\(|hsla?\()/i;

/** CSS custom properties whose names hint at a semantic palette role (098 analyzer parity). */
const VAR_ROLE_HINTS: Record<string, RegExp> = {
	'accent-ink': /(?:accent|primary)[-_]?(?:text|ink)\b|on-(?:accent|primary)/i,
	accent: /(?:^|[-_])(?:accent|primary|brand)(?=$|[-_](?!text|ink|on))|highlight/i,
	surface: /background|surface|bg\b|canvas/i,
	text: /(?<![-_](?:primary|secondary|accent|on|danger|success|warning|info))[-_]text(?:$|[-_])|foreground|content[-_]?text|body[-_]?text|text[-_]?color|(?:^|[-_])ink(?:$|[-_])/i,
	'field.border': /border|stroke|outline/i,
};

/** Elementor global-color names (the common page-builder var surface). */
const ELEMENTOR_ROLE_NAMES: Record<string, string> = {
	primary: 'accent',
	secondary: 'accent-alt',
	text: 'text',
	accent: 'accent',
};

// The stylesheet cap keeps hostile sources from ballooning the analysis, but
// it must be generous enough to reach the theme's CUSTOMIZER file — the
// override sheet that carries the real body/header/footer facts (jayainc's
// `uploads/phlox-pro/custom.css` sat at index 7 and a 6-cap silently dropped
// the site's entire typography/chrome truth). The cumulative byte cap below
// still bounds the total payload.
const MAX_STYLESHEETS = 40;
const MAX_CSS_TOTAL_BYTES = 3 * 1024 * 1024;
const MAX_HTML_BYTES = 2 * 1024 * 1024;
// The per-file cap must hold a full theme sheet (jayainc's main.css is
// ~886KB) — a 512KB cut lands mid-@media block and the dangling brace
// swallows every stylesheet fetched after it.
const MAX_CSS_BYTES = 2 * 1024 * 1024;

// -- logo color extraction (124 §1) -------------------------------------------

/** The site's logo candidate imgs — class/id carries a logo/brand marker
 * (custom-logo, aux-logo-image1/2, brand-*, …), never content images whose
 * FILENAME merely mentions a logo. Deduplicated, bounded. */
export function logoCandidates( html: string ): string[] {
	const srcs: string[] = [];
	for ( const match of html.matchAll( /<img[^>]*>/gi ) ) {
		const src = /src=["']([^"']+)["']/i.exec( match[ 0 ] )?.[ 1 ];
		if ( ! src ) {
			continue;
		}
		const cls = /class=["']([^"']+)["']/i.exec( match[ 0 ] )?.[ 1 ] ?? '';
		const id = /id=["']([^"']+)["']/i.exec( match[ 0 ] )?.[ 1 ] ?? '';
		if ( ! /(?:^|\s)(?:[a-z-]*logo[a-z-]*|brand)(?:\s|$)/i.test( ` ${ cls } ${ id } ` ) ) {
			continue;
		}
		srcs.push( src );
	}
	return [ ...new Set( srcs ) ].slice( 0, 6 );
}

/** Frequency-ranked fill colors of an SVG logo (text parse, no deps). */
export function svgFillsOf( svg: string ): string[] {
	const counts = new Map<string, number>();
	const valueRe = /(?:fill|stop-color)\s*=\s*["']([^"']+)["']/gi;
	for ( const match of svg.matchAll( valueRe ) ) {
		const value = ( match[ 1 ] ?? '' ).trim().toLowerCase();
		if ( value === 'none' || value === 'transparent' || value.startsWith( 'url(' ) ) {
			continue;
		}
		const hex = normalizeColor( value );
		if ( hex && hex !== 'transparent' ) {
			counts.set( hex, ( counts.get( hex ) ?? 0 ) + 1 );
		}
	}
	return [ ...counts.entries() ].sort( ( a, b ) => b[ 1 ] - a[ 1 ] ).slice( 0, 4 ).map( ( [ hex ] ) => hex );
}

/** Dominant colors of a raster logo — sharp when resolvable (the forge dev
 * toolchain ships it; the published CLI degrades to the filename heuristic). */
async function rasterLogoColors( bytes: Uint8Array ): Promise<string[] | undefined> {
	let sharp: { default?: unknown } | undefined;
	try {
		sharp = nodeRequire( 'sharp' ) as { default?: unknown } | undefined;
	} catch {
		return undefined;
	}
	const impl = ( ( sharp?.default ?? sharp ) as ( input: Buffer ) => {
		resize: ( w: number, h: number, opts: Record<string, unknown> ) => unknown;
	} );
	if ( typeof impl !== 'function' ) {
		return undefined;
	}
	try {
		const pipeline = impl( Buffer.from( bytes ) ).resize( 24, 24, { fit: 'inside' } ) as {
			ensureAlpha: () => { raw: () => { toBuffer: ( opts: { resolveWithObject: true } ) => Promise<{ data: Buffer; info: { channels: number } }> } };
		};
		const { data } = await pipeline.ensureAlpha().raw().toBuffer( { resolveWithObject: true } );
		const counts = new Map<string, number>();
		for ( let index = 0; index + 3 < data.length; index += 4 ) {
			if ( data[ index + 3 ] < 128 ) {
				continue;
			}
			const key = `${ data[ index ] >> 4 }-${ data[ index + 1 ] >> 4 }-${ data[ index + 2 ] >> 4 }`;
			counts.set( key, ( counts.get( key ) ?? 0 ) + 1 );
		}
		return [ ...counts.entries() ]
			.sort( ( a, b ) => b[ 1 ] - a[ 1 ] )
			.slice( 0, 3 )
			.map( ( [ key ] ) => {
				const [ r, g, b ] = key.split( '-' ).map( ( part ) => ( parseInt( part, 10 ) << 4 ) + 8 );
				return rgbToHex( r, g, b );
			} );
	} catch {
		return undefined;
	}
}

/** Filename-hint fallback colors (wht/white/light → white, dark/black →
 * near-black) — exact colors always come from the fetched asset itself. */
export function heuristicLogoColors( src: string ): string[] | undefined {
	const stem = decodeURIComponent( ( src.split( '?' )[ 0 ].split( '/' ).pop() ?? '' ).toLowerCase() ).replace( /\.[a-z0-9]+$/, '' );
	if ( /(?:^|[-_])(?:wht|white|light)(?:[-_]|$)/.test( stem ) ) {
		return [ '#ffffff' ];
	}
	if ( /(?:^|[-_])(?:dark|black)(?:[-_]|$)/.test( stem ) ) {
		return [ '#101010' ];
	}
	return undefined;
}

/** Is this img a site-logo candidate (class/id carries a logo/brand marker)?
 * Content images whose FILENAME merely mentions a logo never qualify. */
export function isLogoImg( node: { attribs?: Record<string, string> } ): boolean {
	if ( ! node.attribs?.src ) {
		return false;
	}
	const cls = node.attribs?.class ?? '';
	const id = node.attribs?.id ?? '';
	return /(?:^|\s)(?:[a-z-]*logo[a-z-]*|brand)(?:\s|$)/i.test( ` ${ cls } ${ id } ` );
}

/** The color stops of a surface (flat → itself; gradients → their stops) —
 * a logo variant must clear EVERY stop (worst-case scoring, 124 §1). */
function surfaceStops( surface: string | undefined ): string[] {
	if ( ! surface ) {
		return [];
	}
	const stops: string[] = [];
	for ( const match of surface.matchAll( HEX_RE ) ) {
		const hex = expandHex( match[ 0 ] ).toLowerCase();
		if ( ! stops.includes( hex ) ) {
			stops.push( hex );
		}
	}
	for ( const match of surface.matchAll( RGB_RE ) ) {
		const hex = rgbToHex( parseInt( match[ 1 ], 10 ), parseInt( match[ 2 ], 10 ), parseInt( match[ 3 ], 10 ) );
		if ( ! stops.includes( hex ) ) {
			stops.push( hex );
		}
	}
	if ( stops.length === 0 ) {
		const flat = normalizeColor( surface );
		if ( flat && flat !== 'transparent' ) {
			stops.push( flat );
		}
	}
	return stops.slice( 0, 4 );
}

/** Worst-case WCAG contrast of a candidate against the surface stops. */
function logoContrastScore( colors: string[], surface: string | undefined ): number | undefined {
	const stops = surfaceStops( surface );
	if ( stops.length === 0 ) {
		return undefined;
	}
	let worst = Infinity;
	for ( const color of colors ) {
		for ( const stop of stops ) {
			const ratio = contrastRatio( color, stop );
			if ( ratio !== undefined ) {
				worst = Math.min( worst, ratio );
			}
		}
	}
	return worst === Infinity ? undefined : worst;
}

/** Pick the best-contrast logo variant for a surface (WCAG 1.4.11 non-text
 * contrast — 3:1 minimum, max wins). Exact colors come from the fetched
 * assets (logoColors, keyed by ABSOLUTE src); the filename heuristic
 * stands in when the asset was not fetched. Ties keep pool order (footer
 * DOM candidates first for footers). Undefined when no variant scores. */
export function pickLogoVariant<T extends { attribs?: Record<string, string> | undefined }>(
	pool: T[],
	surface: string | undefined,
	logoColors: Record<string, string[]> | undefined,
	baseUrl: string
): T | undefined {
	let best: T | undefined;
	let bestScore = -Infinity;
	for ( const candidate of pool ) {
		const src = candidate.attribs?.src;
		if ( ! src ) {
			continue;
		}
		let absolute = src;
		try {
			absolute = new URL( src, baseUrl ).href;
		} catch {
			// Keep the raw src — the lookup falls back to it below.
		}
		const colors = logoColors?.[ absolute ] ?? logoColors?.[ src ] ?? heuristicLogoColors( src );
		if ( ! colors || colors.length === 0 ) {
			continue;
		}
		const score = logoContrastScore( colors, surface );
		if ( score !== undefined && score > bestScore ) {
			best = candidate;
			bestScore = score;
		}
	}
	return best;
}

/** Expand a short hex (#abc → #aabbcc); alpha is dropped. */
function hueOf( hex: string ): number {
	const [ r, g, b ] = hexToRgb( hex ).map( ( channel ) => channel / 255 );
	const max = Math.max( r, g, b );
	const min = Math.min( r, g, b );
	const delta = max - min;
	if ( delta === 0 ) {
		return 0;
	}
	let hue: number;
	if ( max === r ) hue = ( ( g - b ) / delta ) % 6;
	else if ( max === g ) hue = ( b - r ) / delta + 2;
	else hue = ( r - g ) / delta + 4;
	return Math.round( hue * 60 );
}

function saturationOf( hex: string ): number {
	const [ r, g, b ] = hexToRgb( hex ).map( ( channel ) => channel / 255 );
	const max = Math.max( r, g, b );
	const min = Math.min( r, g, b );
	return max === 0 ? 0 : Math.round( ( ( max - min ) / max ) * 100 );
}

/** Perceived lightness 0–100 (the WCAG luminance, scaled). */
export function lightnessOf( hex: string ): number {
	const [ r, g, b ] = hexToRgb( hex ).map( ( c ) => {
		const s = c / 255;
		return s <= 0.04045 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
	} );
	return Math.round( ( 0.2126 * r + 0.7152 * g + 0.0722 * b ) * 100 );
}

/** ΔE on hex colors via CIE76 RGB distance, normalized 0–1 (identical → 0). */
export function colorDistance( a: string, b: string ): number {
	const [ r1, g1, b1 ] = hexToRgb( a );
	const [ r2, g2, b2 ] = hexToRgb( b );
	const d = Math.sqrt( ( r1 - r2 ) ** 2 + ( g1 - g2 ) ** 2 + ( b1 - b2 ) ** 2 );
	return Math.min( 1, d / ( Math.sqrt( 3 ) * 255 ) );
}

// -- fetch (bounded, static) -------------------------------------------------

/** Parse the homepage HTML for same-origin stylesheet links (cap enforced). */
export function stylesheetLinks( html: string, baseUrl: string ): string[] {
	const links: string[] = [];
	for ( const match of html.matchAll( /<link[^>]+rel=["']?stylesheet["']?[^>]*>/gi ) ) {
		const href = /href=["']([^"']+)["']/i.exec( match[ 0 ] )?.[ 1 ];
		if ( ! href ) {
			continue;
		}
		try {
			const absolute = new URL( href, baseUrl ).href;
			if ( new URL( absolute ).hostname === new URL( baseUrl ).hostname ) {
				links.push( absolute );
			}
		} catch {
			// Unparseable href — skip.
		}
	}
	return [ ...new Set( links ) ].slice( 0, MAX_STYLESHEETS );
}

/**
 * Fetch the homepage + bounded same-origin stylesheets for a site URL.
 * The caller has already passed the URL through the SSRF guard + registry
 * policy; this function re-asserts the literal-range rejection and enforces
 * size caps so a hostile source cannot balloon the analysis. `allowLoopback`
 * is the CLI-only local-dev opt-in (NVOOS_FORGE_ALLOW_LOOPBACK) — the MCP
 * surface never sets it.
 */
export async function fetchDesignInput(
	url: string,
	fetchImpl: typeof fetch = fetch,
	options: { allowLoopback?: boolean } = {}
): Promise<DesignFetchInput> {
	assertSafeHttpUrl( url, options );
	const base = url.replace( /\/+$/, '' );
	const home = await fetchImpl( base, { redirect: 'follow', signal: AbortSignal.timeout( 20_000 ) } );
	if ( ! home.ok ) {
		throw new Error( `Design fetch failed: ${ home.status } ${ home.statusText } for ${ base }` );
	}
	const html = ( await home.text() ).slice( 0, MAX_HTML_BYTES );
	const css: string[] = [];
	// Collect stylesheets in DOCUMENT order — inline <style> blocks
	// interleave with the linked sheets at their printed positions (WP
	// prints the inline blocks after the links; an inline 16px nav rule
	// must cascade AFTER the linked 0.875em rule, as the browser sees it).
	let cssTotalBytes = 0;
	const tagRe = /<link[^>]+rel=["']?stylesheet["']?[^>]*>|<style[^>]*>[\s\S]*?<\/style>/gi;
	for ( const match of html.matchAll( tagRe ) ) {
		const tag = match[ 0 ];
		if ( tag.startsWith( '<style' ) ) {
			const body = tag.replace( /^<style[^>]*>|<\/style>$/gi, '' ).slice( 0, MAX_CSS_BYTES );
			css.push( body );
			continue;
		}
		if ( cssTotalBytes >= MAX_CSS_TOTAL_BYTES ) {
			continue;
		}
		const href = /href=["']([^"']+)["']/i.exec( tag )?.[ 1 ];
		if ( ! href ) {
			continue;
		}
		let absolute: string;
		try {
			absolute = new URL( href, base ).href;
			if ( new URL( absolute ).hostname !== new URL( base ).hostname ) {
				continue;
			}
		} catch {
			continue;
		}
		try {
			const res = await fetchImpl( absolute, { redirect: 'follow', signal: AbortSignal.timeout( 15_000 ) } );
			if ( res.ok ) {
				const body = ( await res.text() ).slice( 0, MAX_CSS_BYTES );
				css.push( body );
				cssTotalBytes += body.length;
			}
		} catch {
			// A missing stylesheet is never fatal — the remaining signals stand.
		}
	}
	// The logo variant pool (124 §1): fetch each candidate and extract its
	// dominant colors (SVG fills — a text parse; rasters via sharp when
	// available) so the chrome pass can assign variants to surfaces by
	// WCAG 1.4.11 contrast. Bounded, fail-soft — a missing asset never
	// fails the analysis; the filename heuristics stand in.
	const logoColors: Record<string, string[]> = {};
	for ( const src of logoCandidates( html ) ) {
		let absolute: string;
		try {
			absolute = new URL( src, base ).href;
			if ( new URL( absolute ).hostname !== new URL( base ).hostname ) {
				continue;
			}
		} catch {
			continue;
		}
		try {
			const res = await fetchImpl( absolute, { redirect: 'follow', signal: AbortSignal.timeout( 10_000 ) } );
			if ( ! res.ok ) {
				continue;
			}
			const bytes = new Uint8Array( await res.arrayBuffer() );
			if ( bytes.byteLength > 256 * 1024 ) {
				continue;
			}
			const text = new TextDecoder().decode( bytes.slice( 0, 2048 ) );
			const colors = /<svg/i.test( text ) ? svgFillsOf( new TextDecoder().decode( bytes ) ) : await rasterLogoColors( bytes );
			if ( colors && colors.length > 0 ) {
				logoColors[ absolute ] = colors;
			}
		} catch {
			// A missing logo asset is never fatal — the heuristics stand.
		}
	}
	return { url: base, html, css, logoColors };
}

// -- analysis ----------------------------------------------------------------

/** Deterministic concept summary from the confident roles + type pairing. */
function conceptFor( roles: Record<string, string>, displayFamily?: string, bodyFamily?: string ): string {
	const accent = roles.accent;
	const warmth = accent ? ( hueOf( accent ) > 210 || ( hueOf( accent ) >= 150 && hueOf( accent ) <= 210 ) ? 'cool' : hueOf( accent ) < 60 || hueOf( accent ) >= 300 ? 'warm' : 'neutral' ) : 'neutral';
	const pair = displayFamily && bodyFamily ? `${ displayFamily.toLowerCase() } display / ${ bodyFamily.toLowerCase() } body` : 'single-family type';
	return `${ warmth } accent over light neutrals, ${ pair }, compact spacing rhythm.`;
}

/** Frequency-ranked color map from arbitrary CSS text. */
function colorFrequencies( cssText: string ): Map<string, number> {
	const counts = new Map<string, number>();
	const bump = ( value: string ) => {
		counts.set( value, ( counts.get( value ) ?? 0 ) + 1 );
	};
	for ( const match of cssText.matchAll( HEX_RE ) ) {
		bump( expandHex( match[ 0 ] ).toLowerCase() );
	}
	for ( const match of cssText.matchAll( RGB_RE ) ) {
		bump( rgbToHex( Number( match[ 1 ] ), Number( match[ 2 ] ), Number( match[ 3 ] ) ).toLowerCase() );
	}
	for ( const match of cssText.matchAll( HSL_RE ) ) {
		bump( hslToHex( Number( match[ 1 ] ), Number( match[ 2 ] ), Number( match[ 3 ] ) ).toLowerCase() );
	}
	return counts;
}

/** Ranked font families from CSS (font-family declarations + font-* custom properties — the forge/Elementor typography-token layers). */
function familyFrequencies( cssText: string ): Array<{ family: string; weight: number }> {
	const counts = new Map<string, number>();
	const bump = ( raw: string ) => {
		const first = raw.split( ',' )[ 0 ].trim().replace( /^["']|["']$/g, '' ).toLowerCase();
		// Generic/system families are stack fallbacks, not design choices.
		if ( ! first || GENERIC_FAMILIES.has( first ) || first.startsWith( 'var(' ) ) {
			return;
		}
		counts.set( first, ( counts.get( first ) ?? 0 ) + 1 );
	};
	for ( const match of cssText.matchAll( /font-family\s*:\s*([^;}]+)/gi ) ) {
		bump( match[ 1 ] );
	}
	// Font values carried by typography custom properties
	// (--e-global-typography-*-font-family, --forge-font-display, …).
	for ( const match of cssText.matchAll( /(--[\w-]*font[\w-]*)\s*:\s*([^;}]+)/gi ) ) {
		bump( match[ 2 ] );
	}
	return [ ...counts.entries() ]
		.map( ( [ family, weight ] ) => ( { family, weight } ) )
		.sort( ( a, b ) => b.weight - a.weight );
}

/** Stack fallbacks that never count as the design's type pairing. */
const GENERIC_FAMILIES = new Set( [
	'inherit',
	'initial',
	'unset',
	'ui-monospace',
	'ui-sans-serif',
	'ui-serif',
	'system-ui',
	'-apple-system',
	'sans-serif',
	'serif',
	'monospace',
	'cursive',
	'fantasy',
] );

/** Sorted numeric values extracted from px declarations (font-size/margin/…). */
function pxValues( cssText: string, patterns: Array<{ pattern: RegExp; pxPerUnit?: number }> ): number[] {
	const values = new Set<number>();
	for ( const { pattern, pxPerUnit } of patterns ) {
		for ( const match of cssText.matchAll( pattern ) ) {
			const raw = parseFloat( match[ 1 ] );
			const value = pxPerUnit ? raw * pxPerUnit : raw;
			if ( Number.isFinite( value ) && value > 0 && value <= 160 ) {
				values.add( Math.round( value * 10 ) / 10 );
			}
		}
	}
	return [ ...values ].sort( ( a, b ) => a - b );
}

// The forge's scale/spacing tokens are custom properties
// (--forge-scale-h1: 40px) — px signals live in the var VALUES, not in
// font-size/margin declarations, so both declaration and token forms count.
// rem/em sizes normalize to px at the 16px root (the industry default) so
// display sizes (jayainc's 4.5rem hero = 72px) enter the scale.
const FONT_SCALE_PATTERNS: Array<{ pattern: RegExp; pxPerUnit?: number }> = [
	{ pattern: /font-size\s*:\s*([\d.]+)px/gi },
	{ pattern: /font-size\s*:\s*([\d.]+)r?em/gi, pxPerUnit: 16 },
	{ pattern: /--[\w-]*scale[\w-]*\s*:\s*([\d.]+)px/gi },
];
const SPACE_PATTERNS: Array<{ pattern: RegExp }> = [
	{ pattern: /(?:margin|padding|gap)\s*(?:-\w+)?\s*:\s*([\d.]+)px/gi },
	{ pattern: /--[\w-]*spac[\w-]*\s*:\s*([\d.]+)px/gi },
];
const RADIUS_PATTERNS: Array<{ pattern: RegExp }> = [ { pattern: /border-radius\s*:\s*([\d.]+)px/gi } ];

/**
 * Trim a step ladder to at most `max` steps while keeping BOTH ends — the
 * small reading sizes AND the display sizes (a naive head-slice drops the
 * hero size, which is the one the design system needs most).
 */
function trimLadder( values: number[], max: number ): number[] {
	if ( values.length <= max ) {
		return values;
	}
	return [ ...values.slice( 0, Math.max( 2, max - 6 ) ), ...values.slice( -6 ) ];
}

// The type-scale bands (117 §5 + jayainc): a dense builder harvest yields
// 50+ font sizes — the ladder must keep a REPRESENTATIVE of each band, not
// a raw bottom/top slice (the 4/6 split dropped the entire reading band:
// body became 64px on a 52-value harvest). Bands, not positions.
const SCALE_BANDS = {
	small: [ 0, 12 ],
	body: [ 13, 20 ],
	card: [ 24, 34 ],
	section: [ 36, 60 ],
	hero: [ 60, 100 ],
} as const;

function bandValues( values: number[], lo: number, hi: number ): number[] {
	return values.filter( ( value ) => value >= lo && value <= hi );
}

/** The first (smallest) value in the band — the body/small reading size. */
function firstInBand( values: number[], lo: number, hi: number ): number | undefined {
	return bandValues( values, lo, hi )[ 0 ];
}

/** The value closest to the band center — the band representative. */
function centerOfBand( values: number[], lo: number, hi: number ): number | undefined {
	const band = bandValues( values, lo, hi );
	if ( band.length === 0 ) {
		return undefined;
	}
	const center = ( lo + hi ) / 2;
	return band.reduce( ( best, value ) => ( Math.abs( value - center ) < Math.abs( best - center ) ? value : best ) );
}

/** The smallest value strictly above `min` and at most `hi` (a step above). */
function nextStep( values: number[], min: number, hi: number ): number | undefined {
	return values.filter( ( value ) => value > min && value <= hi ).sort( ( a, b ) => a - b )[ 0 ];
}

/**
 * Trim the FONT-size ladder band-aware (jayainc: 52 harvested sizes): the
 * first small steps, the body size, a card-title representative, a section-
 * title representative, the hero representative and the largest display
 * value. The token ranking below reads the same bands off this ladder.
 */
function trimScaleLadder( values: number[] ): number[] {
	if ( values.length <= 10 ) {
		return values;
	}
	const picked = new Set<number>();
	for ( const value of bandValues( values, SCALE_BANDS.small[ 0 ], SCALE_BANDS.small[ 1 ] ).slice( 0, 3 ) ) {
		picked.add( value );
	}
	const body = firstInBand( values, SCALE_BANDS.body[ 0 ], SCALE_BANDS.body[ 1 ] );
	if ( body !== undefined ) {
		picked.add( body );
	}
	const card = centerOfBand( values, SCALE_BANDS.card[ 0 ], SCALE_BANDS.card[ 1 ] );
	if ( card !== undefined ) {
		picked.add( card );
	}
	const section = centerOfBand( values, SCALE_BANDS.section[ 0 ], SCALE_BANDS.section[ 1 ] );
	if ( section !== undefined ) {
		picked.add( section );
	}
	const hero = centerOfBand( values, SCALE_BANDS.hero[ 0 ], SCALE_BANDS.hero[ 1 ] );
	if ( hero !== undefined ) {
		picked.add( hero );
	}
	// The largest display value (banner-size) keeps the full range in the
	// parity fingerprint even when it sits above the hero band.
	const largest = values[ values.length - 1 ];
	picked.add( largest );
	return [ ...picked ].sort( ( a, b ) => a - b );
}

// The spacing rhythm bands (jayainc: a 46-value margin/padding harvest).
// Same disease as the type scale: a bottom/top slice kept [0,1,2,3] +
// [101,104,125,130,140,150] — the actual layout rhythm (4-96px) vanished
// and space-4 baked as 104px. Bands, not positions.
const SPACE_BANDS = {
	xs: [ 2, 6 ],
	sm: [ 8, 12 ],
	md: [ 14, 20 ],
	lg: [ 24, 40 ],
	xl: [ 48, 96 ],
} as const;

function trimSpaceLadder( values: number[] ): number[] {
	if ( values.length <= 10 ) {
		return values;
	}
	const picked = new Set<number>();
	const hairline = firstInBand( values, 1, 3 );
	if ( hairline !== undefined ) {
		picked.add( hairline );
	}
	for ( const [ lo, hi ] of Object.values( SPACE_BANDS ) ) {
		const representative = centerOfBand( values, lo, hi );
		if ( representative !== undefined ) {
			picked.add( representative );
		}
	}
	picked.add( values[ values.length - 1 ] );
	return [ ...picked ].sort( ( a, b ) => a - b );
}

/** Elementor's untouched default primaries — when the var role is one of
 * these, the builder was never customized and the theme's own CTA color is
 * the brand's real accent (jayainc: --e-global-color-primary stays #6ec1e4
 * while every button is #fccd0f). */
const DEFAULT_BUILDER_PRIMARIES = new Set( [ '#6ec1e4', '#61ce70', '#4054b2' ] );

/**
 * The dominant button/CTA background color from the CSS — the color the
 * user actually sees on every call-to-action. Neutral/transparent values
 * are skipped; the most frequent saturated CTA color wins.
 */
function dominantCtaColor( cssText: string ): string | undefined {
	const counts = new Map<string, number>();
	for ( const rule of topLevelRules( cssText ) ) {
		const selector = rule.selector;
		if ( ! /button|\.btn\b|cta|aux-call|aux-ac|pricing/i.test( selector ) ) {
			continue;
		}
		const color = rule.body.match( BG_COLOR_RE );
		if ( ! color ) {
			continue;
		}
		const value = normalizeColor( color[ 1 ].trim() );
		if ( ! value || value === 'transparent' ) {
			continue;
		}
		const saturation = saturationOf( value );
		const lightness = lightnessOf( value );
		if ( saturation < 40 || lightness < 25 || lightness > 80 ) {
			continue;
		}
		counts.set( value, ( counts.get( value ) ?? 0 ) + 1 );
	}
	let best: string | undefined;
	let bestCount = 0;
	for ( const [ value, count ] of counts ) {
		if ( count > bestCount ) {
			best = value;
			bestCount = count;
		}
	}
	return best;
}

/** Keep the raw value when normalization would flatten it (gradients, and
 * rgba()/hsla() WITH alpha — e.g. the translucent navy footer bar); normalize
 * flat hex/named/rgb colors so palette math stays comparable. */
function bgForCss( value: string ): string | undefined {
	const trimmed = value.trim();
	if ( trimmed.includes( 'gradient' ) || /^(rgba|hsla)\(\s*[^)]+,\s*[\d.]+\s*\)\s*$/i.test( trimmed ) ) {
		return trimmed;
	}
	return normalizeColor( trimmed );
}

interface ResolvedElementStyle {
	/** The effective background (gradient ALWAYS beats flat color) — the
	 * cascade winner per property, raw where bgForCss keeps it raw. */
	fill?: string;
	/** The anchored `color:` cascade winner (text ink, alpha preserved). */
	ink?: string;
	padding?: string;
	radius?: string;
	weight?: string;
	size?: string;
	transform?: string;
	letterSpacing?: string;
}

interface ResolveElementOptions {
	/** When true, the FILL only considers rules whose selector ENDS on one of
	 * the element's own tokens (the rule styling the element itself, not a
	 * descendant rule that merely mentions it) — the header/footer strips
	 * resolve their own background this way (jayainc's `.site-header-section
	 * .aux-button` gradient must never leak onto the header). */
	terminalFill?: boolean;
}

/**
 * The specificity-approximated cascade shared by the CTA harvest and the
 * chrome typography pass: per property, the rule whose selector matches the
 * MOST of the element's tokens wins (`own * 1e6 + context * 1e8 + ruleIndex`,
 * ties by source order). Ancestor-context matches outweigh own matches —
 * scoped rules like `.site-header-section .aux-button` are the element's
 * real rules and must beat the generic `.aux-button`/size-ladder rules. The
 * frequency schemes this replaces let base rules out-vote the real
 * size/pill/ink rules. The FILL prefers gradients: a gradient
 * background-image is the effective paint over any flat background-color
 * (jayainc's yellow call button; flat hover/outline colors lose).
 * Pseudo-state rules (`:hover` etc.) are skipped — the rebuild targets the
 * resting design.
 */
function resolveElementRules( classes: string[], cssText: string, extra: string[] = [], options: ResolveElementOptions = {} ): ResolvedElementStyle {
	const out: ResolvedElementStyle = {};
	const tokens = [ ...classes, ...extra ].filter( Boolean );
	const own = classes.filter( Boolean );
	if ( tokens.length === 0 ) {
		return out;
	}
	interface Best {
		own: boolean;
		spec: number;
		important: boolean;
	}
	// Own-match precedence: a rule matching NONE of the element's own tokens
	// can only ever INHERIT (the header's `.aux-header-light { color: #fff }`
	// feeds the call button nothing — its real ink comes from a rule matching
	// its own classes). Any own-match candidate beats any context-only
	// candidate for the same property. `!important` beats everything except
	// another `!important` (the customizer's white-topbar rule outranks the
	// theme's yellow icon rules exactly as the browser honors it).
	const better = ( own: boolean, spec: number, important: boolean, best: Best | undefined ): boolean => {
		if ( ! best ) {
			return true;
		}
		if ( important && ! best.important ) {
			return true;
		}
		if ( ! important && best.important ) {
			return false;
		}
		if ( own && ! best.own ) {
			return true;
		}
		if ( ! own && best.own ) {
			return false;
		}
		return spec >= best.spec;
	};
	let gradient: string | undefined;
	let gradientBest: Best | undefined;
	let color: string | undefined;
	let colorBest: Best | undefined;
	let ink: string | undefined;
	let inkBest: Best | undefined;
	const specs: Record<Exclude<keyof ResolvedElementStyle, 'fill' | 'ink'>, { value?: string; best?: Best }> = {
		padding: {},
		radius: {},
		weight: {},
		size: {},
		transform: {},
		letterSpacing: {},
	};
	let ruleIndex = 0;
	for ( const rule of topLevelRules( cssText ) ) {
		const selector = rule.selector;
		// Pseudo-states AND state CLASSES skip — the rebuild targets the
		// resting design (jayainc's nav: an !important `.aux-hover` rule
		// would otherwise out-cascade the resting white).
		if ( /:(?:hover|focus|active|visited|disabled)|\b(?:aux-)?hover\b/i.test( selector ) ) {
			ruleIndex++;
			continue;
		}
		const matchCount = tokens.filter( ( token ) => selector.includes( token ) ).length;
		if ( matchCount === 0 ) {
			ruleIndex++;
			continue;
		}
		const ownMatches = own.filter( ( token ) => selector.includes( token ) ).length;
		const contextMatches = matchCount - ownMatches;
		const spec = ownMatches * 1000000 + contextMatches * 100000000 + ruleIndex;
		const hasOwn = ownMatches > 0;
		const body = rule.body;
		// Per-declaration !important: the flag belongs to the property, not
		// the whole rule (a rule may pin its background while its color is
		// a normal declaration).
		const imp = ( match: RegExpExecArray | null | undefined | RegExpMatchArray ): boolean => Boolean( match && match[ 2 ] );
		// The terminal rule (selector ENDS on an own token) styles the
		// element itself — the descendant rules below must never supply the
		// element-level FILL. Ink/typography still flow from descendant
		// rules (`.aux-top-header a { color: #fff }` IS the topbar's ink).
		const trimmed = selector.trim();
		const terminal = own.some( ( token ) => trimmed.endsWith( token ) );
		const fillEligible = ! options.terminalFill || terminal;
		if ( fillEligible ) {
			const gradientMatch = body.match( BG_GRADIENT_RE );
			if ( gradientMatch && better( hasOwn, spec, imp( gradientMatch ), gradientBest ) ) {
				gradient = gradientMatch[ 1 ].trim();
				gradientBest = { own: hasOwn, spec, important: imp( gradientMatch ) };
			}
			const colorMatch = body.match( BG_COLOR_RE );
			if ( colorMatch ) {
				const value = bgForCss( colorMatch[ 1 ].trim() );
				if ( value && value !== 'transparent' && better( hasOwn, spec, imp( colorMatch ), colorBest ) ) {
					color = value;
					colorBest = { own: hasOwn, spec, important: imp( colorMatch ) };
				}
			}
		}
		const inkMatch = body.match( /(?:^|;)\s*color\s*:\s*([^;!]+)(\s*!\s*important)?/i );
		if ( inkMatch ) {
			// Alpha-preserving: rgba(255,255,255,0.7) stays raw — the 70%
			// translucency IS the source's look (the copyright bar ink).
			const value = bgForCss( inkMatch[ 1 ].trim() );
			if ( value && value !== 'transparent' && better( hasOwn, spec, imp( inkMatch ), inkBest ) ) {
				ink = value;
				inkBest = { own: hasOwn, spec, important: imp( inkMatch ) };
			}
		}
		for ( const key of Object.keys( specs ) as Array<keyof typeof specs> ) {
			const regex = PROP_RE[ key ];
			const match = body.match( regex );
			const value = match ? match[ 1 ].trim() : undefined;
			if ( value && better( hasOwn, spec, imp( match ), specs[ key ].best ) ) {
				specs[ key ] = { value, best: { own: hasOwn, spec, important: imp( match ) } };
			}
		}
		ruleIndex++;
	}
	out.fill = gradient ?? color;
	if ( ink ) {
		out.ink = ink;
	}
	// Collapse symmetric 4-value paddings (14px 43px 14px 43px → 14px 43px).
	if ( specs.padding.value ) {
		specs.padding.value = specs.padding.value.replace( /^(\S+\s+\S+)\s+\1$/, '$1' );
	}
	for ( const key of Object.keys( specs ) as Array<keyof typeof specs> ) {
		if ( specs[ key ].value ) {
			out[ key ] = specs[ key ].value;
		}
	}
	return out;
}

const PROP_RE: Record<'padding' | 'radius' | 'weight' | 'size' | 'transform' | 'letterSpacing', RegExp> = {
	padding: /(?:^|;)\s*padding\s*:\s*([^;!]+)(\s*!\s*important)?/i,
	radius: /(?:^|;)\s*border-radius\s*:\s*([^;!]+)(\s*!\s*important)?/i,
	weight: /(?:^|;)\s*font-weight\s*:\s*([^;!]+)(\s*!\s*important)?/i,
	size: /(?:^|;)\s*font-size\s*:\s*([^;!]+)(\s*!\s*important)?/i,
	transform: /(?:^|;)\s*text-transform\s*:\s*([^;!]+)(\s*!\s*important)?/i,
	letterSpacing: /(?:^|;)\s*letter-spacing\s*:\s*([^;!]+)(\s*!\s*important)?/i,
};

/** The last body/html-selector rule's declaration for `prop` (cascade-
 * ordered — later rules win). The source's ACTUAL body font/size, as opposed
 * to the banded content scale (jayainc: `body { font-family: Rubik;
 * font-weight: 300 }` while its content widgets run 13px). */
function rootRuleFact( cssText: string, tag: 'body' | 'html', prop: string ): string | undefined {
	let best: string | undefined;
	let bestSpec = -1;
	let ruleIndex = 0;
	for ( const rule of topLevelRules( cssText ) ) {
		const selector = rule.selector.trim().toLowerCase();
		const isTarget =
			selector === tag ||
			selector.startsWith( tag + ' ' ) ||
			selector.startsWith( tag + '.' ) ||
			selector.startsWith( tag + '#' ) ||
			( tag === 'body' && selector.startsWith( 'html body' ) );
		if ( isTarget ) {
			const match = rule.body.match( new RegExp( '(?:^|;)\\s*' + prop + '\\s*:\\s*([^;!]+)', 'i' ) );
			const value = match ? match[ 1 ].trim() : '';
			// A var() reference carries no fact (the rebuilt token layer's
			// `body { font-family: var(--forge-font) }` must not pollute the
			// source analysis — and neither must it when scoring the built).
			if ( value && ! value.startsWith( 'var(' ) && ruleIndex >= bestSpec ) {
				best = value;
				bestSpec = ruleIndex;
			}
		}
		ruleIndex++;
	}
	return best;
}

function bodyRuleFact( cssText: string, prop: string ): string | undefined {
	return rootRuleFact( cssText, 'body', prop );
}

/** The h1-h6 family rule's declaration for `prop` — the source's heading
 * (display) font (jayainc: `h1, h2, h3, h4, h5, h6 { font-family: Rubik;
 * font-weight: 500 }` in the customizer sheet, cascade-ordered). */
function headingRuleFact( cssText: string, prop: string ): string | undefined {
	let best: string | undefined;
	let bestSpec = -1;
	let ruleIndex = 0;
	for ( const rule of topLevelRules( cssText ) ) {
		const selector = rule.selector.trim().toLowerCase();
		const isHeading = /^h1\b/.test( selector ) && /h2\b/.test( selector ) && /h3\b/.test( selector );
		if ( isHeading ) {
			const match = rule.body.match( new RegExp( '(?:^|;)\\s*' + prop + '\\s*:\\s*([^;!]+)', 'i' ) );
			const value = match ? match[ 1 ].trim() : '';
			if ( value && ! value.startsWith( 'var(' ) && ruleIndex >= bestSpec ) {
				best = value;
				bestSpec = ruleIndex;
			}
		}
		ruleIndex++;
	}
	return best;
}

/**
 * Resolve the source's CHROME font-size as a px fact. The body rule often
 * declares a relative value (`body { font-size: 100% }`) — relative to the
 * html root rule, not to the rebuilt content body (jayainc: 100% of 16px =
 * 16px chrome over a 13px content body).
 */
function chromeSizeFrom( cssText: string ): string | undefined {
	const htmlSize = rootRuleFact( cssText, 'html', 'font-size' );
	const bodySize = rootRuleFact( cssText, 'body', 'font-size' );
	const pxOf = ( value: string | undefined ): number | undefined => {
		const match = /^([\d.]+)px$/i.exec( ( value ?? '' ).trim() );
		return match ? Number( match[ 1 ] ) : undefined;
	};
	if ( bodySize ) {
		const px = pxOf( bodySize );
		if ( px !== undefined ) {
			return `${ px }px`;
		}
		const base = pxOf( htmlSize ) ?? 16;
		const pct = /^([\d.]+)%$/i.exec( bodySize.trim() );
		if ( pct ) {
			return `${ Math.round( ( Number( pct[ 1 ] ) / 100 ) * base ) }px`;
		}
		const em = /^([\d.]+)em$/i.exec( bodySize.trim() );
		if ( em ) {
			return `${ Math.round( Number( em[ 1 ] ) * base ) }px`;
		}
	}
	const htmlPx = pxOf( htmlSize );
	return htmlPx !== undefined ? `${ htmlPx }px` : undefined;
}

/**
 * The element-based CTA metrics: walk the rendered homepage for ACTUAL
 * button elements (button-ish classes), resolve each one's background,
 * padding and border-radius through the CSS, and return the values
 * covering the most elements. Element coverage beats rule frequency —
 * the source's every button wears `aux-mikado-yellow` (the yellow
 * gradient) while the accent-colored rules outnumber it in the raw CSS
 * (jayainc: rule-frequency picked the cyan accent for buttons).
 */
function dominantCtaFillFromHtml( input: DesignFetchInput ): { fill: string; padding?: string; radius?: string } | undefined {
	const handler = new DomHandler( undefined, {} );
	const parser = new Parser( handler, { lowerCaseTags: true, lowerCaseAttributeNames: true } );
	parser.write( input.html );
	parser.end();
	const root = handler.root as unknown as HNodeLike;
	const cssText = input.css.join( '\n' );
	const votes = new Map<string, number>();
	const padVotes = new Map<string, number>();
	const radiusVotes = new Map<string, number>();
	const bump = ( map: Map<string, number>, value: string ): void => {
		map.set( value, ( map.get( value ) ?? 0 ) + 1 );
	};
	const walk = ( node: HNodeLike ): void => {
		if ( node.type === 'tag' && ( node.name === 'a' || node.name === 'button' ) ) {
			const classes = tagClasses( node ).split( /\s+/ ).filter( Boolean );
			if ( ! classes.some( ( cls ) => /button|btn|cta|aux-ac|aux-call|pricing/i.test( cls ) ) ) {
				for ( const child of childNodes( node ) ) {
					walk( child );
				}
				return;
			}
			// The shared cascade resolution (specificity-approximated, gradient
			// fill always beats flat) — the cascade winner per element feeds the
			// votes directly, base-rule noise never reaches them.
			const resolved = resolveElementRules( classes, cssText );
			if ( resolved.fill ) {
				bump( votes, resolved.fill );
			}
			if ( resolved.padding ) {
				bump( padVotes, resolved.padding );
			}
			if ( resolved.radius ) {
				bump( radiusVotes, resolved.radius );
			}
		}
		for ( const child of childNodes( node ) ) {
			walk( child );
		}
	};
	walk( root );
	const top = ( map: Map<string, number> ): string | undefined => {
		let best: string | undefined;
		let bestCount = 0;
		for ( const [ value, count ] of map ) {
			if ( count > bestCount ) {
				best = value;
				bestCount = count;
			}
		}
		return best;
	};
	const fill = top( votes );
	if ( ! fill ) {
		return undefined;
	}
	return { fill, padding: top( padVotes ), radius: top( radiusVotes ) };
}

function componentVocabulary( html: string ): Record<string, number> {
	const vocabulary: Record<string, number> = {};
	const hints: Record<string, RegExp> = {
		header: /\b(?:site-)?header\b/i,
		footer: /\b(?:site-)?footer\b/i,
		button: /\bbtn\b|button/i,
		card: /\bcard\b/i,
		nav: /\bnav(?:bar|igation)?\b/i,
		badge: /\bbadge\b/i,
		form: /\bform\b|contact[-_]?form/i,
		input: /\binput\b|text[-_]?field/i,
		hero: /\bhero\b/i,
		feature: /\bfeature\b|icon[-_]?box/i,
		testimonial: /\btestimonial/i,
		gallery: /\bgallery\b/i,
	};
	const classes = html.match( /class=["']([^"']+)["']/gi ) ?? [];
	const joined = classes.join( ' ' );
	for ( const [ name, regex ] of Object.entries( hints ) ) {
		const matches = joined.match( new RegExp( regex.source, 'gi' ) ) ?? [];
		if ( matches.length > 0 ) {
			vocabulary[ name ] = matches.length;
		}
	}
	return vocabulary;
}

/**
 * Analyze fetched input into a `ForgeDesign` (static, deterministic). Role
 * assignment follows the fail-loud discipline: CSS-var-backed roles earn
 * high confidence, frequency/saturation heuristics medium, and everything
 * else lands in the unassigned pool.
 */
// ---------------------------------------------------------------------------
// Section-background pass (117 §2) — the per-band theming data.
//
// The design-rebuild applies ONE flat theme; real Elementor pages alternate
// dark photo heros, navy bands, and gray/white bands (jayainc's rhythm). The
// section pass recovers that: it walks the RAW rendered homepage for
// top-level Elementor sections (elementor-element-<id>), then pulls each
// id's background-image / background (gradient) / background-color / overlay /
// padding out of the same-origin CSS bodies (Elementor emits them as
// `.elementor-element-<id>` rules, never inline). The scaffold bakes the
// results into sections.generated.ts keyed by id — the classifier carries the
// SAME id on each rebuilt section, so the renderer re-applies the source
// band theming positionally without any markup copying.
// ---------------------------------------------------------------------------

type HNodeLike = {
	type?: string;
	name?: string;
	attribs?: Record<string, string>;
	children?: HNodeLike[];
	/** Source offset (htmlparser2 withStartIndices) — position scoping. */
	startIndex?: number;
};

const RULE_RE = /([^{}]+)\{([^{}]*)\}/g;

/**
 * Split CSS into TOP-LEVEL rules (comments and quoted strings inert, parens
 * tracked, nested blocks counted by depth). The flat RULE_RE can desync on
 * `@media`/`@supports` blocks AND on braces inside url(data:…) payloads —
 * both silently swallow the rules that follow them (jayainc: an svg data
 * URI in an early sheet ate every later `body { font-family }` rule and the
 * customizer's navy-header overrides). Media bodies keep their nested text
 * — element-token matching never hits a media selector, so the inner rules
 * stay inert.
 */
function topLevelRules( cssText: string ): Array<{ selector: string; body: string }> {
	const rules: Array<{ selector: string; body: string }> = [];
	let depth = 0;
	let paren = 0;
	let selector = '';
	let body = '';
	let inComment = false;
	let inString: string | undefined;
	for ( let i = 0; i < cssText.length; i++ ) {
		const c = cssText[ i ];
		const next = cssText[ i + 1 ];
		if ( inComment ) {
			if ( c === '*' && next === '/' ) {
				inComment = false;
				i++;
			}
			continue;
		}
		if ( c === '/' && next === '*' ) {
			inComment = true;
			i++;
			continue;
		}
		if ( inString ) {
			// Strings are inert STRUCTURALLY, but their bytes accumulate (the
			// property regexes read them: `font-family: "Rubik"`).
			if ( c === '\\' && next ) {
				if ( depth === 0 ) {
					selector += c + next;
				} else {
					body += c + next;
				}
				i++;
				continue;
			}
			if ( c === inString ) {
				inString = undefined;
			}
		} else if ( paren === 0 && ( c === '"' || c === "'" ) ) {
			// Quotes only open strings OUTSIDE url(...) — unquoted data URIs
			// carry apostrophes that must never start a string (jayainc's svg
			// payloads swallowed every rule after them).
			inString = c;
		} else {
			if ( c === '(' ) {
				paren++;
			}
			if ( c === ')' ) {
				paren = Math.max( 0, paren - 1 );
			}
			// Braces inside parens (url(data:…) payloads) are value bytes,
			// not structure.
			if ( c === '{' && paren === 0 ) {
				if ( depth === 0 ) {
					selector = selector.trim();
					body = '';
					depth = 1;
				} else {
					depth++;
					body += c;
				}
				continue;
			}
			if ( c === '}' && paren === 0 ) {
				depth--;
				if ( depth === 0 ) {
					rules.push( { selector, body } );
					selector = '';
				} else {
					body += c;
				}
				continue;
			}
		}
		if ( depth === 0 ) {
			selector += c;
		} else {
			body += c;
		}
	}
	return rules;
}
const BG_GRADIENT_RE = /background-image\s*:\s*((?:repeating-)?(?:linear|radial|conic)-gradient\([^;]+)(\s*!\s*important)?/i;
const BG_IMAGE_RE = /background-image\s*:\s*url\(["']?([^"')]+)["']?\)/i;
const BG_COLOR_RE = /background-color\s*:\s*([^;!]+)(\s*!\s*important)?/i;
const PADDING_RE = /padding\s*:\s*([^;!]+)/i;
const SECTION_ID_RE = /elementor-element-([0-9a-f]{4,})/;

/** One top-level column's responsive facts (124 §6) — Elementor's per-device
 * width/order/visibility classes. Undefined when the column carries none. */
function columnFactsOf( node: HNodeLike ): ForgeSectionColumn | undefined {
	const classes = sectionClasses( node );
	const facts: ForgeSectionColumn = {};
	const width = classes.map( ( c ) => /^elementor-col-(\d+)$/.exec( c ) ).find( Boolean );
	if ( width ) {
		facts.width = parseInt( width[ 1 ], 10 );
	}
	const tablet = classes.map( ( c ) => /^elementor-md-(\d+)$/.exec( c ) ).find( Boolean );
	if ( tablet ) {
		facts.widthTablet = parseInt( tablet[ 1 ], 10 );
	}
	const mobile = classes.map( ( c ) => /^elementor-sm-(\d+)$/.exec( c ) ).find( Boolean );
	if ( mobile ) {
		facts.widthMobile = parseInt( mobile[ 1 ], 10 );
	}
	if ( classes.includes( 'elementor-reverse-mobile' ) || classes.includes( 'elementor-reverse-phone' ) ) {
		facts.reverseMobile = true;
	}
	if ( classes.includes( 'elementor-reverse-tablet' ) ) {
		facts.reverseTablet = true;
	}
	if ( classes.includes( 'elementor-hidden-mobile' ) || classes.includes( 'elementor-hidden-phone' ) ) {
		facts.hiddenMobile = true;
	}
	if ( classes.includes( 'elementor-hidden-tablet' ) ) {
		facts.hiddenTablet = true;
	}
	if ( classes.includes( 'elementor-hidden-desktop' ) ) {
		facts.hiddenDesktop = true;
	}
	return Object.keys( facts ).length > 0 ? facts : undefined;
}

/** Elementor's column-gap class → the side padding it adds to the column
 * (the container's inner gutter — `gap-wider` = 30px, the default 10px). */
const ELEMENTOR_COLUMN_GAPS: Record<string, string> = {
	narrow: '5px',
	default: '10px',
	extended: '15px',
	wide: '20px',
	wider: '30px',
};

/** The Elementor boxed-container default when the stylesheets carry no
 * explicit rule (frontend.min.css: `.elementor-section.elementor-section-
 * boxed > .elementor-container { max-width: 1140px }`). */
const ELEMENTOR_BOXED_MEASURE = '1140px';

function childNodes( node: HNodeLike | undefined ): HNodeLike[] {
	return node?.children ?? [];
}

function sectionClasses( node: HNodeLike ): string[] {
	return ( node.attribs?.class ?? '' ).split( /\s+/ ).filter( Boolean );
}

/**
 * The device buckets the template renders — its own breakpoints (1025 desktop,
 * 768 tablet). Elementor scopes a rule to a device RANGE with max-width, so the
 * same property can carry three different values.
 */
type DeviceBucket = 'desktop' | 'tablet' | 'mobile';

/** Map an `@media` condition onto the template's bucket, or undefined if it is
 * outside the ranges the rebuild renders. */
function mediaBucket( media: string ): DeviceBucket | undefined {
	const max = /max-width\s*:\s*(\d+(?:\.\d+)?)px/i.exec( media );
	if ( max ) {
		const width = Number( max[ 1 ] );
		if ( width <= 767 ) {
			return 'mobile';
		}
		if ( width <= 1024 ) {
			return 'tablet';
		}
		return undefined;
	}
	const min = /min-width\s*:\s*(\d+(?:\.\d+)?)px/i.exec( media );
	if ( min ) {
		const width = Number( min[ 1 ] );
		if ( width >= 1025 ) {
			return 'desktop';
		}
		if ( width >= 768 ) {
			return 'tablet';
		}
	}
	return undefined;
}

/**
 * Every rule, flattened with the media condition that guards it. `topLevelRules`
 * keeps a media body as inert nested text (so element-token matching cannot hit
 * a media selector); this recursion is what makes those rules readable.
 */
function rulesInMedia( cssText: string ): Array<{ selector: string; body: string; media?: string }> {
	const out: Array<{ selector: string; body: string; media?: string }> = [];
	for ( const rule of topLevelRules( cssText ) ) {
		const selector = rule.selector.trim();
		if ( ! selector.startsWith( '@media' ) ) {
			out.push( { selector, body: rule.body } );
			continue;
		}
		for ( const inner of topLevelRules( rule.body ) ) {
			out.push( { selector: inner.selector.trim(), body: inner.body, media: selector } );
		}
	}
	return out;
}

/**
 * A property the source scopes per device. Returns a device map ONLY when the
 * scoped declarations actually differ — a single value stays a string, so the
 * common case is unchanged.
 *
 * This is the class of fact the flat "first top-level match wins" read loses: a
 * band declared flush on desktop but pulled on tablet/mobile read as "no pull"
 * (or as whichever single value an operator then pinned by hand).
 */
function responsiveLength(
	rules: Array<{ selector: string; body: string; media?: string }>,
	token: string,
	property: string
): ResponsiveLength | undefined {
	const pattern = new RegExp( `${ property }\\s*:\\s*([^;!]+)`, 'i' );
	const values: Partial< Record< DeviceBucket, string > > = {};
	let sawMedia = false;
	for ( const rule of rules ) {
		if ( ! rule.selector.includes( token ) ) {
			continue;
		}
		const match = pattern.exec( rule.body );
		if ( ! match ) {
			continue;
		}
		const value = match[ 1 ].trim();
		if ( ! value ) {
			continue;
		}
		if ( rule.media ) {
			const bucket = mediaBucket( rule.media );
			if ( ! bucket ) {
				continue;
			}
			sawMedia = true;
			values[ bucket ] = value;
			continue;
		}
		if ( ! values.desktop ) {
			values.desktop = value;
		}
	}
	if ( ! sawMedia ) {
		return undefined;
	}
	const declared = [ values.desktop, values.tablet, values.mobile ].filter( Boolean ) as string[];
	if ( declared.length === 0 ) {
		return undefined;
	}
	// A media rule that merely repeats the desktop value is not a scope.
	// (A scope with no desktop value at all — tablet-only — still is.)
	const repeatsDesktop = values.desktop !== undefined && declared.every( ( value ) => value === values.desktop );
	return repeatsDesktop ? undefined : values;
}

function allSectionNodes( node: HNodeLike, out: HNodeLike[] ): void {
	for ( const child of childNodes( node ) ) {
		// TOP-level sections only (124 §6) — inner sections are a column's
		// content, never a band; their elementor-element ids must not leak
		// into the band facts (an inner section's margin is a widget-level
		// spacing fact, not a band pull).
		if ( child.type === 'tag' && sectionClasses( child ).some( ( c ) => c === 'elementor-top-section' ) ) {
			out.push( child );
		} else {
			allSectionNodes( child, out );
		}
	}
}

function inlineStyle( node: HNodeLike, name: string, regex: RegExp ): string | undefined {
	const style = node.attribs?.style;
	if ( ! style ) {
		return undefined;
	}
	const parts = style.split( ';' ).map( ( part ) => part.trim() ).filter( Boolean );
	for ( const part of parts ) {
		if ( part.toLowerCase().startsWith( name ) ) {
			const match = regex.exec( part );
			if ( match ) {
				return match[ 1 ].trim();
			}
		}
	}
	return undefined;
}

/**
 * Extract the ordered source-section backgrounds from the fetched homepage
 * + stylesheets. Deterministic; an empty html yields an empty list.
 */
export function analyzeSections( input: DesignFetchInput ): ForgeSection[] {
	const handler = new DomHandler( undefined, {} );
	const parser = new Parser( handler, { lowerCaseTags: true, lowerCaseAttributeNames: true } );
	parser.write( input.html );
	parser.end();

	const sections: HNodeLike[] = [];
	allSectionNodes( handler.root as unknown as HNodeLike, sections );

	const cssText = input.css.join( '\n' );
	// Every rule with the media condition guarding it — the per-section pass
	// below reads top-level rules only, so device-scoped overrides were invisible.
	const scopedRules = rulesInMedia( cssText );
	const facts: ForgeSection[] = [];
	for ( const section of sections ) {
		const classes = sectionClasses( section );
		const idMatch = classes.map( ( c ) => SECTION_ID_RE.exec( c ) ).find( Boolean );
		if ( ! idMatch ) {
			continue;
		}
		const id = idMatch[ 1 ];
		const fact: ForgeSection = { id };
		// The section's own HTML id is the source anchor (#about, #contact…)
		// the chrome nav links to — the renderer re-applies it per section.
		if ( section.attribs?.id ) {
			fact.anchor = section.attribs.id;
		}
		// A nav anchor can sit on an INNER section instead of the band
		// (125 §6 — jayainc's #products lives on the inner section inside
		// the products band): adopt the first descendant id that reads as
		// a nav target (plain lowercase slug, not an internal/auto id).
		if ( ! fact.anchor ) {
			const stack: HNodeLike[] = [ section ];
			let adopted: string | undefined;
			while ( stack.length > 0 && ! adopted ) {
				const current = stack.pop() as HNodeLike;
				for ( const child of childNodes( current ) ) {
					const candidate = child.attribs?.id;
					if ( candidate && /^[a-z][a-z0-9-]{1,31}$/.test( candidate ) && ! /^(?:elementor|aux-|df_|nvoos)/.test( candidate ) ) {
						adopted = candidate;
						break;
					}
					stack.push( child );
				}
			}
			if ( adopted ) {
				fact.anchor = adopted;
			}
		}
		// Inline styles (rare for Elementor, but cheap to honor).
		const inlineGradient = inlineStyle( section, 'background-image', BG_GRADIENT_RE );
		if ( inlineGradient ) {
			fact.bgGradient = inlineGradient;
		} else {
			const inlineImage = inlineStyle( section, 'background-image', BG_IMAGE_RE );
			if ( inlineImage ) {
				fact.bgImage = inlineImage;
			}
		}
		const inlineColor = inlineStyle( section, 'background-color', BG_COLOR_RE );
		if ( inlineColor ) {
			fact.bgColor = inlineColor;
		}
		// Same-origin CSS rules scoped to this section's id.
		for ( const rule of topLevelRules( cssText ) ) {
			const selector = rule.selector;
			const body = rule.body;
			if ( ! selector.includes( `elementor-element-${ id }` ) ) {
				continue;
			}
			const gradient = body.match( BG_GRADIENT_RE );
			// A photo beats a gradient: Elementor layers decoration gradients on
			// child/overlay selectors of the same id — the section's own photo
			// must never be cleared by one (jayainc's hero).
			if ( gradient && ! fact.bgGradient && ! fact.bgImage ) {
				fact.bgGradient = gradient[ 1 ].trim();
			}
			const image = body.match( BG_IMAGE_RE );
			if ( image && ! fact.bgImage ) {
				fact.bgImage = image[ 1 ].trim();
			}
			const color = body.match( BG_COLOR_RE );
			// The overlay rule's color belongs to the overlay layer — it must
			// never become the band's own background color.
			if ( selector.includes( 'background-overlay' ) ) {
				if ( color && ! fact.overlay ) {
					fact.overlay = color[ 1 ].trim();
				}
			} else if ( color && ! fact.bgColor ) {
				fact.bgColor = color[ 1 ].trim();
			}
			const padding = body.match( PADDING_RE );
			if ( padding && ! fact.padding ) {
				fact.padding = padding[ 1 ].trim();
			}
			// Section-level pull/overlap facts (124 §4): negative margins are
			// the source pulling a band up/down over its neighbor; z-index
			// is the stacking cue that makes the overlap render.
			const marginTop = body.match( /margin-top\s*:\s*([^;!]+)/i );
			if ( marginTop && ! fact.marginTop ) {
				const value = marginTop[ 1 ].trim();
				if ( value && ! /^0(?:px|%|em|rem)?$/.test( value ) ) {
					fact.marginTop = value;
				}
			}
			const marginBottom = body.match( /margin-bottom\s*:\s*([^;!]+)/i );
			if ( marginBottom && ! fact.marginBottom ) {
				const value = marginBottom[ 1 ].trim();
				if ( value && ! /^0(?:px|%|em|rem)?$/.test( value ) ) {
					fact.marginBottom = value;
				}
			}
			const zIndex = body.match( /z-index\s*:\s*([^;!]+)/i );
			if ( zIndex && ! fact.zIndex ) {
				fact.zIndex = zIndex[ 1 ].trim();
			}
		}
		// Device-scoped pulls (Elementor's responsive cascade): a band can be
		// flush on desktop and pulled on tablet/mobile. Capture the map instead
		// of one value — the flat read above cannot see inside `@media`.
		const scopedToken = `elementor-element-${ id }`;
		const scopedMarginTop = responsiveLength( scopedRules, scopedToken, 'margin-top' );
		if ( scopedMarginTop ) {
			fact.marginTop = scopedMarginTop;
		}
		const scopedMarginBottom = responsiveLength( scopedRules, scopedToken, 'margin-bottom' );
		if ( scopedMarginBottom ) {
			fact.marginBottom = scopedMarginBottom;
		}
		// The top-level column responsive facts (124 §6) — the container's
		// DIRECT column children (inner-section columns nest deeper and are
		// not this band's grid). Widths + per-device order/visibility are
		// the rebuild's relayout input across device types.
		const container = childNodes( section ).find(
			( child ) => child.type === 'tag' && sectionClasses( child ).includes( 'elementor-container' )
		);
		if ( container ) {
			const columns = childNodes( container )
				.filter( ( child ) => child.type === 'tag' && sectionClasses( child ).includes( 'elementor-column' ) )
				.map( ( column ) => columnFactsOf( column ) )
				.filter( ( column ) => column !== undefined );
			if ( columns.length >= 2 || columns.some( ( column ) => column.widthTablet || column.widthMobile || column.reverseMobile || column.reverseTablet || column.hiddenMobile || column.hiddenTablet || column.hiddenDesktop ) ) {
				fact.columns = columns;
			}
		}
		// The boxed container's measure + column gap (124 §3): boxed sections
		// constrain their CONTENT (the band bg stays full-width) — the rebuilt
		// band's inner column must re-measure to the source's container width
		// (jayainc: 1140px + the gap-wider 30px gutter), never the site measure.
		if ( classes.includes( 'elementor-section-boxed' ) ) {
			fact.measure = ELEMENTOR_BOXED_MEASURE;
			// Resolve the effective container width: the theme's canonical
			// --container-max-width declaration (Elementor/Phlox emit it per
			// breakpoint; jayainc: 1140/1024/767 — the desktop value is the
			// boxed truth) wins. Only when absent, fall back to the
			// stylesheet ladder — a numeric max-width on a .elementor-container
			// rule, boxed-scoped rules over generic ones. A raw sheet rule can
			// belong to a different template (jayainc's sheets carry a 1900px
			// one the live site never renders) — the var is the ground truth.
			let resolved: string | undefined;
			const containerVar = cssText.match( /--container-max-width\s*:\s*(\d+(?:\.\d+)?(?:px|rem|em|vw|%))/ );
			if ( containerVar ) {
				resolved = containerVar[ 1 ];
			}
			if ( ! resolved ) {
				for ( const rule of topLevelRules( cssText ) ) {
					if ( ! /\.elementor-container/.test( rule.selector ) ) {
						continue;
					}
					const width = rule.body.match( /max-width\s*:\s*(\d+(?:\.\d+)?(?:px|rem|em|vw|%))/i );
					if ( ! width ) {
						continue;
					}
					if ( /section-boxed/.test( rule.selector ) ) {
						resolved = width[ 1 ];
						break;
					}
					if ( ! resolved ) {
						resolved = width[ 1 ];
					}
				}
			}
			if ( resolved ) {
				fact.measure = resolved;
			}
			const container = childNodes( section ).find(
				( child ) => child.type === 'tag' && sectionClasses( child ).includes( 'elementor-container' )
			);
			const gapClass = container
				? sectionClasses( container ).map( ( c ) => /^elementor-column-gap-(\w+)$/.exec( c ) ).find( Boolean )
				: undefined;
			const gap = gapClass ? ELEMENTOR_COLUMN_GAPS[ gapClass[ 1 ] ] : undefined;
			if ( gap ) {
				fact.paddingX = gap;
			}
		}
		// ---- 125 §1: the split-hero layout + collage/typography facts ----
		// The source's two-column hero bands (a copy column beside a media
		// collage column) rebuild as a split hero — jayainc's about band.
		// Widget ids are collected from the DOM because Elementor scopes the
		// widget VALUE rules to the widget id, never the section id.
		const hasWidget = ( node: HNodeLike, pattern: RegExp ): boolean => {
			const stack: HNodeLike[] = [ node ];
			while ( stack.length > 0 ) {
				const current = stack.pop() as HNodeLike;
				for ( const child of childNodes( current ) ) {
					if ( child.type === 'tag' && sectionClasses( child ).some( ( cls ) => pattern.test( cls ) ) ) {
						return true;
					}
					stack.push( child );
				}
			}
			return false;
		};
		const collectWidgetIds = ( node: HNodeLike, pattern: RegExp, out: string[] ): void => {
			for ( const child of childNodes( node ) ) {
				if ( child.type === 'tag' && sectionClasses( child ).some( ( cls ) => pattern.test( cls ) ) ) {
					const widgetId = elementIdOf( tagClasses( child ) );
					if ( widgetId && ! out.includes( widgetId ) ) {
						out.push( widgetId );
					}
				}
				collectWidgetIds( child, pattern, out );
			}
		};
		const COPY_WIDGET_RE = /^elementor-widget-(?:heading|text-editor|button|aux_modern_heading|aux_button|aux_text)$/;
		const MEDIA_WIDGET_RE = /^elementor-widget-(?:aux_image|image)$/;
		const splitContainer = childNodes( section ).find(
			( child ) => child.type === 'tag' && sectionClasses( child ).includes( 'elementor-container' )
		);
		const domColumns = splitContainer
			? childNodes( splitContainer ).filter( ( child ) => child.type === 'tag' && sectionClasses( child ).includes( 'elementor-column' ) )
			: [];
		const copyColumn = domColumns.find( ( column ) => hasWidget( column, COPY_WIDGET_RE ) && ! hasWidget( column, MEDIA_WIDGET_RE ) && ! hasWidget( column, /aux-simple-shape/ ) );
		const mediaColumn = domColumns.find( ( column ) => ( hasWidget( column, MEDIA_WIDGET_RE ) || hasWidget( column, /aux-simple-shape/ ) ) && ! hasWidget( column, COPY_WIDGET_RE ) );
		const hasImageSrc = ( node: HNodeLike ): boolean => {
			const stack: HNodeLike[] = [ node ];
			while ( stack.length > 0 ) {
				const current = stack.pop() as HNodeLike;
				for ( const child of childNodes( current ) ) {
					if ( child.type === 'tag' && child.name === 'img' && child.attribs?.src ) {
						return true;
					}
					stack.push( child );
				}
			}
			return false;
		};
		const mediaColumnReal = mediaColumn && ( hasImageSrc( mediaColumn ) || hasWidget( mediaColumn, /aux-simple-shape/ ) );
		// A photo-background hero never splits: the band itself IS the media
		// (jayainc's hero carries an empty aux_image shell in its second
		// column — a builder leftover, not content).
		if ( ! fact.bgImage && domColumns.length === 2 && copyColumn && mediaColumnReal ) {
			fact.layout = 'split';
			// The body paragraphs sit in sub-columns (jayainc: two side-by-side).
			const innerSection = ( () => {
				const stack: HNodeLike[] = [ copyColumn ];
				while ( stack.length > 0 ) {
					const current = stack.pop() as HNodeLike;
					for ( const child of childNodes( current ) ) {
						if ( child.type === 'tag' && sectionClasses( child ).includes( 'elementor-inner-section' ) ) {
							return child;
						}
						stack.push( child );
					}
				}
				return undefined;
			} )();
			if ( innerSection ) {
				const innerContainer = childNodes( innerSection ).find(
					( child ) => child.type === 'tag' && sectionClasses( child ).includes( 'elementor-container' )
				);
				const innerColumns = innerContainer
					? childNodes( innerContainer ).filter(
							( child ) => child.type === 'tag' && sectionClasses( child ).includes( 'elementor-column' )
					  )
					: [];
				if ( innerColumns.length >= 2 ) {
					fact.bodyColumns = innerColumns.length;
				}
			}
		}
		const headingIds: string[] = [];
		const shapeIds: string[] = [];
		const imageIds: string[] = [];
		collectWidgetIds( section, /^elementor-widget-(?:aux_modern_heading|heading)$/, headingIds );
		collectWidgetIds( section, /^elementor-widget-(?:aux_simple_shape|simple_shape)$/, shapeIds );
		collectWidgetIds( section, MEDIA_WIDGET_RE, imageIds );
		// The heading facts (125 §1/§7) apply to any band with a modern-
		// heading widget — the split hero's copy column AND single-column
		// heading bands (jayainc's centered "Other Featured Products" +
		// the hero's own 72px title + divider).
		if ( fact.layout === 'split' || headingIds.length > 0 ) {
			for ( const rule of topLevelRules( cssText ) ) {
				const scopedToSection = rule.selector.includes( `elementor-element-${ id }` );
				const scopedToWidget =
					headingIds.some( ( widgetId ) => rule.selector.includes( `elementor-element-${ widgetId }` ) ) ||
					shapeIds.some( ( widgetId ) => rule.selector.includes( `elementor-element-${ widgetId }` ) ) ||
					imageIds.some( ( widgetId ) => rule.selector.includes( `elementor-element-${ widgetId }` ) );
				if ( ! scopedToSection && ! scopedToWidget ) {
					continue;
				}
				const body = rule.body;
				if ( ! fact.titleDivider && /\.aux-modern-heading-divider/.test( rule.selector ) ) {
					const width = body.match( /width\s*:\s*([^;!]+)/i );
					const height = body.match( /height\s*:\s*([^;!]+)/i );
					const background = body.match( /background-color\s*:\s*([^;!]+)/i );
					const shadow = body.match( /box-shadow\s*:\s*([^;!]+)/i );
					if ( width && height && background ) {
						fact.titleDivider = {
							width: width[ 1 ].trim(),
							height: height[ 1 ].trim(),
							background: background[ 1 ].trim(),
							shadow: shadow?.[ 1 ]?.trim(),
						};
					}
				}
				if ( ! fact.titleAlign && /\.aux-widget-inner/.test( rule.selector ) && /text-align\s*:\s*center/i.test( body ) ) {
					fact.titleAlign = 'center';
				}
				if ( ! fact.titleStyle && /\.aux-modern-heading-primary/.test( rule.selector ) ) {
					const size = body.match( /font-size\s*:\s*([^;!]+)/i );
					const weight = body.match( /font-weight\s*:\s*([^;!]+)/i );
					const lineHeight = body.match( /line-height\s*:\s*([^;!]+)/i );
					const letterSpacing = body.match( /letter-spacing\s*:\s*([^;!]+)/i );
					if ( size || weight || lineHeight || letterSpacing ) {
						fact.titleStyle = {
							size: size?.[ 1 ]?.trim(),
							weight: weight?.[ 1 ]?.trim(),
							lineHeight: lineHeight?.[ 1 ]?.trim(),
							letterSpacing: letterSpacing?.[ 1 ]?.trim(),
						};
					}
				}
				if ( ! fact.mediaShape && /\.aux-the-shape/.test( rule.selector ) ) {
					const width = body.match( /width\s*:\s*([^;!]+)/i );
					const height = body.match( /height\s*:\s*([^;!]+)/i );
					const gradient = body.match( BG_GRADIENT_RE );
					const color = body.match( BG_COLOR_RE );
					const radius = body.match( /border-radius\s*:\s*([^;!]+)/i );
					if ( width && height && ( gradient || color ) ) {
						fact.mediaShape = {
							width: width[ 1 ].trim(),
							height: height[ 1 ].trim(),
							background: ( gradient ?? color )?.[ 1 ]?.trim() ?? '',
							radius: radius?.[ 1 ]?.trim(),
						};
					}
				}
				for ( const widgetId of imageIds ) {
					if ( ! rule.selector.includes( `elementor-element-${ widgetId }` ) ) {
						continue;
					}
					if ( ! fact.mediaOffsets ) {
						fact.mediaOffsets = imageIds.map( () => ( {} ) );
					}
					const entry = fact.mediaOffsets[ imageIds.indexOf( widgetId ) ];
					if ( /\.aux-media-image/.test( rule.selector ) ) {
						const shadow = body.match( /box-shadow\s*:\s*([^;!]+)/i );
						if ( shadow && ! entry.shadow ) {
							entry.shadow = shadow[ 1 ].trim();
						}
					} else if ( /position\s*:\s*absolute/i.test( body ) ) {
						const top = body.match( /top\s*:\s*([^;!]+)/i );
						const right = body.match( /right\s*:\s*([^;!]+)/i );
						const bottom = body.match( /bottom\s*:\s*([^;!]+)/i );
						const left = body.match( /left\s*:\s*([^;!]+)/i );
						if ( top ) {
							entry.top = top[ 1 ].trim();
						}
						if ( right ) {
							entry.right = right[ 1 ].trim();
						}
						if ( bottom ) {
							entry.bottom = bottom[ 1 ].trim();
						}
						if ( left ) {
							entry.left = left[ 1 ].trim();
						}
					}
				}
			}
			// Drop the offsets list when nothing is absolutely positioned.
			if ( fact.mediaOffsets && ! fact.mediaOffsets.some( ( entry ) => entry.top || entry.right || entry.bottom || entry.left ) ) {
				delete fact.mediaOffsets;
			}
		}
		// ---- 125 §4: the flipbook catalog (the dflip shortcode) ----
		// The catalog's PDF source lives ONLY in the widget's script — the
		// sanitizer strips scripts, so the classifier never sees it. This raw
		// pass captures it as a band fact and the rebuild renders a titled
		// open-catalogue CTA (jayainc's FIORI catalog below the logos band).
		const dfBook = ( () => {
			const stack: HNodeLike[] = [ section ];
			while ( stack.length > 0 ) {
				const current = stack.pop() as HNodeLike;
				for ( const child of childNodes( current ) ) {
					if ( child.type === 'tag' && sectionClasses( child ).some( ( cls ) => /^_?df_book$/.test( cls ) || cls === 'df-lite' ) ) {
						return child;
					}
					stack.push( child );
				}
			}
			return undefined;
		} )();
		if ( dfBook ) {
			let source: string | undefined;
			const stack: HNodeLike[] = [ section ];
			while ( stack.length > 0 && ! source ) {
				const current = stack.pop() as HNodeLike;
				for ( const child of childNodes( current ) ) {
					// htmlparser2 v12 emits script/style nodes with type 'script'/
					// 'style' (not 'tag') — match on the name, not the type.
					if ( child.name === 'script' && sectionClasses( child ).includes( 'df-shortcode-script' ) ) {
						const text = ( child.children ?? [] ).map( ( node ) => ( node as { data?: string } ).data ?? '' ).join( '' );
						const payload = /window\.option_df_\d+\s*=\s*(\{[\s\S]*?\})\s*;/.exec( text );
						if ( payload ) {
							try {
								const parsed = JSON.parse( payload[ 1 ] ) as { source?: string };
								if ( parsed.source ) {
									source = parsed.source;
								}
							} catch {
								// Malformed payload — the CTA stays absent.
							}
						}
					}
					stack.push( child );
				}
			}
			if ( source ) {
				const title = dfBook.attribs?.[ 'data-title' ];
				fact.catalog = { source, ...( title ? { title } : {} ) };
			}
		}
		// A background-color alongside a photo is an UNDERLAY — Elementor
		// paints it beneath the (opaque) image, so the source renders it
		// invisibly (jayainc's hero: rgba(8,26,67,0.9) under jaya-3-1.jpg).
		// Storing it as the band color makes the rebuilt hero paint the tint
		// OVER the photo — the opposite of the source. Drop it.
		if ( fact.bgImage ) {
			delete fact.bgColor;
		}
		if ( fact.bgImage || fact.bgGradient || fact.bgColor || fact.overlay || fact.measure || fact.paddingX || fact.marginTop || fact.marginBottom || fact.zIndex || fact.columns || fact.layout || fact.titleDivider || fact.titleStyle || fact.titleAlign || fact.mediaShape || fact.mediaOffsets || fact.bodyColumns || fact.catalog ) {
			facts.push( fact );
		}
	}
	// Vendor-agnostic fallback (Proposal 137). The pass above understands ONLY
	// Elementor: a WPBakery/Divi/Bricks/Gutenberg/Flatsome/plain-HTML site
	// yielded zero bands, losing its design structure entirely
	// (virtuallyjamaica.com, 2026-11-09 — WPBakery + Brando). Those bands go to
	// `design.outline`, NOT to `sections`: `sections` drives rendering and the
	// band-flow gate would (correctly) fail for bands the renderer cannot emit.

	return facts;
}

/**
 * The band outline for builders the rich Elementor extractor does not cover.
 * Returns `{}` when Elementor produced sections (they are authoritative) or
 * when no bands were detected — keeping the profile clean.
 */
export function bandOutline( input: DesignFetchInput ): {
	outline?: import( './bands.ts' ).BandCandidate[];
	builder?: string;
} {
	const detected = extractBandsFromHtml( input.html );
	if ( detected.bands.length === 0 ) {
		return {};
	}
	// `builder` is the tech that was fingerprinted; `bands[].via` is the layer
	// that produced the boundaries (they differ when a theme rewrites the
	// builder's row wrapper away — Brando keeps wpb_column, drops vc_row).
	return { outline: detected.bands, builder: detected.builder ?? detected.adapter };
}

/**
 * Promote outline bands into the renderable band model. The two band shapes
 * carry the same background facts, so the mapping is a field copy — the
 * renderer then themes them positionally (`bandSource: 'outline'`).
 */
export function promoteOutlineBands( bands?: BandCandidate[] ): ForgeSection[] {
	return ( bands ?? [] ).map( ( band ) => ( {
		id: band.id,
		anchor: band.anchor,
		bgColor: band.bgColor,
		bgImage: band.bgImage,
		bgGradient: band.bgGradient,
		measure: band.measure,
		paddingX: band.paddingX,
		columns: band.columns?.map( ( column ) => ( { width: column.width } ) ),
	} ) );
}

/**
 * The bands a build can render: the Elementor sections when present, else the
 * promoted outline. Profiles analyzed before the promotion still carry
 * `sections: []` + an outline, so every consumer goes through this.
 */
export function renderableBands(
	design?: { sections?: ForgeSection[]; outline?: BandCandidate[] } | null
): ForgeSection[] {
	if ( design?.sections && design.sections.length > 0 ) {
		return design.sections;
	}
	return promoteOutlineBands( design?.outline );
}

/** The last color value of a gradient expression (the band's dominant end). */
function lastColorOf( gradient: string | undefined ): string | undefined {
	if ( ! gradient ) {
		return undefined;
	}
	const colors = gradient.match( /(?:rgba?|hsla?)\([^)]+\)|#[0-9a-f]{3,8}\b/gi );
	return colors?.[ colors.length - 1 ];
}

/** Normalize an rgb()/hex color to #rrggbb for the luminance math. */
function toHex( color: string | undefined ): string | undefined {
	if ( ! color ) {
		return undefined;
	}
	const rgb = /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i.exec( color );
	if ( rgb ) {
		return '#' + [ 1, 2, 3 ].map( ( i ) => ( +rgb[ i ] ).toString( 16 ).padStart( 2, '0' ) ).join( '' );
	}
	return /^#[0-9a-f]{3,8}$/i.test( color.trim() ) ? color.trim() : undefined;
}

/**
 * Resolve a source section's rebuild theme: hero (photo background), dark
 * (navy/black bands), alt (gray bands), light (white/near-white). Baked into
 * sections.generated.ts at scaffold; the renderer wraps each rebuilt section.
 */
export function resolveSectionTheme( section: ForgeSection ): 'hero' | 'dark' | 'alt' | 'light' {
	if ( section.bgImage ) {
		return 'hero';
	}
	const candidate = toHex( lastColorOf( section.bgGradient ) ?? section.bgColor );
	const lum = candidate ? relativeLuminance( candidate ) : undefined;
	if ( lum !== undefined ) {
		if ( lum < 0.3 ) {
			return 'dark';
		}
		if ( lum > 0.85 ) {
			return 'light';
		}
	}
	return 'alt';
}

// ---------------------------------------------------------------------------
// Site-chrome pass (120 §2) — the header/footer facts.
//
// The page content rebuilds through the semantic pipeline, but the header/
// footer come from theme templates the REST content never carries — so the
// rebuilt site lost the source's chrome entirely (jayainc: top contact
// strip, logo + tagline, anchor nav, CALL US, the footer contact columns).
// This pass extracts the chrome FACTS from the raw rendered homepage —
// topbar lines, logo, tagline, nav items, the tel: button, footer blocks,
// copyright — and the template rebuilds them with its own components.
// Facts only, never markup.
// ---------------------------------------------------------------------------

function findFirstNode( node: HNodeLike, predicate: ( node: HNodeLike ) => boolean ): HNodeLike | undefined {
	if ( predicate( node ) ) {
		return node;
	}
	for ( const child of childNodes( node ) ) {
		const found = findFirstNode( child, predicate );
		if ( found ) {
			return found;
		}
	}
	return undefined;
}

function collectNodes( node: HNodeLike, predicate: ( node: HNodeLike ) => boolean, out: HNodeLike[] = [] ): HNodeLike[] {
	if ( predicate( node ) ) {
		out.push( node );
	}
	for ( const child of childNodes( node ) ) {
		collectNodes( child, predicate, out );
	}
	return out;
}

/**
 * Pick the SITE chrome node among hint matches. Stray mid-document matches
 * must not win: jayainc renders a post's `<footer class="entry-meta">`
 * BEFORE the real `<footer id="sitefooter">`. Preference order: a node
 * whose class/id matches the site pattern, else the last match (the site
 * chrome closes the document).
 */
function findSiteNode( root: HNodeLike, hint: ( node: HNodeLike ) => boolean, sitePattern: RegExp ): HNodeLike | undefined {
	const candidates = collectNodes( root, hint );
	if ( candidates.length === 0 ) {
		return undefined;
	}
	const site = candidates.find( ( node ) => sitePattern.test( tagClasses( node ) + ' ' + ( node.attribs?.id ?? '' ) ) );
	return site ?? candidates[ candidates.length - 1 ];
}

function nodeText( node: HNodeLike | undefined ): string {
	if ( ! node ) {
		return '';
	}
	return ( node.type === 'text' ? ( ( node as unknown as { data?: string } ).data ?? '' ) : childNodes( node ).map( nodeText ).join( ' ' ) ).replace( /\s+/g, ' ' ).trim();
}

function tagClasses( node: HNodeLike ): string {
	return node.attribs?.class ?? '';
}

function paragraphNodesOf( node: HNodeLike ): HNodeLike[] {
	return collectNodes( node, ( n ) => n.type === 'tag' && n.name === 'p' );
}

/**
 * Reconcile the harvested chrome with the MEASURED sticky header.
 *
 * `analyzeChrome` resolves colours from the source's stylesheet because the
 * homepage HTML alone cannot tell which rule wins. For the header that is not
 * good enough: Brando declares a red `.navbar` background that a more specific
 * transparent header variation overrides, so the harvest picks the red the
 * source never paints. The browser measurement is the tie-breaker.
 *
 * Two facts are taken from the measurement:
 *
 *  1. The RESTING background. Absent `restBg` means the header is fully
 *     transparent — it floats over the hero — so `headerBg` is CLEARED rather
 *     than left at a stylesheet guess, and `headerTransparent` records why.
 *  2. The CHROME ink. The source keeps the container at the body ink while its
 *     nav links paint their own colour; `restInk` is that container colour, and
 *     `chrome.navInk` (unchanged) stays the link colour.
 *
 * Pure, so the reconcile is testable without a browser.
 */
export function applyMeasuredHeader(
	chrome: ForgeChrome | undefined,
	sticky: ForgeStickyHeader | undefined
): void {
	if ( ! chrome || ! sticky?.sticky ) {
		return;
	}
	if ( sticky.restBg ) {
		chrome.headerBg = sticky.restBg;
		delete chrome.headerTransparent;
	} else {
		// A transparent resting header paints nothing — the strip belongs to the
		// band behind it.
		delete chrome.headerBg;
		chrome.headerTransparent = true;
	}
	if ( sticky.restInk ) {
		chrome.headerInk = sticky.restInk;
	}
}

/**
 * The tokens that name a theme / colour-mode CONTROL (not a layout class).
 * Deliberately narrow: `.theme-header` or a `color-mode-banner` must not be
 * mistaken for a toggle.
 */
const THEME_TOGGLE_TOKEN = /(theme[-_]?(toggle|switch)|dark[-_]?mode|darkmode|(?:color|colour)[-_]?(?:mode|scheme)[-_]?(?:toggle|switch)|scheme[-_]?toggle)/i;
const THEME_TOGGLE_LABEL = /(?:(?:dark|light)\s*(?:mode|theme))|(?:theme\s*(?:toggle|switch|mode))|(?:colou?r\s*scheme)/i;

/**
 * Does the source expose a theme / colour-mode control?
 *
 * The template renders one UNCONDITIONALLY, which is an addition the source may
 * not have — virtuallyjamaica (a 2018 Brando theme with no dark mode at all)
 * showed a stray `light` button floating in its header. A site that really has a
 * toggle keeps it: `[data-theme-toggle]`, `.dark-mode-toggle`, a
 * `[aria-label="Dark mode"]` button, the WP Dark Mode plugin switch, a
 * "Colour scheme" menu item.
 *
 * Pure, so the heuristic is testable without a browser.
 */
export function sourceHasThemeToggle( html: string ): boolean {
	if ( ! html ) {
		return false;
	}
	// Cheap reject before parsing: most pages never mention a toggle at all.
	if ( ! /toggle|dark[-_ ]?mode|colou?r[-_ ]?(mode|scheme)/i.test( html ) ) {
		return false;
	}
	const handler = new DomHandler();
	const parser = new Parser( handler, { lowerCaseTags: true, lowerCaseAttributeNames: true } );
	parser.write( html );
	parser.end();
	const root: HNodeLike = {
		type: 'tag',
		name: 'root',
		attribs: {},
		children: ( handler.dom ?? [] ) as HNodeLike[],
	};
	const CONTROLS = new Set( [ 'button', 'a', 'label', 'input', 'span', 'i' ] );
	for ( const node of collectNodes( root, ( candidate ) => candidate.type === 'tag' ) ) {
		const attribs = node.attribs ?? {};
		if ( 'data-theme-toggle' in attribs || 'data-theme-switch' in attribs ) {
			return true;
		}
		const naming = `${ attribs.class ?? '' } ${ attribs.id ?? '' }`;
		if ( THEME_TOGGLE_TOKEN.test( naming ) ) {
			return true;
		}
		const label = `${ attribs[ 'aria-label' ] ?? '' } ${ attribs.title ?? '' }`;
		if ( THEME_TOGGLE_LABEL.test( label ) ) {
			return true;
		}
		// A text-labelled control (`<button>Dark mode</button>`): only on an
		// interactive tag, and only when the whole label is short — never a
		// paragraph that happens to mention dark mode.
		if ( CONTROLS.has( node.name ?? '' ) ) {
			const text = nodeText( node );
			if ( text.length > 0 && text.length <= 32 && THEME_TOGGLE_LABEL.test( text ) ) {
				return true;
			}
		}
	}
	return false;
}

/** The header/footer facts of the rendered homepage, or undefined. */
export function analyzeChrome( input: DesignFetchInput ): ForgeChrome | undefined {
	const handler = new DomHandler( undefined, { withStartIndices: true } );
	const parser = new Parser( handler, { lowerCaseTags: true, lowerCaseAttributeNames: true } );
	parser.write( input.html );
	parser.end();
	const root = handler.root as unknown as HNodeLike;

	const headerHint = ( n: HNodeLike ): boolean =>
		n.type === 'tag' &&
		// The top contact strip (#top-header) is a SIBLING of the header — a
		// loose header id/class match grabs it and empties logo/tagline/nav.
		!/top-header|topbar|top-bar/i.test( tagClasses( n ) + ' ' + ( n.attribs?.id ?? '' ) ) &&
		( n.name === 'header' || /site-header|masthead|master-header/i.test( tagClasses( n ) ) || /(^|-)header($|-)/i.test( n.attribs?.id ?? '' ) );
	const footerHint = ( n: HNodeLike ): boolean =>
		n.type === 'tag' &&
		( n.name === 'footer' || /site-footer|colophon/i.test( tagClasses( n ) ) || /footer/i.test( n.attribs?.id ?? '' ) );
	const headerNode = findSiteNode( root, headerHint, /site-header|masthead|master-header/i );
	const footerNode = findSiteNode( root, footerHint, /site-footer|sitefooter|colophon/i );
	if ( ! headerNode && ! footerNode ) {
		return undefined;
	}

	const chrome: ForgeChrome = {};
	const cssText = input.css.length > 0 ? input.css.join( '\n' ) : '';
	// The element's own tokens (classes + #id — many chrome rules are id-scoped:
	// #site-header, #top-header) and the ancestor context tokens (header
	// classes for nav/call links — `.site-header-section .aux-item-content`
	// must beat the unrelated `.mm-footer .aux-item-content`).
	const nodeTokens = ( node: HNodeLike | undefined ): string[] => {
		const tokens = ( node?.attribs?.class ?? '' ).split( /\s+/ ).filter( Boolean );
		if ( node?.attribs?.id ) {
			tokens.push( `#${ node.attribs.id }` );
		}
		return tokens;
	};
	const resolveStyle = ( node: HNodeLike | undefined, context: Array<HNodeLike | undefined> = [] ): ResolvedElementStyle => {
		return resolveElementRules( nodeTokens( node ), cssText, context.flatMap( nodeTokens ) );
	};
	// Element-level backgrounds resolve against TERMINAL rules only (the rule
	// styling the strip itself — a descendant rule's gradient must never leak
	// onto the header, as jayainc's `.site-header-section .aux-button` would).
	const resolveElementFill = ( node: HNodeLike | undefined ): ResolvedElementStyle => {
		return resolveElementRules( nodeTokens( node ), cssText, [], { terminalFill: true } );
	};
	const pickTextStyle = ( resolved: ResolvedElementStyle ): ForgeChromeStyle | undefined => {
		const style: ForgeChromeStyle = {};
		if ( resolved.weight ) {
			style.weight = resolved.weight;
		}
		if ( resolved.size ) {
			style.size = resolved.size;
		}
		if ( resolved.transform ) {
			style.transform = resolved.transform;
		}
		if ( resolved.letterSpacing && ! /^(0|0px|normal)$/i.test( resolved.letterSpacing ) ) {
			style.letterSpacing = resolved.letterSpacing;
		}
		return Object.keys( style ).length > 0 ? style : undefined;
	};
	if ( headerNode ) {
		// 1. The top contact strip — a top-header/topbar element ANYWHERE in
		//    the document (jayainc's sits as a SIBLING above the header).
		const topbar = findFirstNode( root, ( n ) => n.type === 'tag' && /top-header|topbar|top-bar/i.test( tagClasses( n ) + ' ' + ( n.attribs?.id ?? '' ) ) );
		const topbarLines = ( topbar ? paragraphNodesOf( topbar ).map( nodeText ) : [] ).filter( ( text ) => text.length > 0 ).slice( 0, 3 );
		if ( topbarLines.length > 0 ) {
			chrome.topbar = topbarLines;
		}
		// The top strip's own colors (jayainc: the navy #04132b strip with
		// white text — the rebuilt default surface-alt/muted would drift).
		const topbarResolved = resolveElementFill( topbar );
		if ( topbarResolved.fill ) {
			chrome.topbarBg = topbarResolved.fill;
		}
		if ( topbarResolved.ink ) {
			chrome.topbarInk = topbarResolved.ink;
		}

		// 2. The logo — assigned by WCAG 1.4.11 non-text contrast (124 §1):
		//    each variant's dominant colors (fetched SVG fills / raster
		//    sampling / filename hints) score against the header's actual
		//    background; the best-contrast variant wins. jayainc ships a
		//    white svg + a navy "blue" svg — the white one reads on the
		//    navy header, the navy one does not (1.07:1). Without a
		//    resolvable surface, the old variant preference stands in.
		const imgs = collectNodes( headerNode, ( n ) => n.type === 'tag' && n.name === 'img' && Boolean( n.attribs?.src ) );
		const logoPool = imgs.filter( ( img ) => isLogoImg( img ) );
		// The header surface resolves NOW (the 4c pass runs after the logo
		// pick — resolve it early for the contrast assignment; the later
		// pass re-resolves and owns chrome.headerBg).
		const headerSurface = chrome.headerBg ?? resolveElementFill( headerNode ).fill ?? chrome.topbarBg;
		const picked = pickLogoVariant( logoPool.length > 0 ? logoPool : imgs, headerSurface, input.logoColors, input.url );
		const logoImg = picked ?? imgs.find( ( img ) => tagClasses( img ).split( /\s+/ ).some( ( c ) => /image2$|logo2$|(?:blue|dark|color)/i.test( c ) ) ) ?? imgs[ 0 ];
		if ( logoImg ) {
			chrome.logo = { src: logoImg.attribs?.src, alt: logoImg.attribs?.alt };
		}

		// 3. The tagline — the longest header paragraph (≤140 chars) that is
		//    not a topbar line (the source's site description under the brand).
		const headerParagraphs = paragraphNodesOf( headerNode )
			.map( nodeText )
			.filter( ( text ) => text.length > 0 && ! topbarLines.includes( text ) );
		const tagline = headerParagraphs.filter( ( text ) => text.length <= 140 ).sort( ( a, b ) => b.length - a.length )[ 0 ];
		if ( tagline ) {
			chrome.tagline = tagline;
		}

		// 4. The nav — labeled links (the logo link carries no text and
		//    drops out); '#' (scroll-top) normalizes to '/'.
		const navNode = findFirstNode( headerNode, ( n ) => n.type === 'tag' && n.name === 'nav' ) ?? headerNode;
		const nav: Array<{ label: string; href: string }> = [];
		for ( const link of collectNodes( navNode, ( n ) => n.type === 'tag' && n.name === 'a' && Boolean( n.attribs?.href ) ) ) {
			const label = nodeText( link );
			const href = ( link.attribs?.href ?? '' ).trim();
			if ( ! label || href.startsWith( 'javascript:' ) || href.startsWith( 'tel:' ) || href.startsWith( 'mailto:' ) ) {
				continue;
			}
			nav.push( { label, href: href === '#' ? '/' : href } );
			if ( nav.length >= 8 ) {
				break;
			}
		}
		if ( nav.length > 0 ) {
			chrome.nav = nav;
		}

		// 4b. The nav link typography — the first labeled link's classes
		// resolve through the CSS (the source nav is often uppercase at the
		// chrome size: jayainc's 400/16px/uppercase white on the navy header).
		const navLinkNode = collectNodes( navNode, ( n ) => n.type === 'tag' && n.name === 'a' && Boolean( n.attribs?.href ) ).find( ( link ) => {
			const label = nodeText( link );
			const href = ( link.attribs?.href ?? '' ).trim();
			return Boolean( label ) && ! href.startsWith( 'tel:' ) && ! href.startsWith( 'mailto:' );
		} );
		const navResolved = resolveStyle( navLinkNode, [ headerNode ] );
		const navStyle = pickTextStyle( navResolved );
		if ( navStyle ) {
			chrome.navStyle = navStyle;
		}
		if ( navResolved.ink ) {
			chrome.navInk = navResolved.ink;
			// The nav link color doubles as the header text color (the brand
			// block/tagline inherit it on dark headers).
			chrome.headerInk = navResolved.ink;
		}

		// 4c. The header's own background — many one-pagers run a dark
		// header (jayainc: navy #04132b) the default white chrome must not
		// paint over.
		const headerResolved = resolveElementFill( headerNode );
		if ( headerResolved.fill ) {
			chrome.headerBg = headerResolved.fill;
		}
		if ( headerResolved.ink ) {
			chrome.headerInk = chrome.headerInk ?? headerResolved.ink;
		}

		// 5. The call button (the tel: link) — plus its real colors AND shape:
		//    the link's classes resolve through the source CSS via the shared
		//    specificity cascade (jayainc's CALL US: the yellow gradient,
		//    DARK #242424 ink — a base white `color:` rule must not win —
		//    uppercase, 14px 43px, pill radius).
		const callLink = findFirstNode( headerNode, ( n ) => n.type === 'tag' && n.name === 'a' && ( n.attribs?.href ?? '' ).startsWith( 'tel:' ) );
		if ( callLink ) {
			const call: NonNullable<ForgeChrome[ 'call' ]> = { label: nodeText( callLink ) || 'Call', href: callLink.attribs?.href ?? '' };
			const callResolved = resolveStyle( callLink, [ headerNode ] );
			if ( callResolved.fill ) {
				call.bg = callResolved.fill;
			}
			if ( callResolved.ink ) {
				call.ink = callResolved.ink;
			}
			const callStyle: ForgeChromeStyle = {};
			if ( callResolved.padding ) {
				callStyle.padding = callResolved.padding;
			}
			if ( callResolved.radius ) {
				callStyle.radius = callResolved.radius;
			}
			if ( callResolved.weight ) {
				callStyle.weight = callResolved.weight;
			}
			if ( callResolved.size ) {
				callStyle.size = callResolved.size;
			}
			if ( callResolved.transform ) {
				callStyle.transform = callResolved.transform;
			}
			if ( Object.keys( callStyle ).length > 0 ) {
				call.style = callStyle;
			}
			chrome.call = call;
		}
	}

	if ( footerNode ) {
		// 6. The footer blocks — the intro paragraphs, then each heading with
		//    its following item lines (jayainc: blurb, Products list, the
		//    Contact table). Position-scoped between consecutive headings.
		//    The columns usually live in a footer-widget ASIDE next to the
		//    footer element (jayainc's <aside class="subfooter">) — the
		//    <footer> itself is often just the copyright line, so both
		//    scopes merge for the blocks (the copyright below stays strict
		//    to the footer element).
		const scopes: HNodeLike[] = [ footerNode ];
		const widgetAside = findFirstNode( root, ( n ) =>
			n.type === 'tag' && n.name === 'aside' && /subfooter|sub-footer|footer-widget|footbar/i.test( tagClasses( n ) + ' ' + ( n.attribs?.id ?? '' ) )
		);
		if ( widgetAside ) {
			scopes.push( widgetAside );
			// The footer brand logo — the variant pool spans header + footer
			// scopes and the contrast against the footer surface decides
			// (jayainc: the white PNG in the first footer widget; on a
			// dark footer the white variants win, a dark variant on a light
			// footer). Footer-DOM candidates break ties.
			const footerImgs = collectNodes( widgetAside, ( n ) => n.type === 'tag' && n.name === 'img' && Boolean( n.attribs?.src ) );
			const headerLogoImgs = headerNode
				? collectNodes( headerNode, ( n ) => n.type === 'tag' && n.name === 'img' && Boolean( n.attribs?.src ) ).filter( ( img ) => isLogoImg( img ) )
				: [];
			const footerPool = [ ...footerImgs, ...headerLogoImgs ];
			const asideClasses = ( widgetAside.attribs?.class ?? '' ).split( /\s+/ ).filter( Boolean );
			if ( asideClasses.length > 0 && input.css.length > 0 ) {
				const rules = topLevelRules( input.css.join( '\n' ) );
				// Prefer the rule whose selector ENDS on the aside's class (the
				// rule styling the aside itself, not a descendant rule that
				// merely mentions the class) — cascade-ordered, last wins.
				const terminal = rules.filter( ( rule ) => asideClasses.some( ( cls ) => new RegExp( '\\.' + cls + '\\s*$' ).test( rule.selector.trim() ) ) );
				// The :before overlay paints ON TOP of the flat base — include
				// the aside's pseudo-element rules in the bg pool (jayainc's
				// navy subfooter tint lives in `.aux-subfooter:before`).
				const overlayRules = rules.filter( ( rule ) => asideClasses.some( ( cls ) => new RegExp( '\\.' + cls + '\\s*:?(:?before|after)\\s*$' ).test( rule.selector.trim() ) ) );
				const pool = terminal.length > 0 ? [ ...terminal, ...overlayRules ] : rules.filter( ( rule ) => asideClasses.some( ( cls ) => rule.selector.includes( cls ) ) );
				const inkRules = terminal.length > 0 ? rules.filter( ( rule ) => asideClasses.some( ( cls ) => rule.selector.includes( cls ) ) ) : pool;
				let flatBg: string | undefined;
				let overlayBg: string | undefined;
				for ( const rule of pool ) {
					const isOverlay = /:(:)?(?:before|after)/.test( rule.selector );
					if ( /:(:)?(?:hover|focus|active|visited)/.test( rule.selector ) ) {
						continue; // Pseudo-states are not the resting fill.
					}
					const gradient = rule.body.match( BG_GRADIENT_RE );
					const color = rule.body.match( BG_COLOR_RE );
					const value = gradient ? gradient[ 1 ].trim() : color ? normalizeColor( color[ 1 ].trim() ) : undefined;
					if ( ! value || value === 'transparent' ) {
						continue;
					}
					if ( isOverlay ) {
						// The :before overlay paints ON TOP of the flat base — a
						// gradient overlay (jayainc's navy subfooter tint) is the
						// effective fill; a gradient always beats a flat overlay.
						if ( gradient || ! overlayBg ) {
							overlayBg = value;
						}
					} else {
						flatBg = value;
					}
				}
				const effectiveFooterBg = overlayBg ?? flatBg;
				if ( effectiveFooterBg ) {
					chrome.footerBg = effectiveFooterBg;
				}
				// The subfooter text color (the source paints white text on
				// the dark footer) — the descendant `color:` rule, never a
				// background-color (the 123 ink-regex discipline). The
				// SHALLOWEST selector wins (`.aux-subfooter p` is the
				// footer's base text; `.… .social-list li a` colors only
				// its links); ties → later rule wins.
				let footerInk: string | undefined;
				let inkDepth = Infinity;
				for ( const rule of inkRules ) {
					if ( /:(:)?(?:before|after|hover|focus|active|visited)/.test( rule.selector ) ) {
						continue;
					}
					const ink = rule.body.match( /(?:^|;)\s*color\s*:\s*([^;!]+)/i );
					if ( ! ink ) {
						continue;
					}
					const value = normalizeColor( ink[ 1 ].trim() );
					if ( ! value || value === 'transparent' ) {
						continue;
					}
					const parts = rule.selector.trim().split( /\s+/ );
					const depth = parts.length - 1;
					if ( depth <= inkDepth ) {
						footerInk = value;
						inkDepth = depth;
					}
				}
				if ( footerInk ) {
					chrome.footerInk = footerInk;
				}
			}
			// The contrast assignment needs the resolved surface — resolve it
			// first, then pick the footer variant against it.
			const footerSurface = chrome.footerBg ?? chrome.footerBarBg;
			const picked = pickLogoVariant( footerPool, footerSurface, input.logoColors, input.url );
			const footerImg = picked ?? footerImgs[ 0 ];
			if ( footerImg ) {
				chrome.footerLogo = { src: footerImg.attribs?.src, alt: footerImg.attribs?.alt };
			}
		}
		const blocks: Array<{ title?: string; items: string[] }> = [];
		const byPosition = ( a: HNodeLike, b: HNodeLike ): number => ( a.startIndex ?? 0 ) - ( b.startIndex ?? 0 );
		const headings = scopes
			.flatMap( ( scope ) => collectNodes( scope, ( n ) => n.type === 'tag' && /^h[3-6]$/.test( n.name ?? '' ) ) )
			.sort( byPosition );
		const paragraphs = scopes.flatMap( ( scope ) => paragraphNodesOf( scope ) ).sort( byPosition );
		const itemNodes = scopes
			.flatMap( ( scope ) => collectNodes( scope, ( n ) => n.type === 'tag' && ( n.name === 'li' || n.name === 'tr' || n.name === 'p' ) ) )
			.sort( byPosition );
		if ( headings.length === 0 ) {
			const items = paragraphs.map( nodeText ).filter( ( text ) => text.length > 0 ).slice( 0, 4 );
			if ( items.length > 0 ) {
				blocks.push( { items } );
			}
		} else {
			const firstHeadingIndex = headings[ 0 ].startIndex ?? Number.POSITIVE_INFINITY;
			const intro = paragraphs
				.filter( ( p ) => ( p.startIndex ?? 0 ) < firstHeadingIndex )
				.map( nodeText )
				.filter( ( text ) => text.length > 0 )
				.slice( 0, 2 );
			if ( intro.length > 0 ) {
				blocks.push( { items: intro } );
			}
			headings.slice( 0, 4 ).forEach( ( heading, index ) => {
				const nextIndex = headings[ index + 1 ]?.startIndex ?? Number.POSITIVE_INFINITY;
				const items: string[] = [];
				for ( const itemNode of itemNodes ) {
					const position = itemNode.startIndex ?? 0;
					if ( position <= ( heading.startIndex ?? 0 ) || position >= nextIndex ) {
						continue;
					}
					const text = nodeText( itemNode );
					if ( text && ! items.includes( text ) ) {
						items.push( text );
					}
					if ( items.length >= 8 ) {
						break;
					}
				}
				blocks.push( { title: nodeText( heading ), items } );
			} );
		}
		if ( blocks.length > 0 ) {
			chrome.footerBlocks = blocks;
		}

		// 7. The copyright line (the small/.copyright element — conservative,
		//    no prose fallback).
		const copyright = findFirstNode( footerNode, ( n ) =>
			n.type === 'tag' && ( n.name === 'small' || /copyright/i.test( tagClasses( n ) ) || /copyright/i.test( n.attribs?.id ?? '' ) )
		);
		const copyrightText = nodeText( copyright );
		if ( copyrightText ) {
			chrome.copyright = copyrightText;
		}
		// The ink resolution prefers the CLASSCED container (`.aux-copyright`
		// carries the source's translucent-white rule) over the classless
		// <small> it wraps.
		const copyrightBox = findFirstNode( footerNode, ( n ) =>
			n.type === 'tag' && ( /copyright/i.test( tagClasses( n ) ) || /copyright/i.test( n.attribs?.id ?? '' ) )
		);

		// 7b. The footer element's OWN colors — the copyright bar (jayainc:
		// a translucent navy rgba(8,26,67,0.9) strip BELOW the light-gray
		// subfooter aside, translucent-white text). The footer element and
		// the widget aside are two different strips on many Phlox sites.
		// The bar INK belongs to the copyright node (its own rule carries
		// the translucent white — the footer element's base color is a gray
		// the bar must not inherit).
		const footerBarResolved = resolveElementFill( footerNode );
		if ( footerBarResolved.fill ) {
			chrome.footerBarBg = footerBarResolved.fill;
		}
		const copyrightResolved = resolveStyle( copyrightBox ?? copyright, [ footerNode ] );
		if ( copyrightResolved.ink ) {
			chrome.footerBarInk = copyrightResolved.ink;
		} else if ( footerBarResolved.ink ) {
			chrome.footerBarInk = footerBarResolved.ink;
		}

		// 7c. The footer column heading typography (jayainc: 500/29px/
		// capitalize — the rebuilt display-font 18px default drifts).
		if ( headings.length > 0 ) {
			const headingResolved = resolveStyle( headings[ 0 ], [ footerNode, widgetAside ] );
			const headingStyle = pickTextStyle( headingResolved );
			if ( headingStyle ) {
				chrome.footerHeadingStyle = headingStyle;
			}
		}
	}

	// 8. The favicon — the first <link rel="icon"> (or apple-touch-icon)
	//    href, resolved absolute. The rebuilt layout had no icon (a
	//    404 on /favicon.ico) while the source ships one.
	const iconLink = findFirstNode( root, ( n ) =>
		n.type === 'tag' &&
		n.name === 'link' &&
		Boolean( n.attribs?.href ) &&
		/(?:^|\s)(?:icon|shortcut icon|apple-touch-icon)(?:\s|$)/i.test( n.attribs?.rel ?? '' )
	);
	if ( iconLink ) {
		try {
			chrome.favicon = new URL( iconLink.attribs?.href ?? '', input.url ).href;
		} catch {
			// Unparseable href — skip.
		}
	}

	// 8b. The source icon-font stylesheet (the icons CSS link — jayainc's
	// auxin-icon.css). The rebuild's icon markers carry the source glyph
	// classes; this sheet (the customer's own asset) draws the real glyphs.
	// Matched by the stylesheet id/href naming convention (icon/awesome/
	// fontello/icomoon), never the favicon's `rel=icon`.
	const iconCssLink = findFirstNode( root, ( n ) => {
		if ( n.type !== 'tag' || n.name !== 'link' || ! n.attribs?.href ) {
			return false;
		}
		if ( ! /(?:^|\s)stylesheet(?:\s|$)/i.test( n.attribs?.rel ?? '' ) ) {
			return false;
		}
		const id = n.attribs?.id ?? '';
		const basename = ( n.attribs?.href ?? '' ).split( '?' )[ 0 ].split( '/' ).pop() ?? '';
		return /(?:icon|awesome|fontello|icomoon)/i.test( `${ id } ${ basename }` );
	} );
	if ( iconCssLink ) {
		try {
			chrome.iconCss = new URL( iconCssLink.attribs?.href ?? '', input.url ).href;
		} catch {
			// Unparseable href — skip.
		}
	}

	// 9. The chrome base font-size — header/topbar/footer run at the source's
	// chrome size (jayainc: 16px), NOT the content body scale (13px). The
	// body/html rules are the authoritative source (relative values resolve
	// against the html root rule, never the rebuilt body); the element
	// resolutions only fill in when those rules are absent (descendant
	// font-size rules pollute the header element's own resolution).
	if ( cssText.length > 0 ) {
		const chromeSize = chromeSizeFrom( cssText ) ?? resolveStyle( headerNode ).size ?? resolveStyle( footerNode ).size;
		if ( chromeSize ) {
			chrome.chromeSize = chromeSize;
		}
	}

	// 10. Does the source expose a theme / colour-mode control? The template
	// renders one unconditionally, which is an ADDITION — a stray `light` button
	// in the header of a site with no dark mode at all.
	if ( sourceHasThemeToggle( input.html ) ) {
		chrome.themeToggle = true;
	}

	if ( Object.keys( chrome ).length === 0 ) {
		return undefined;
	}
	return chrome;
}

// ---------------------------------------------------------------------------
// Scroll-behavior pass (Proposal 121) — the per-element behavior walk.
//
// The rebuild was static while the source moves: Phlox's
// aux-appear-watch-animation reveals (32 on jayainc's home), the
// aux-parallax-section hero column, the aux-logo-sticky header, and
// Elementor Motion Effects (data-settings motion_fx JSON). This pass walks
// EVERY element (the owner's loop idea) and classifies each one's scroll
// behavior into a tiny vocabulary — sticky / reveal (once) / parallax
// (continuous) — keyed by the SAME elementor-element-<id> the sections pass
// uses. Facts only: the template rebuilds the motion with its own
// CSS-native @supports runtime, never the vendor's JS.
// ---------------------------------------------------------------------------

/** The aux-appear effect class → canonical effect (name-mapped, 116-style). */
const REVEAL_EFFECTS: Array<{ pattern: RegExp; effect: NonNullable<ScrollBehaviorEntry[ 'effect' ]> }> = [
	{ pattern: /fade-in-up|fadeup|fadeInUp/i, effect: 'fade-up' },
	{ pattern: /fade-in-down|fadedown|fadeInDown/i, effect: 'fade-down' },
	{ pattern: /fade-in-left|fadeleft|fadeInLeft/i, effect: 'fade-left' },
	{ pattern: /fade-in-right|faderight|fadeInRight/i, effect: 'fade-right' },
	{ pattern: /scale-up|scaleup|zoom-in|zoomIn/i, effect: 'scale-up' },
	{ pattern: /blur|blurin/i, effect: 'blur' },
];

const STICKY_CLASS_RE = /(?:^|\s)(elementor-sticky|aux-top-sticky|aux-logo-sticky)(?:\s|$)/;
const APPEAR_CLASS_RE = /(?:^|\s)aux-appear-watch-animation(?:\s|$)/;
const PARALLAX_CLASS_RE = /(?:^|\s)aux-parallax-section(?:\s|$)/;

/** Decode the &quot;/&#39; entities Elementor writes into data-settings. */
function decodeEntities( value: string ): string {
	return value.replace( /&quot;/g, '"' ).replace( /&#0?39;/g, "'" ).replace( /&amp;/g, '&' );
}

/** Parse the data-settings motion_fx JSON into a tolerant record. */
function motionFxSettings( node: HNodeLike ): Record<string, unknown> | undefined {
	const raw = node.attribs?.[ 'data-settings' ];
	if ( ! raw ) {
		return undefined;
	}
	try {
		const parsed = JSON.parse( decodeEntities( raw ) );
		return typeof parsed === 'object' && parsed !== null ? ( parsed as Record<string, unknown> ) : undefined;
	} catch {
		return undefined;
	}
}

function motionFxValue( settings: Record<string, unknown> | undefined, key: string ): unknown {
	return settings?.[ key ];
}

/** Extract the source element id (elementor-element-<id>) from a class list. */
function elementIdOf( classes: string ): string | undefined {
	return classes.split( /\s+/ ).map( ( cls ) => SECTION_ID_RE.exec( cls ) ).find( Boolean )?.[ 1 ];
}

/**
 * The per-element scroll-behavior classification (121 §2.2). Deterministic;
 * an empty html yields an empty pass.
 */
export function analyzeScrollBehavior( input: DesignFetchInput ): ScrollBehaviorPass {
	const handler = new DomHandler( undefined, {} );
	const parser = new Parser( handler, { lowerCaseTags: true, lowerCaseAttributeNames: true } );
	parser.write( input.html );
	parser.end();
	const root = handler.root as unknown as HNodeLike;
	const cssText = input.css.join( '\n' );

	const pass: ScrollBehaviorPass = { headerSticky: false, entries: [] };
	const seen = new Set<string>();
	const push = ( entry: ScrollBehaviorEntry ): void => {
		const key = `${ entry.kind }:${ entry.id ?? '' }:${ entry.effect ?? '' }`;
		if ( seen.has( key ) ) {
			return;
		}
		seen.add( key );
		pass.entries.push( entry );
	};

	const headerNode = findFirstNode( root, ( n ) => n.type === 'tag' && ( n.name === 'header' || /site-header|master-header/i.test( tagClasses( n ) + ' ' + ( n.attribs?.id ?? '' ) ) ) );

	// The walk: EVERY element (the one-pager loop), classified in order.
	// Behaviors ROLL UP to the nearest top-level section: a parallax column
	// inside the hero makes the rebuilt HERO section parallax (the renderer
	// only ever wraps top-level sections). The section stack tracks the
	// current containing elementor-top-section id.
	const sectionStack: string[] = [];
	const walk = ( node: HNodeLike ): void => {
		if ( node.type === 'tag' ) {
			const classes = tagClasses( node );
			const id = elementIdOf( classes );
			const isTopSection = sectionClasses( node ).some( ( cls ) => cls === 'elementor-top-section' || cls === 'elementor-section' );
			if ( isTopSection && id ) {
				sectionStack.push( id );
			}
			const sectionId = id !== undefined && isTopSection ? id : sectionStack[ sectionStack.length - 1 ] ?? id;
			const settings = motionFxSettings( node );
			const scrolling = motionFxValue( settings, 'motion_fx_motion_fx_scrolling' ) === 'yes';
			const hasMotionKeys = settings !== undefined && Object.keys( settings ).some( ( key ) => /^motion_fx_(translate|rotate|scale|opacity|blur)[xy]?_effect$/i.test( key ) );

			// 1. Sticky — the Elementor sticky class, a sticky header class, or
			//    a sticky/fixed CSS rule scoped to the element's own id.
			if ( STICKY_CLASS_RE.test( classes ) ) {
				const insideHeader = headerNode !== undefined && findFirstNode( headerNode, ( n ) => n === node ) !== undefined;
				if ( insideHeader || /aux-logo-sticky|aux-top-sticky/i.test( classes ) ) {
					pass.headerSticky = true;
				} else if ( sectionId ) {
					push( { id: sectionId, kind: 'sticky' } );
				}
			} else if ( id ) {
				for ( const rule of topLevelRules( cssText ) ) {
					if ( ! rule.selector.includes( `elementor-element-${ id }` ) ) {
						continue;
					}
					if ( /position\s*:\s*(sticky|fixed)/i.test( rule.body ) ) {
						push( { id: sectionId, kind: 'sticky' } );
					}
					break;
				}
			}

			// 2. Parallax — the theme class, the Elementor scroll-tied
			//    translate effect, or a fixed-attachment background rule.
			if ( PARALLAX_CLASS_RE.test( classes ) || ( scrolling && hasMotionKeys ) ) {
				if ( sectionId ) {
					const speed = settings ? ( ( motionFxValue( settings, 'motion_fx_translateY_speed' ) as { size?: number } | undefined )?.size ?? 1 ) : 1;
					push( { id: sectionId, kind: 'parallax', speed: Number.isFinite( speed ) && speed > 0 ? speed : 1 } );
				}
			} else if ( id ) {
				for ( const rule of topLevelRules( cssText ) ) {
					if ( ! rule.selector.includes( `elementor-element-${ id }` ) ) {
						continue;
					}
					if ( /background-attachment\s*:\s*fixed/i.test( rule.body ) ) {
						push( { id: sectionId, kind: 'parallax' } );
					}
					break;
				}
			}

			// 3. Reveal — the theme's appear-watch system, Elementor's
			//    invisible marker (entrance-animated elements), or the
			//    generic AOS/wow/animate conventions.
			const hasAppear = APPEAR_CLASS_RE.test( classes );
			const hasEntrance = settings !== undefined && ! scrolling && hasMotionKeys;
			const hasGenericReveal = /data-aos|\bwow\b|animate__animated/i.test( classes + ' ' + ( node.attribs?.[ 'data-aos' ] ?? '' ) );
			if ( hasAppear || hasEntrance || hasGenericReveal || /(?:^|\s)elementor-invisible(?:\s|$)/.test( classes ) ) {
				if ( sectionId ) {
					const effect = REVEAL_EFFECTS.find( ( entry ) => entry.pattern.test( classes ) )?.effect ?? 'fade';
					push( { id: sectionId, kind: 'reveal', effect } );
				}
			}
		}
		for ( const child of childNodes( node ) ) {
			walk( child );
		}
		if ( node.type === 'tag' && sectionClasses( node ).some( ( cls ) => cls === 'elementor-top-section' || cls === 'elementor-section' ) ) {
			sectionStack.pop();
		}
	};
	walk( root );
	return pass.entries.length === 0 && ! pass.headerSticky
		? { headerSticky: false, entries: [] }
		: pass;
}

export function analyzeDesignInput( input: DesignFetchInput ): ForgeDesign {
	const cssText = input.css.join( '\n' );
	const roles: Record<string, string> = {};
	const confidence: Record<string, RoleConfidence> = {};
	const varHits: Record<string, { value: string; role: string }> = {};
	let designCtaStyle: { padding?: string; radius?: string } | undefined;

	// 1. CSS custom properties with role hints — the highest-quality signal.
	for ( const match of cssText.matchAll( CSS_VAR_RE ) ) {
		const name = match[ 1 ];
		const raw = match[ 2 ].trim();
		if ( ! COLOR_VALUE_RE.test( raw ) ) {
			continue;
		}
		const value = normalizeColor( raw );
		if ( ! value ) {
			continue;
		}
		let role: string | undefined;
		// Elementor globals first (--e-global-color-primary → accent).
		const elementor = /^--e-global-color-([\w-]+)$/i.exec( name );
		if ( elementor ) {
			role = ELEMENTOR_ROLE_NAMES[ elementor[ 1 ].toLowerCase() ] ?? `custom-${ elementor[ 1 ].toLowerCase() }`;
		} else {
			for ( const [ candidate, regex ] of Object.entries( VAR_ROLE_HINTS ) ) {
				if ( regex.test( name ) ) {
					role = candidate;
					break;
				}
			}
		}
		if ( role && ! roles[ role ] ) {
			roles[ role ] = value;
			confidence[ role ] = 'high';
			varHits[ role ] = { value, role };
		}
	}

	// 1b. The untouched-builder-default override: when the var role is one
	// of the builder's stock primaries, the theme was never customized and
	// the dominant CTA color IS the brand accent (jayainc: the global
	// primary stays #6ec1e4 while every button renders #fccd0f).
	if ( roles.accent && DEFAULT_BUILDER_PRIMARIES.has( roles.accent ) ) {
		const cta = dominantCtaColor( cssText );
		if ( cta && cta !== roles.accent ) {
			roles.accent = cta;
			confidence.accent = 'high';
			varHits.accent = { value: cta, role: 'accent' };
		}
	}

	// 1c. The CTA role — the fill the source's actual BUTTON elements wear
	// (element coverage beats rule frequency: jayainc's buttons are all
	// mikado-yellow while the accent-colored rules outnumber them in the
	// raw CSS). Separate from `accent`, which stays the text-accent.
	// The button metrics (padding/radius) ride along — the rebuilt
	// .ds-button must wear the source's own shape (jayainc: 14px 43px
	// pill, not the template's 8px 16px square).
	if ( input.html ) {
		const ctaMetrics = dominantCtaFillFromHtml( input );
		if ( ctaMetrics ) {
			roles.cta = ctaMetrics.fill;
			confidence.cta = 'high';
			designCtaStyle = {
				padding: ctaMetrics.padding,
				// A pill (1600px) is a pill — cap so the token stays sane.
				radius: ctaMetrics.radius ? ( parseFloat( ctaMetrics.radius ) > 999 ? '999px' : ctaMetrics.radius ) : undefined,
			};
		}
	}

	// 2. Frequency + saturation heuristics fill the confident gaps.
	const frequencies = colorFrequencies( cssText );
	const ranked = [ ...frequencies.entries() ].sort( ( a, b ) => b[ 1 ] - a[ 1 ] );
	const unassigned: string[] = [];
	const seen = new Set( Object.values( roles ) );
	const saturated = ranked.filter( ( [ value ] ) => saturationOf( value ) >= 45 && lightnessOf( value ) > 20 && lightnessOf( value ) < 85 );
	const neutrals = ranked.filter( ( [ value ] ) => saturationOf( value ) < 12 );
	if ( ! roles.accent && saturated.length > 0 ) {
		roles.accent = saturated[ 0 ][ 0 ];
		confidence.accent = 'medium';
		seen.add( roles.accent );
	}
	if ( ! roles.text && neutrals.length > 0 ) {
		const bodyGray = neutrals.find( ( [ value ] ) => lightnessOf( value ) <= 70 && lightnessOf( value ) >= 15 );
		if ( bodyGray ) {
			roles.text = bodyGray[ 0 ];
			confidence.text = 'medium';
			seen.add( roles.text );
		}
	}
	if ( ! roles.surface ) {
		const light = neutrals.find( ( [ value ] ) => lightnessOf( value ) >= 95 );
		roles.surface = light ? light[ 0 ] : '#ffffff';
		confidence.surface = light ? 'medium' : 'low';
		seen.add( roles.surface );
	}
	if ( ! roles[ 'field.border' ] && neutrals.length > 0 ) {
		const border = neutrals.find( ( [ value ] ) => lightnessOf( value ) >= 70 && lightnessOf( value ) <= 92 );
		if ( border ) {
			roles[ 'field.border' ] = border[ 0 ];
			confidence[ 'field.border' ] = 'medium';
			seen.add( roles[ 'field.border' ] );
		}
	}
	for ( const [ value ] of ranked ) {
		if ( unassigned.length >= 12 || seen.has( value ) ) {
			continue;
		}
		unassigned.push( value );
		seen.add( value );
	}

	// 3. Typography, spacing, radii, shadows.
	// @font-face blocks are load evidence, not usage — they inflate
	// local google-fonts sheets (roboto.css carries ~5 faces) and flip the
	// ranking away from the families elements actually wear.
	const cssForFamilies = cssText.replace( /@font-face\s*\{[^{}]*\}/gi, '' );
	const families = familyFrequencies( cssForFamilies ).map( ( entry ) => entry.family );
	// The body-selector rule is the authoritative body family (later rules
	// win) — the frequency ranking miscounts builder-default families that
	// sit unused in Elementor's typography tokens (jayainc: `roboto` is a
	// token-layer leftover while the body really renders Rubik). The
	// h1-h6 rule is the authoritative DISPLAY family the same way.
	const bodyRuleFamily = bodyRuleFact( cssText, 'font-family' )?.split( ',' )[ 0 ].trim().replace( /^["']|["']$/g, '' ).toLowerCase();
	const headingRuleFamily = headingRuleFact( cssText, 'font-family' )?.split( ',' )[ 0 ].trim().replace( /^["']|["']$/g, '' ).toLowerCase();
	const bodyFamily = bodyRuleFamily && ! GENERIC_FAMILIES.has( bodyRuleFamily ) ? bodyRuleFamily : families[ 1 ];
	const headingFamily = headingRuleFamily && ! GENERIC_FAMILIES.has( headingRuleFamily ) ? headingRuleFamily : undefined;
	const displayFamily = headingFamily ?? ( families[ 0 ] && families[ 0 ] !== bodyFamily ? families[ 0 ] : families.find( ( family ) => family !== bodyFamily ) ?? bodyFamily );
	const bodyWeight = bodyRuleFact( cssText, 'font-weight' );
	const scale = trimScaleLadder( pxValues( cssText, FONT_SCALE_PATTERNS ) );
	const spacing = trimSpaceLadder( pxValues( cssText, SPACE_PATTERNS ) );
	const radii = trimLadder( pxValues( cssText, RADIUS_PATTERNS ), 8 );
	const shadows = ( cssText.match( /box-shadow\s*:/gi ) ?? [] ).length;

	// The section-background pass (117 §2) — the per-band theming data the
	// scaffold bakes into sections.generated.ts. Elementor is authoritative
	// when present. When it is not (WPBakery, Divi, Bricks, Gutenberg,
	// Flatsome, plain HTML), the vendor-agnostic OUTLINE becomes the band
	// model instead: without this the rebuild has no band surfaces at all and
	// renders template defaults (virtuallyjamaica.com's red #dd3333 hero came
	// out #f5f5f4, 2026-11-09). The renderer maps outline bands positionally,
	// which `bandSource` tells it to do.
	const elementorSections = input.html ? analyzeSections( input ) : undefined;
	const outline = input.html ? bandOutline( input ) : {};
	const sections =
		elementorSections && elementorSections.length > 0
			? elementorSections
			: promoteOutlineBands( outline.outline );
	const bandSource =
		elementorSections && elementorSections.length > 0
			? 'sections'
			: sections.length > 0
				? 'outline'
				: undefined;

	return {
		mode: 'rebuild',
		source: { url: input.url, analyzedAt: new Date().toISOString() },
		concept: conceptFor( roles, displayFamily, bodyFamily ),
		palette: { roles, unassigned, confidence },
		typography: {
			display: displayFamily,
			body: bodyFamily,
			scalePx: scale.length > 0 ? scale : [ 14, 16, 20, 28, 40 ],
			bodyWeight,
		},
		spacing: { scalePx: spacing.length > 0 ? spacing : [ 4, 8, 16, 24, 48 ] },
		radii: { valuesPx: radii },
		shadows: { count: shadows },
		components: componentVocabulary( input.html ),
		modes: { dark: 'auto', contrast: 'auto' },
		// The section-background pass (117 §2) — the per-band theming data the
		// scaffold bakes into sections.generated.ts. Elementor sections win;
		// otherwise the promoted outline bands (above) drive rendering.
		sections,
		bandSource,
		// Proposal 137 — the vendor-agnostic band OUTLINE. Kept for provenance
		// (adapter, `via`, measured columns) even when it also became
		// `sections`; absent for Elementor sites.
		...( input.html ? outline : {} ),
		chrome: input.html ? analyzeChrome( input ) : undefined,
		behavior: input.html ? analyzeScrollBehavior( input ) : undefined,
		ctaStyle: designCtaStyle,
		adaptation: {
			keep: [
				'The palette roles, type pairing, and spacing rhythm — the design language, not the pixels.',
				'Component vocabulary hints (buttons, cards, nav) as a checklist, not as markup.',
			],
			replace: [
				'All copy, imagery, logos, and distinctive artwork with the customer\'s own content.',
				'Fonts with licensed equivalents unless the source font is verifiably self-hostable.',
			],
			remix: [
				'Shift the accent hue by roughly 10 degrees and pick the customer\'s own brand neutrals — visibly theirs, not a copy.',
				'Let the customer\'s real content (products, posts, pages) drive the layout; apply the language to it.',
			],
			licensingNote:
				'Inspired-by analysis: no source CSS, HTML, copy, or assets were copied. Verify the source license before any closer adaptation.',
			attribution: [ `Source: ${ input.url }` ],
		},
	};
}

/** Normalize a CSS color value to lowercase hex; undefined when unparseable. */
/** The CSS named colors themes actually use for buttons (white/black and
 * friends) — the chrome ink extraction reads `color: white` declarations. */
const NAMED_COLORS: Record<string, string> = {
	white: '#ffffff',
	black: '#000000',
	red: '#ff0000',
	green: '#008000',
	blue: '#0000ff',
	yellow: '#ffff00',
	orange: '#ffa500',
	gray: '#808080',
	grey: '#808080',
	silver: '#c0c0c0',
	gold: '#ffd700',
};

export function normalizeColor( value: string ): string | undefined {
	const trimmed = value.trim();
	const named = NAMED_COLORS[ trimmed.toLowerCase() ];
	if ( named ) {
		return named;
	}
	let hex = /^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.exec( trimmed )?.[ 0 ];
	if ( hex ) {
		return expandHex( hex ).toLowerCase();
	}
	const rgb = /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i.exec( trimmed );
	if ( rgb ) {
		return rgbToHex( Number( rgb[ 1 ] ), Number( rgb[ 2 ] ), Number( rgb[ 3 ] ) ).toLowerCase();
	}
	const hsl = /^hsla?\(\s*([\d.]+)\s*,\s*([\d.]+)%\s*,\s*([\d.]+)%/i.exec( trimmed );
	if ( hsl ) {
		return hslToHex( Number( hsl[ 1 ] ), Number( hsl[ 2 ] ), Number( hsl[ 3 ] ) ).toLowerCase();
	}
	return undefined;
}

// -- token mapping (114 §3.3) -------------------------------------------------

/** Analyze a built site's CSS bundle — the verify-side extraction (no HTML). */
export function analyzeBuiltCss( css: string, url: string ): ForgeDesign {
	return analyzeDesignInput( { url, html: '', css: [ css ] } );
}

/**
 * Derive the flat `--forge-*` token overrides from a design block (the
 * tokens_apply v2 mapping). Accent-ink is luminance-derived (white/black),
 * the text role lands on ink, and muted picks a mid-gray from the
 * unassigned pool — the contrast gate then audits every rendered pair.
 */
export function designToTokens( design: ForgeDesign ): Record<string, string> {
	const tokens: Record<string, string> = {};
	const { roles } = design.palette;
	if ( roles.accent ) {
		tokens.accent = roles.accent;
		// Accent ink by contrast, not by a lightness threshold: whichever of
		// white/black renders better against THIS accent wins (the audit then
		// confirms the result — for #6ec1e4 black is the only AA option).
		const whiteRatio = contrast( '#ffffff', roles.accent ) ?? 0;
		const blackRatio = contrast( '#111111', roles.accent ) ?? 0;
		tokens[ 'accent-ink' ] = whiteRatio >= blackRatio ? '#ffffff' : '#111111';
	}
	if ( roles.text ) {
		tokens.ink = roles.text;
	}
	if ( roles.surface ) {
		tokens.surface = roles.surface;
	}
	if ( roles[ 'field.border' ] ) {
		// The functional border role feeds line-strong (the 3:1-audited input
		// border token, mirroring the ADS line-strong additive) — decorative
		// hairlines keep the template default line.
		tokens[ 'line-strong' ] = roles[ 'field.border' ];
	}
	if ( roles.cta ) {
		// The CTA fill (121 §5): the actual button background the source
		// wears — often distinct from the text accent (jayainc: yellow
		// buttons, cyan accents). cta-ink by contrast, like accent-ink;
		// a gradient's dominant END color drives the ink decision.
		tokens.cta = roles.cta;
		const ctaHex = normalizeColor( roles.cta ) ?? ( roles.cta.includes( 'gradient' ) ? normalizeColor( lastColorOf( roles.cta ) ?? '' ) : undefined );
		if ( ctaHex ) {
			const whiteRatio = contrast( '#ffffff', ctaHex ) ?? 0;
			const blackRatio = contrast( '#111111', ctaHex ) ?? 0;
			tokens[ 'cta-ink' ] = whiteRatio >= blackRatio ? '#ffffff' : '#111111';
		}
	}
	// The source button shape (121 §5) — padding + radius ride the same
	// harvest so the rebuilt buttons match the source's (jayainc: the
	// 14px 43px pill, not the template default).
	if ( design.ctaStyle?.padding ) {
		tokens[ 'cta-pad' ] = design.ctaStyle.padding;
	}
	if ( design.ctaStyle?.radius ) {
		tokens[ 'cta-radius' ] = design.ctaStyle.radius;
	}
	const grays = design.palette.unassigned.filter( ( value ) => {
		const hex = normalizeColor( value );
		return hex !== undefined && saturationOf( hex ) < 12;
	} );
	const muted = grays.find( ( value ) => lightnessOf( value ) >= 35 && lightnessOf( value ) <= 65 );
	if ( muted ) {
		tokens.muted = muted;
	}
	if ( design.typography.display ) {
		tokens[ 'font-display' ] = `'${ design.typography.display }', ${ DEFAULT_FONT_STACK }`;
	}
	if ( design.typography.body ) {
		// Always emit the body font — even when body === display the body
		// token must not fall through to the system stack (a single-family
		// source like jayainc renders BOTH roles in Rubik).
		tokens.font = `'${ design.typography.body }', ${ DEFAULT_FONT_STACK }`;
	}
	if ( design.typography.bodyWeight ) {
		// The source body's real weight (jayainc: 300 — the rebuilt 400
		// default reads visibly bolder than the source everywhere).
		tokens[ 'body-weight' ] = design.typography.bodyWeight;
	}
	const scale = design.typography.scalePx;
	if ( scale.length > 0 ) {
		// Banded ranking (117 §5 + jayainc's dense harvest): the top-down
		// ranking pinned h1 to the largest harvested value — a 52-value
		// builder harvest made that a 128px banner and body text 64px.
		// Read each band off the ladder instead: body/small from the reading
		// band (first in band — the source's actual body size), headings
		// from their band centers (representatives, dense-ladder-safe).
		// The micro-text band centers on 10px — a first-value pick lands on
		// 8px (a hidden/micro element size) and makes every scale-small
		// consumer unreadable (jayainc's topbar rendered at 8px).
		const small = centerOfBand( scale, 8, 12 ) ?? firstInBand( scale, SCALE_BANDS.small[ 0 ], SCALE_BANDS.small[ 1 ] ) ?? scale[ 0 ];
		const body =
			( typeof design.typography.bodyPx === 'number' && design.typography.bodyPx >= 10 && design.typography.bodyPx <= 28
				? design.typography.bodyPx
				: undefined ) ??
			firstInBand( scale, SCALE_BANDS.body[ 0 ], SCALE_BANDS.body[ 1 ] ) ??
			firstInBand( scale, 13, 24 ) ??
			16;
		const h3 = centerOfBand( scale, SCALE_BANDS.card[ 0 ], SCALE_BANDS.card[ 1 ] ) ?? nextStep( scale, body + 6, 40 ) ?? body + 8;
		const h2 = centerOfBand( scale, SCALE_BANDS.section[ 0 ], SCALE_BANDS.section[ 1 ] ) ?? nextStep( scale, h3 + 8, 76 ) ?? h3 + 12;
		const h1 = centerOfBand( scale, SCALE_BANDS.hero[ 0 ], SCALE_BANDS.hero[ 1 ] ) ?? nextStep( scale, h2 + 12, 128 ) ?? scale[ scale.length - 1 ];
		tokens[ 'scale-h1' ] = `${ h1 }px`;
		tokens[ 'scale-h2' ] = `${ h2 }px`;
		tokens[ 'scale-h3' ] = `${ h3 }px`;
		tokens[ 'scale-body' ] = `${ body }px`;
		tokens[ 'scale-small' ] = `${ small }px`;
	}
	const space = design.spacing.scalePx;
	if ( space.length > 0 ) {
		// Banded spacing (jayainc's 46-value harvest): the bottom/top slice
		// baked space-4 as 104px. Read each rhythm band off the ladder —
		// xs/sm/md/lg/xl representatives — so the rebuilt rhythm stays a
		// rhythm.
		const xs = centerOfBand( space, SPACE_BANDS.xs[ 0 ], SPACE_BANDS.xs[ 1 ] ) ?? firstInBand( space, 2, 8 ) ?? space[ 0 ];
		const sm = centerOfBand( space, SPACE_BANDS.sm[ 0 ], SPACE_BANDS.sm[ 1 ] ) ?? nextStep( space, xs, 14 ) ?? xs + 4;
		const md = centerOfBand( space, SPACE_BANDS.md[ 0 ], SPACE_BANDS.md[ 1 ] ) ?? nextStep( space, sm + 4, 22 ) ?? sm + 8;
		const lg = centerOfBand( space, SPACE_BANDS.lg[ 0 ], SPACE_BANDS.lg[ 1 ] ) ?? nextStep( space, md + 8, 44 ) ?? md + 12;
		const xl = centerOfBand( space, SPACE_BANDS.xl[ 0 ], SPACE_BANDS.xl[ 1 ] ) ?? nextStep( space, lg + 12, 100 ) ?? lg + 20;
		tokens[ 'space-1' ] = `${ xs }px`;
		tokens[ 'space-2' ] = `${ sm }px`;
		tokens[ 'space-3' ] = `${ md }px`;
		tokens[ 'space-4' ] = `${ lg }px`;
		tokens[ 'space-5' ] = `${ xl }px`;
	}
	const radii = design.radii.valuesPx;
	if ( radii.length > 0 ) {
		tokens.radius = `${ radii[ 0 ] }px`;
		tokens[ 'radius-lg' ] = `${ radii[ Math.min( radii.length - 1, 1 ) ] ?? radii[ 0 ] }px`;
	}
	return tokens;
}

const DEFAULT_FONT_STACK = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";

// -- adaptation + contrast fixes (114 §3.5) ----------------------------------

/** One suggested token substitution from the design's own unassigned pool. */
export interface ContrastFix {
	/** The token key that fails (e.g. 'ink'). */
	token: string;
	/** The failing pair label. */
	label: string;
	ratio: number;
	/** Replacement value from the unassigned pool (undefined when none fits). */
	suggested?: string;
	/** Contrast the suggestion would render. */
	suggestedRatio?: number;
}

/**
 * For each failing rendered pair, find the best replacement for the
 * foreground from the design's unassigned pool — the source's own palette
 * fixes its own contrast (114 §3.5; the #7a7a7a → #444444 class of fix).
 */
export function suggestContrastFixes(
	design: ForgeDesign,
	failures: Array<{ token: string; label: string; ratio: number; background: string; required: number }>
): ContrastFix[] {
	const pool = design.palette.unassigned
		.map( normalizeColor )
		.filter( ( value ): value is string => value !== undefined );
	return failures.map( ( failure ) => {
		let best: string | undefined;
		let bestRatio = 0;
		let bestDistance = Number.POSITIVE_INFINITY;
		for ( const candidate of pool ) {
			const ratio = contrast( candidate, failure.background );
			if ( ratio === undefined || ratio < failure.required ) {
				continue;
			}
			const distance = colorDistance( candidate, failure.background );
			if ( ratio > bestRatio || ( ratio === bestRatio && distance < bestDistance ) ) {
				best = candidate;
				bestRatio = ratio;
				bestDistance = distance;
			}
		}
		return {
			token: failure.token,
			label: failure.label,
			ratio: failure.ratio,
			suggested: best,
			suggestedRatio: best ? bestRatio : undefined,
		};
	} );
}

/** WCAG contrast between two hex colors (undefined when unparseable). */
export function contrast( fg: string, bg: string ): number | undefined {
	const l1 = luminance( fg );
	const l2 = luminance( bg );
	if ( l1 === undefined || l2 === undefined ) {
		return undefined;
	}
	const [ light, dark ] = l1 >= l2 ? [ l1, l2 ] : [ l2, l1 ];
	return ( light + 0.05 ) / ( dark + 0.05 );
}

function luminance( hex: string ): number | undefined {
	const normalized = normalizeColor( hex );
	if ( ! normalized ) {
		return undefined;
	}
	const [ r, g, b ] = hexToRgb( normalized ).map( ( c ) => {
		const s = c / 255;
		return s <= 0.04045 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
	} );
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

// -- parity scoring (114 §3.6, forge verify) ---------------------------------

export interface ParityMetrics {
	roleDistance: number;
	familyOverlap: number;
	scaleOverlap: number;
	spacingOverlap: number;
	conceptMatch: number;
}

export interface ParityReport {
	source: { url: string; analyzedAt: string };
	target: { url: string; analyzedAt: string };
	metrics: ParityMetrics;
	/** 0–1 language-parity score (1 = identical language, 0 = unrelated). */
	score: number;
	pass: boolean;
	threshold: number;
}

function overlap( a: number[], b: number[] ): number {
	if ( a.length === 0 || b.length === 0 ) {
		return 0;
	}
	const setA = new Set( a.map( Math.round ) );
	const setB = new Set( b.map( Math.round ) );
	const intersection = [ ...setA ].filter( ( value ) => setB.has( value ) ).length;
	return intersection / Math.max( setA.size, setB.size );
}

/** Compare two family lists (first-name match on lowercase). */
function familyOverlapOf( a: Array<string | undefined>, b: Array<string | undefined> ): number {
	const setA = new Set( a.filter( ( value ): value is string => Boolean( value ) ).map( ( value ) => value.toLowerCase().split( ' ' )[ 0 ] ) );
	const setB = new Set( b.filter( ( value ): value is string => Boolean( value ) ).map( ( value ) => value.toLowerCase().split( ' ' )[ 0 ] ) );
	if ( setA.size === 0 || setB.size === 0 ) {
		return 0;
	}
	const intersection = [ ...setA ].filter( ( value ) => setB.has( value ) ).length;
	return intersection / Math.max( setA.size, setB.size );
}

/**
 * The language-parity score: how faithfully the built site's design
 * language carries the source's. Deliberately NOT pixel parity — role
 * distance, family/scale/spacing overlap, and the deterministic concept
 * signature only (114 §3.6). Thresholds are per-invocation, default 0.6.
 */
export function scoreParity( source: ForgeDesign, target: ForgeDesign, threshold: number = 0.6 ): ParityReport {
	const roles = [ 'accent', 'text', 'surface', 'field.border' ];
	const distances: number[] = [];
	for ( const role of roles ) {
		const a = source.palette.roles[ role ];
		const b = target.palette.roles[ role ];
		if ( a && b ) {
			distances.push( colorDistance( a, b ) );
		} else if ( a || b ) {
			distances.push( 1 ); // role present on one side only — full distance.
		}
	}
	const roleDistance = distances.length > 0 ? distances.reduce( ( sum, value ) => sum + value, 0 ) / distances.length : 1;
	const familyOverlap = familyOverlapOf(
		[ source.typography.display, source.typography.body ],
		[ target.typography.display, target.typography.body ]
	);
	const scaleOverlap = overlap( source.typography.scalePx, target.typography.scalePx );
	const spacingOverlap = overlap( source.spacing.scalePx, target.spacing.scalePx );
	const conceptMatch = conceptSignature( source ) === conceptSignature( target ) ? 1 : 0;

	const score =
		( 1 - roleDistance ) * 0.45 +
		familyOverlap * 0.2 +
		scaleOverlap * 0.15 +
		spacingOverlap * 0.1 +
		conceptMatch * 0.1;
	return {
		source: source.source,
		target: target.source,
		metrics: { roleDistance, familyOverlap, scaleOverlap, spacingOverlap, conceptMatch },
		score: Math.round( score * 1000 ) / 1000,
		pass: score >= threshold,
		threshold,
	};
}

// -- band continuity (124 §7) -------------------------------------------------

/** One adjacent-band transition seam — the source's own hard cut, or the
 * symptom of a band the rebuild dropped/mis-analyzed. */
export interface BandSeam {
	fromId?: string;
	toId?: string;
	/** The edge colors that failed to meet. */
	fromEdge?: string;
	toEdge?: string;
	/** CIE76 ΔE between the two edge colors (0 = identical). */
	distance: number;
}

/** Color tokens of a gradient/color string, in declaration order. */
function gradientStops( value: string | undefined ): string[] {
	if ( ! value ) {
		return [];
	}
	const stops: string[] = [];
	const re = /#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})\b|rgba?\([^)]*\)/gi;
	for ( const match of value.matchAll( re ) ) {
		const hex = normalizeColor( match[ 0 ] );
		if ( hex ) {
			stops.push( hex );
		}
	}
	return stops;
}

/** A band's visual edges (the colors its top/bottom meet its neighbors
 * with): the gradient's first/last stops, or the flat color both ways.
 * Photo-only bands (bgImage without a color) have no comparable edges. */
export function bandEdgeColors( section: ForgeSection ): { start?: string; end?: string } {
	const stops = gradientStops( section.bgGradient );
	if ( stops.length >= 2 ) {
		return { start: stops[ 0 ], end: stops[ stops.length - 1 ] };
	}
	if ( stops.length === 1 ) {
		return { start: stops[ 0 ], end: stops[ 0 ] };
	}
	if ( section.bgColor && section.bgColor !== 'transparent' ) {
		const color = normalizeColor( section.bgColor );
		if ( color ) {
			return { start: color, end: color };
		}
	}
	return {};
}

/** Raw CIE76 ΔE (0–441) between two hex colors. */
function rawDeltaE( a: string, b: string ): number | undefined {
	try {
		const [ r1, g1, b1 ] = hexToRgb( a );
		const [ r2, g2, b2 ] = hexToRgb( b );
		return Math.sqrt( ( r1 - r2 ) ** 2 + ( g1 - g2 ) ** 2 + ( b1 - b2 ) ** 2 );
	} catch {
		return undefined;
	}
}

/** The source's adjacent-band transition report: seams where band N's end
 * color does not meet band N+1's start color (ΔE > 25 — a visible hard
 * cut). Photo bands and unparseable colors are never compared. The seams
 * are the source's OWN cuts — the verify gate treats any built/source
 * MISMATCH of this signature as the defect, never the cuts themselves. */
export function analyzeBandContinuity( sections: ForgeSection[] ): BandSeam[] {
	const seams: BandSeam[] = [];
	for ( let index = 1; index < sections.length; index++ ) {
		const previous = bandEdgeColors( sections[ index - 1 ] );
		const next = bandEdgeColors( sections[ index ] );
		if ( ! previous.end || ! next.start ) {
			continue;
		}
		const distance = rawDeltaE( previous.end, next.start );
		if ( distance === undefined ) {
			continue;
		}
		if ( distance > 25 ) {
			seams.push( {
				fromId: sections[ index - 1 ].id,
				toId: sections[ index ].id,
				fromEdge: previous.end,
				toEdge: next.start,
				distance: Math.round( distance ),
			} );
		}
	}
	return seams;
}

/** One built band wrap (the DesignRenderer marks each wrap with its source
 * section id — the verify gate matches on it). */
export interface BuiltBand {
	id?: string;
	bg?: string;
	bgImage?: string;
	padding?: string;
}

/** Extract the built page's band wraps from its HTML (static or server
 * output). The wrap's inline style carries the baked theme background. */
export function builtBandsOf( html: string ): BuiltBand[] {
	const bands: BuiltBand[] = [];
	const seen = new Set<string>();
	const scopedPadding = scopedPaddingOf( html );
	// Attribute ORDER is not part of the contract. The renderer emits
	// `data-section="<kind>" data-section-id="<id>" id=… class=… style=…`, so a
	// positional regex that expects `data-section-id` immediately after `<div`
	// silently matches NOTHING and every band is reported as dropped. Match the
	// tag, then read its attributes independently. The FIRST tag per id wins:
	// the outermost wrapper carries the background, and nested elements repeat
	// the id.
	for ( const match of html.matchAll( /<div\b[^>]*>/gi ) ) {
		const attributes = match[ 0 ];
		const id = /\bdata-section-id="([^"]*)"/i.exec( attributes )?.[ 1 ];
		if ( ! id || seen.has( id ) ) {
			continue;
		}
		seen.add( id );
		const style = /\bstyle="([^"]*)"/i.exec( attributes )?.[ 1 ] ?? '';
		const bg = /(?:^|;)background:\s*([^";]+)/i.exec( style )?.[ 1 ];
		const bgImage = /(?:^|;)background-image:\s*url\(['"]?([^'"\)]+)['"]?\)/i.exec( style )?.[ 1 ];
		const padding = /(?:^|;)padding:\s*([^";]+)/i.exec( style )?.[ 1 ];
		bands.push( {
			id,
			bg: bg?.trim(),
			bgImage: bgImage?.trim(),
			padding: padding?.trim() ?? scopedPadding.get( id ),
		} );
	}
	return bands;
}

/** The band-flow verify gate: every source band with visual facts must
 * render in the built page carrying the baked background (a dropped band
 * is exactly the failure the source's continuous flow depends on). */
export interface BandFlowReport {
	/** Source bands (with band facts) missing from the built page. */
	dropped: string[];
	/** Bands whose built background/padding differs from the baked theme. */
	mismatched: Array<{ id: string; expected: string; actual?: string }>;
	/** The source's own adjacent-band seams (informational). */
	seams: BandSeam[];
}

function normalizeBg( value: string | undefined ): string {
	return ( value ?? '' ).replace( /\s+/g, ' ' ).trim().toLowerCase();
}

/** A band padding as comparable text — a device-scoped map compares on its
 * desktop value, which is what the unqueried built rule carries. */
function paddingText( value: ForgeSection[ 'padding' ] ): string {
	return typeof value === 'string' ? value : ( value?.desktop ?? '' );
}

export function verifyBandFlow(
	sourceSections: ForgeSection[],
	builtHtml: string,
	localizedMedia?: Map<string, string>
): BandFlowReport {
	const builtById = new Map( builtBandsOf( builtHtml ).filter( ( band ) => band.id ).map( ( band ) => [ band.id as string, band ] ) );
	const dropped: string[] = [];
	const mismatched: BandFlowReport[ 'mismatched' ] = [];
	// A localized build renders `/media/<hash>-<slug>.<ext>` in place of the
	// source's absolute URL (Proposal 139). The localizer's report is that
	// URL -> path map, so a mirrored image still satisfies the declared fact.
	const sameImage = ( expected: string, actual?: string ): boolean =>
		actual === expected || ( actual !== undefined && localizedMedia?.get( expected ) === actual );
	for ( const section of sourceSections ) {
		if ( ! section.id ) {
			continue;
		}
		const expectedBg = section.bgGradient ?? ( section.bgColor && section.bgColor !== 'transparent' ? normalizeColor( section.bgColor ) : undefined );
		const expectedImage = section.bgImage;
		const expectedPadding = paddingText( section.padding );
		if ( ! expectedBg && ! expectedImage && ! expectedPadding ) {
			continue; // No visual band facts — nothing to gate.
		}
		const band = builtById.get( section.id );
		if ( ! band ) {
			dropped.push( section.id );
			continue;
		}
		if ( expectedBg && normalizeBg( band.bg ) !== normalizeBg( expectedBg ) ) {
			mismatched.push( { id: section.id, expected: expectedBg, actual: band.bg } );
		}
		if ( expectedImage && ! sameImage( expectedImage, band.bgImage ) ) {
			mismatched.push( { id: section.id, expected: expectedImage, actual: band.bgImage } );
		}
		if ( expectedPadding && ( band.padding ?? '' ).replace( /\s+/g, ' ' ).trim() !== expectedPadding.replace( /\s+/g, ' ' ).trim() ) {
			mismatched.push( { id: section.id, expected: expectedPadding, actual: band.padding } );
		}
	}
	return { dropped, mismatched, seams: analyzeBandContinuity( sourceSections ) };
}

/** Deterministic concept signature: accent hue bucket + the sorted type pair (order-independent). */
function conceptSignature( design: ForgeDesign ): string {
	const accent = design.palette.roles.accent;
	const hue = accent ? hueOf( accent ) : -1;
	const bucket = hue < 0 ? 'none' : hue > 210 || ( hue >= 150 && hue <= 210 ) ? 'cool' : hue < 60 || hue >= 300 ? 'warm' : 'neutral';
	const pair = [ design.typography.display ?? '', design.typography.body ?? '' ]
		.map( ( family ) => family.toLowerCase() )
		.filter( Boolean )
		.sort()
		.join( '+' );
	return `${ bucket }:${ pair || 'none' }`;
}
