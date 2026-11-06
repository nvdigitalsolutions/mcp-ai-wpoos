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

import { relativeLuminance } from './contrast.ts';
import { assertSafeHttpUrl } from './ssrf.ts';
import type { ForgeChrome, ForgeDesign, ForgeSection, ScrollBehaviorEntry, ScrollBehaviorPass } from './types.ts';

/** Bounded fetch input: the homepage HTML plus same-origin CSS bodies. */
export interface DesignFetchInput {
	url: string;
	html: string;
	css: string[];
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

const MAX_STYLESHEETS = 6;
const MAX_HTML_BYTES = 2 * 1024 * 1024;
const MAX_CSS_BYTES = 512 * 1024;

/** Expand a short hex (#abc → #aabbcc); alpha is dropped. */
function expandHex( hex: string ): string {
	const value = hex.replace( '#', '' );
	if ( value.length === 3 ) {
		return `#${ value
			.split( '' )
			.map( ( char ) => char + char )
			.join( '' ) }`;
	}
	return `#${ value.slice( 0, 6 ) }`;
}

function clampByte( value: number ): number {
	return Math.max( 0, Math.min( 255, Math.round( value ) ) );
}

function rgbToHex( r: number, g: number, b: number ): string {
	return `#${ [ r, g, b ]
		.map( ( channel ) => clampByte( channel ).toString( 16 ).padStart( 2, '0' ) )
		.join( '' ) }`;
}

function hslToHex( h: number, s: number, l: number ): string {
	const hue = ( ( h % 360 ) + 360 ) % 360;
	const sat = s / 100;
	const light = l / 100;
	const chroma = ( 1 - Math.abs( 2 * light - 1 ) ) * sat;
	const x = chroma * ( 1 - Math.abs( ( ( hue / 60 ) % 2 ) - 1 ) );
	const m = light - chroma / 2;
	let r: number;
	let g: number;
	let b: number;
	if ( hue < 60 ) [ r, g, b ] = [ chroma, x, 0 ];
	else if ( hue < 120 ) [ r, g, b ] = [ x, chroma, 0 ];
	else if ( hue < 180 ) [ r, g, b ] = [ 0, chroma, x ];
	else if ( hue < 240 ) [ r, g, b ] = [ 0, x, chroma ];
	else if ( hue < 300 ) [ r, g, b ] = [ x, 0, chroma ];
	else [ r, g, b ] = [ chroma, 0, x ];
	return rgbToHex( ( r + m ) * 255, ( g + m ) * 255, ( b + m ) * 255 );
}

function hexToRgb( hex: string ): [ number, number, number ] {
	const value = hex.replace( '#', '' );
	return [ parseInt( value.slice( 0, 2 ), 16 ), parseInt( value.slice( 2, 4 ), 16 ), parseInt( value.slice( 4, 6 ), 16 ) ];
}

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
	// Inline <style> blocks ride along with the linked sheets.
	for ( const match of html.matchAll( /<style[^>]*>([\s\S]*?)<\/style>/gi ) ) {
		css.push( match[ 1 ].slice( 0, MAX_CSS_BYTES ) );
	}
	for ( const link of stylesheetLinks( html, base ) ) {
		try {
			const res = await fetchImpl( link, { redirect: 'follow', signal: AbortSignal.timeout( 15_000 ) } );
			if ( res.ok ) {
				css.push( ( await res.text() ).slice( 0, MAX_CSS_BYTES ) );
			}
		} catch {
			// A missing stylesheet is never fatal — the remaining signals stand.
		}
	}
	return { url: base, html, css };
}

// -- analysis ----------------------------------------------------------------

