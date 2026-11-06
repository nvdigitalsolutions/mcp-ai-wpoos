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
import type { ForgeDesign, ForgeSection } from './types.ts';

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
function pxValues( cssText: string, patterns: RegExp[] ): number[] {
	const values = new Set<number>();
	for ( const property of patterns ) {
		for ( const match of cssText.matchAll( property ) ) {
			const value = parseFloat( match[ 1 ] );
			if ( Number.isFinite( value ) && value > 0 && value <= 120 ) {
				values.add( value );
			}
		}
	}
	return [ ...values ].sort( ( a, b ) => a - b );
}

// The forge's scale/spacing tokens are custom properties
// (--forge-scale-h1: 40px) — px signals live in the var VALUES, not in
// font-size/margin declarations, so both declaration and token forms count.
const FONT_SCALE_PATTERNS = [ /font-size\s*:\s*([\d.]+)px/gi, /--[\w-]*scale[\w-]*\s*:\s*([\d.]+)px/gi ];
const SPACE_PATTERNS = [ /(?:margin|padding|gap)\s*(?:-\w+)?\s*:\s*([\d.]+)px/gi, /--[\w-]*spac[\w-]*\s*:\s*([\d.]+)px/gi ];
const RADIUS_PATTERNS = [ /border-radius\s*:\s*([\d.]+)px/gi ];

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

export function analyzeDesignInput( input: DesignFetchInput ): ForgeDesign {
	const cssText = input.css.join( '\n' );
	const roles: Record<string, string> = {};
	const confidence: Record<string, RoleConfidence> = {};
	const varHits: Record<string, { value: string; role: string }> = {};

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
	const scale = pxValues( cssText, FONT_SCALE_PATTERNS ).slice( 0, 8 );
	const spacing = pxValues( cssText, SPACE_PATTERNS ).slice( 0, 8 );
	const radii = pxValues( cssText, RADIUS_PATTERNS ).slice( 0, 8 );
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
export function normalizeColor( value: string ): string | undefined {
	const trimmed = value.trim();
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
		tokens[ 'scale-h1' ] = `${ Math.max( ...scale ) }px`;
		tokens[ 'scale-h2' ] = `${ scale[ Math.min( scale.length - 1, 3 ) ] ?? 28 }px`;
		tokens[ 'scale-h3' ] = `${ scale[ Math.min( scale.length - 1, 2 ) ] ?? 20 }px`;
		tokens[ 'scale-body' ] = `${ scale[ Math.min( scale.length - 1, 1 ) ] ?? 16 }px`;
		tokens[ 'scale-small' ] = `${ scale[ 0 ] ?? 14 }px`;
	}
	const space = design.spacing.scalePx;
	if ( space.length > 0 ) {
		const steps = [ 1, 2, 4, 6, 8 ].map( ( n ) => space[ Math.min( space.length - 1, n - 1 ) ] ?? space[ space.length - 1 ] );
		tokens[ 'space-1' ] = `${ steps[ 0 ] }px`;
		tokens[ 'space-2' ] = `${ steps[ 1 ] }px`;
		tokens[ 'space-3' ] = `${ steps[ 2 ] }px`;
		tokens[ 'space-4' ] = `${ steps[ 3 ] }px`;
		tokens[ 'space-5' ] = `${ steps[ 4 ] }px`;
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