/** Deterministic concept summary from the confident roles + type pairing. */
function conceptFor( roles: Record<string, string>, families: string[] ): string {
	const accent = roles.accent;
	const warmth = accent ? ( hueOf( accent ) > 210 || ( hueOf( accent ) >= 150 && hueOf( accent ) <= 210 ) ? 'cool' : hueOf( accent ) < 60 || hueOf( accent ) >= 300 ? 'warm' : 'neutral' ) : 'neutral';
	const pair = families.length >= 2 ? `${ families[ 0 ].toLowerCase() } display / ${ families[ 1 ].toLowerCase() } body` : 'single-family type';
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
	for ( const rule of cssText.matchAll( RULE_RE ) ) {
		const selector = rule[ 1 ];
		if ( ! /button|\.btn\b|cta|aux-call|aux-ac|pricing/i.test( selector ) ) {
			continue;
		}
		const color = rule[ 2 ].match( BG_COLOR_RE );
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
	const bump = ( map: Map<string, number>, value: string, weight = 1 ): void => {
		map.set( value, ( map.get( value ) ?? 0 ) + weight );
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
			// Cascade approximation: per property, the matching rule with the
			// MOST of the element's classes in its selector wins, ties broken
			// by source order (a rough specificity + order cascade — the old
			// frequency scheme let base rules out-vote the size/pill rules).
			// The FILL prefers gradients: a gradient background-image is the
			// effective visual fill over any flat color (jayainc's buttons
			// paint the yellow gradient; flat hover/outline colors lose).
			let elementGradient: string | undefined;
			let elementColor: string | undefined;
			let gradientSpec = -1;
			let colorSpec = -1;
			let elementPadding: string | undefined;
			let elementRadius: string | undefined;
			let padSpec = -1;
			let radiusSpec = -1;
			let ruleIndex = 0;
			for ( const rule of cssText.matchAll( RULE_RE ) ) {
				const selector = rule[ 1 ];
				const matchCount = classes.filter( ( cls ) => selector.includes( cls ) ).length;
				if ( matchCount === 0 ) {
					ruleIndex++;
					continue;
				}
				const spec = matchCount * 1000000 + ruleIndex;
				const body = rule[ 2 ];
				const gradient = body.match( BG_GRADIENT_RE );
				if ( gradient && spec >= gradientSpec ) {
					elementGradient = gradient[ 1 ].trim();
					gradientSpec = spec;
				}
				const color = body.match( BG_COLOR_RE );
				if ( color ) {
					const value = normalizeColor( color[ 1 ].trim() );
					if ( value && value !== 'transparent' && spec >= colorSpec ) {
						elementColor = value;
						colorSpec = spec;
					}
				}
				const padding = body.match( /(?:^|;)\s*padding\s*:\s*([^;!]+)/i );
				if ( padding && spec >= padSpec ) {
					elementPadding = padding[ 1 ].trim();
					padSpec = spec;
				}
				const radius = body.match( /(?:^|;)\s*border-radius\s*:\s*([^;!]+)/i );
				if ( radius && spec >= radiusSpec ) {
					elementRadius = radius[ 1 ].trim();
					radiusSpec = spec;
				}
				ruleIndex++;
			}
			const elementFill = elementGradient ?? elementColor;
			// The cascade winner per element feeds the votes directly — the
			// base-rule noise never reaches them.
			if ( elementFill ) {
				bump( votes, elementFill );
			}
			if ( elementPadding ) {
				bump( padVotes, elementPadding );
			}
			if ( elementRadius ) {
				bump( radiusVotes, elementRadius );
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
const BG_GRADIENT_RE = /background-image\s*:\s*((?:repeating-)?(?:linear|radial|conic)-gradient\([^;]+)/i;
const BG_IMAGE_RE = /background-image\s*:\s*url\(["']?([^"')]+)["']?\)/i;
const BG_COLOR_RE = /background-color\s*:\s*([^;!]+)/i;
const PADDING_RE = /padding\s*:\s*([^;!]+)/i;
const SECTION_ID_RE = /elementor-element-([0-9a-f]{4,})/;

function childNodes( node: HNodeLike | undefined ): HNodeLike[] {
	return node?.children ?? [];
}

function sectionClasses( node: HNodeLike ): string[] {
	return ( node.attribs?.class ?? '' ).split( /\s+/ ).filter( Boolean );
}

function allSectionNodes( node: HNodeLike, out: HNodeLike[] ): void {
	for ( const child of childNodes( node ) ) {
		if ( child.type === 'tag' && sectionClasses( child ).some( ( c ) => c === 'elementor-top-section' || c === 'elementor-section' ) ) {
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
		for ( const rule of cssText.matchAll( RULE_RE ) ) {
			const selector = rule[ 1 ];
			const body = rule[ 2 ];
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
		}
		// A background-color alongside a photo is an UNDERLAY — Elementor
		// paints it beneath the (opaque) image, so the source renders it
		// invisibly (jayainc's hero: rgba(8,26,67,0.9) under jaya-3-1.jpg).
		// Storing it as the band color makes the rebuilt hero paint the tint
		// OVER the photo — the opposite of the source. Drop it.
		if ( fact.bgImage ) {
			delete fact.bgColor;
		}
		if ( fact.bgImage || fact.bgGradient || fact.bgColor || fact.overlay ) {
			facts.push( fact );
		}
	}
	return facts;
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
	if ( headerNode ) {
		// 1. The top contact strip — a top-header/topbar element ANYWHERE in
		//    the document (jayainc's sits as a SIBLING above the header).
		const topbar = findFirstNode( root, ( n ) => n.type === 'tag' && /top-header|topbar|top-bar/i.test( tagClasses( n ) + ' ' + ( n.attribs?.id ?? '' ) ) );
		const topbarLines = ( topbar ? paragraphNodesOf( topbar ).map( nodeText ) : [] ).filter( ( text ) => text.length > 0 ).slice( 0, 3 );
		if ( topbarLines.length > 0 ) {
			chrome.topbar = topbarLines;
		}

		// 2. The logo — prefer the light-background variant of a dark/light
		//    pair (jayainc ships a white svg + a blue svg; the blue one reads
		//    on the rebuilt light header).
		const imgs = collectNodes( headerNode, ( n ) => n.type === 'tag' && n.name === 'img' && Boolean( n.attribs?.src ) );
		const logoImg =
			imgs.find( ( img ) => tagClasses( img ).split( /\s+/ ).some( ( c ) => /image2$|logo2$|(?:blue|dark|color)/i.test( c ) ) ) ?? imgs[ 0 ];
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

		// 5. The call button (the tel: link) — plus its real colors: the
		//    link's classes resolve through the source CSS to the button's
		//    actual background/text (jayainc's CALL US is yellow #fccd0f
		//    while the site accent is cyan #1bb0ce — the chrome must not
		//    repaint it with the accent).
		const callLink = findFirstNode( headerNode, ( n ) => n.type === 'tag' && n.name === 'a' && ( n.attribs?.href ?? '' ).startsWith( 'tel:' ) );
		if ( callLink ) {
			const call: NonNullable<ForgeChrome[ 'call' ]> = { label: nodeText( callLink ) || 'Call', href: callLink.attribs?.href ?? '' };
			const classes = ( callLink.attribs?.class ?? '' ).split( /\s+/ ).filter( Boolean );
			if ( classes.length > 0 && input.css.length > 0 ) {
				const cssText = input.css.join( '\n' );
				const bgVotes = new Map<string, number>();
				const inkVotes = new Map<string, number>();
				for ( const rule of cssText.matchAll( RULE_RE ) ) {
					const selector = rule[ 1 ];
					if ( ! classes.some( ( cls ) => selector.includes( cls ) ) ) {
						continue;
					}
					const body = rule[ 2 ];
					const bg = body.match( BG_COLOR_RE );
					if ( bg ) {
						const value = normalizeColor( bg[ 1 ].trim() );
						if ( value && value !== 'transparent' ) {
							bgVotes.set( value, ( bgVotes.get( value ) ?? 0 ) + 1 );
						}
					}
					const gradient = body.match( BG_GRADIENT_RE );
					if ( gradient ) {
						// The gradient IS the button's effective background (it
						// overrides the flat color) — keep the raw expression
						// so the rebuild renders the source's exact fill.
						bgVotes.set( gradient[ 1 ].trim(), ( bgVotes.get( gradient[ 1 ].trim() ) ?? 0 ) + 2 );
					}
					// The standalone color: declaration (anchored — 'color' inside
					// 'background-color' must not match).
					const ink = body.match( /(?:^|;)\s*color\s*:\s*([^;!]+)/i );
					if ( ink ) {
						const value = normalizeColor( ink[ 1 ].trim() );
						if ( value && value !== 'transparent' ) {
							inkVotes.set( value, ( inkVotes.get( value ) ?? 0 ) + 1 );
						}
					}
				}
				const top = ( votes: Map<string, number> ): string | undefined => {
					let best: string | undefined;
					let bestCount = 0;
					for ( const [ value, count ] of votes ) {
						if ( count > bestCount ) {
							best = value;
							bestCount = count;
						}
					}
					return best;
				};
				call.bg = top( bgVotes );
				call.ink = top( inkVotes );
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
			// The footer brand logo (jayainc: the white logo PNG in the first
			// footer widget) and the footer's own background color.
			const footerImg = findFirstNode( widgetAside, ( n ) => n.type === 'tag' && n.name === 'img' && Boolean( n.attribs?.src ) );
			if ( footerImg ) {
				chrome.footerLogo = { src: footerImg.attribs?.src, alt: footerImg.attribs?.alt };
			}
			const asideClasses = ( widgetAside.attribs?.class ?? '' ).split( /\s+/ ).filter( Boolean );
			if ( asideClasses.length > 0 && input.css.length > 0 ) {
				const rules = [ ...input.css.join( '\n' ).matchAll( RULE_RE ) ];
				// Prefer the rule whose selector ENDS on the aside's class (the
				// rule styling the aside itself, not a descendant rule that
				// merely mentions the class) — cascade-ordered, last wins.
				const terminal = rules.filter( ( rule ) => asideClasses.some( ( cls ) => new RegExp( '\\.' + cls + '\\s*$' ).test( rule[ 1 ].trim() ) ) );
				const pool = terminal.length > 0 ? terminal : rules.filter( ( rule ) => asideClasses.some( ( cls ) => rule[ 1 ].includes( cls ) ) );
				for ( const rule of pool ) {
					const bg = rule[ 2 ].match( BG_COLOR_RE );
					if ( bg ) {
						const value = normalizeColor( bg[ 1 ].trim() );
						if ( value && value !== 'transparent' ) {
							chrome.footerBg = value;
						}
					}
				}
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
				for ( const rule of cssText.matchAll( RULE_RE ) ) {
					if ( ! rule[ 1 ].includes( `elementor-element-${ id }` ) ) {
						continue;
					}
					if ( /position\s*:\s*(sticky|fixed)/i.test( rule[ 2 ] ) ) {
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
				for ( const rule of cssText.matchAll( RULE_RE ) ) {
					if ( ! rule[ 1 ].includes( `elementor-element-${ id }` ) ) {
						continue;
					}
					if ( /background-attachment\s*:\s*fixed/i.test( rule[ 2 ] ) ) {
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
	const families = familyFrequencies( cssText ).map( ( entry ) => entry.family );
	const scale = trimScaleLadder( pxValues( cssText, FONT_SCALE_PATTERNS ) );
	const spacing = trimSpaceLadder( pxValues( cssText, SPACE_PATTERNS ) );
	const radii = trimLadder( pxValues( cssText, RADIUS_PATTERNS ), 8 );
	const shadows = ( cssText.match( /box-shadow\s*:/gi ) ?? [] ).length;

	return {
		mode: 'rebuild',
		source: { url: input.url, analyzedAt: new Date().toISOString() },
		concept: conceptFor( roles, families ),
		palette: { roles, unassigned, confidence },
		typography: {
			display: families[ 0 ],
			body: families[ 1 ],
			scalePx: scale.length > 0 ? scale : [ 14, 16, 20, 28, 40 ],
		},
		spacing: { scalePx: spacing.length > 0 ? spacing : [ 4, 8, 16, 24, 48 ] },
		radii: { valuesPx: radii },
		shadows: { count: shadows },
		components: componentVocabulary( input.html ),
		modes: { dark: 'auto', contrast: 'auto' },
		// The section-background pass (117 §2) — the per-band theming data
		// the scaffold bakes into sections.generated.ts.
		sections: input.html ? analyzeSections( input ) : undefined,
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
	if ( design.typography.body && design.typography.body !== design.typography.display ) {
		tokens.font = `'${ design.typography.body }', ${ DEFAULT_FONT_STACK }`;
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
		const body = firstInBand( scale, SCALE_BANDS.body[ 0 ], SCALE_BANDS.body[ 1 ] ) ?? firstInBand( scale, 13, 24 ) ?? 16;
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
