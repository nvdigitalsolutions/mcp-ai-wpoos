// ---------------------------------------------------------------------------
// class-audit.ts — which class names in the rebuilt bands match NO rule?
//
// The sanitizer keeps the source's class names, and the builder's own stylesheet
// never ships. Most of those classes are inert, and an inert class looks exactly
// like a styled one in a screenshot until you notice the symptom: virtuallyjamaica
// rebuilt `text-center` and `text-uppercase` steps left-aligned in lower case, a
// `padding-four-all` panel with its copy flush against the edge, and an accordion
// that rendered every panel open — four hand fixes, one root cause.
//
// This audit answers the question once, in one list, so the fixes can be chosen
// from data instead of from screenshots. It is REPORT-ONLY by default
// (`--strict-classes` turns it into a gate): a class is not required to be
// styled, and a rebuild with fifty inert classes can still be faithful.
//
// Deliberately static — no browser, no CSS engine. It compares the class
// attributes the page emits against the class selectors the stylesheets define,
// including the attribute-selector forms (`[class*="brando_image_"]`) that a
// naive `.name` scan would report as missing when they are in fact covered.
// ---------------------------------------------------------------------------

import { builtBandBlocks } from './band-audit.ts';

export interface UnresolvedClass {
	/** The class name as it appears in the markup. */
	name: string;
	/** How many times it appears across the audited bands. */
	count: number;
	/** The band ids it appears in (source order, de-duplicated). */
	bands: string[];
}

export interface ClassCoverage {
	/** Distinct class names used inside the rebuilt bands. */
	used: number;
	/** Of those, how many match at least one rule. */
	resolved: number;
	/** The rest, most-used first (then alphabetical, so the output is stable). */
	unresolved: UnresolvedClass[];
}

/** One `[class …]` attribute selector: the operator and its token. */
interface AttributeClassSelector {
	operator: '=' | '~=' | '*=' | '^=' | '$=' | '|=';
	token: string;
}

/**
 * Every selector prelude (the text before each `{`) in a stylesheet.
 *
 * A real character scan, not a regex split: a `{` inside a quoted string (or an
 * attribute selector's quoted value) must not end a prelude, and a declaration
 * body must never be read as one. Comments are stripped first because they may
 * contain both quotes and braces.
 */
function preludesOf( css: string ): string[] {
	const cleaned = css.replace( /\/\*[\s\S]*?\*\//g, ' ' );
	const preludes: string[] = [];
	let buffer = '';
	let quote: string | null = null;
	for ( let index = 0; index < cleaned.length; index++ ) {
		const char = cleaned[ index ];
		if ( quote ) {
			buffer += char;
			if ( char === '\\' ) {
				index++;
				buffer += cleaned[ index ] ?? '';
				continue;
			}
			if ( char === quote ) {
				quote = null;
			}
			continue;
		}
		if ( char === '"' || char === "'" ) {
			quote = char;
			buffer += char;
			continue;
		}
		if ( char === '{' ) {
			preludes.push( buffer );
			buffer = '';
			continue;
		}
		if ( char === '}' ) {
			buffer = '';
			continue;
		}
		buffer += char;
	}
	return preludes;
}

const CLASS_TOKEN_RE = /\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)/g;
const ATTR_CLASS_RE = /\[\s*class\s*([~*^$|]?=)\s*["']?([^"'\]]+?)["']?\s*\]/g;

/**
 * Every class name the stylesheets style, plus the attribute-selector tokens.
 *
 * Preludes only: a declaration (`background: url(…)`, `content: ".x{")` can
 * contain a dot or a brace, so the scan takes the text before each `{` and
 * ignores the block body entirely.
 */
export function classSelectorsFromCss( css: string ): {
	names: Set< string >;
	attributeMatchers: AttributeClassSelector[];
} {
	const names = new Set< string >();
	const attributeMatchers: AttributeClassSelector[] = [];
	const seenMatchers = new Set< string >();

	// Nested at-rules (`@media { .x { … } }`) are covered because the inner
	// `.x {` prelude is captured on its own.
	for ( const prelude of preludesOf( css ) ) {
		for ( const token of prelude.matchAll( CLASS_TOKEN_RE ) ) {
			const name = token[ 1 ];
			if ( name ) {
				names.add( name );
			}
		}
		for ( const attr of prelude.matchAll( ATTR_CLASS_RE ) ) {
			const operator = ( attr[ 1 ] || '=' ) as AttributeClassSelector[ 'operator' ];
			const value = ( attr[ 2 ] || '' ).trim();
			if ( ! value ) {
				continue;
			}
			const key = `${ operator }${ value }`;
			if ( seenMatchers.has( key ) ) {
				continue;
			}
			seenMatchers.add( key );
			attributeMatchers.push( { operator, token: value } );
		}
	}
	return { names, attributeMatchers };
}

/** Does an attribute-class selector cover this class name? */
function matchesAttribute( name: string, matcher: AttributeClassSelector ): boolean {
	switch ( matcher.operator ) {
		case '=':
			return name === matcher.token;
		case '~=':
			// `[class~="x"]` is a whole-token match: the token must BE a class.
			return name === matcher.token;
		case '*=':
			return name.includes( matcher.token );
		case '^=':
			return name.startsWith( matcher.token );
		case '$=':
			return name.endsWith( matcher.token );
		case '|=':
			return name === matcher.token || name.startsWith( `${ matcher.token }-` );
		default:
			return false;
	}
}

/** Class names inside each band slice, keyed by band id. */
function classesByBand( html: string ): Map< string, Map< string, number > > {
	const bands = builtBandBlocks( html );
	const scopes: Array< { id: string; slice: string } > =
		bands.length > 0 ? bands.map( ( band ) => ( { id: band.id, slice: band.slice } ) ) : [ { id: '(page)', slice: html } ];
	const out = new Map< string, Map< string, number > >();
	for ( const scope of scopes ) {
		const counts = new Map< string, number >();
		for ( const attr of scope.slice.matchAll( /\bclass\s*=\s*"([^"]*)"/g ) ) {
			for ( const name of attr[ 1 ].split( /\s+/ ) ) {
				const trimmed = name.trim();
				if ( trimmed ) {
					counts.set( trimmed, ( counts.get( trimmed ) ?? 0 ) + 1 );
				}
			}
		}
		out.set( scope.id, counts );
	}
	return out;
}

/**
 * Audit the built page: every class used inside a rebuilt band, and whether any
 * stylesheet rule reaches it.
 *
 * A class covered only by an attribute selector counts as resolved — that is the
 * distinction between "the builder ships no sheet so this class does nothing"
 * (the finding) and "the shim styles this family with `[class*=…]`" (fine).
 *
 * The page's INLINE `<style>` blocks are part of the sample: the per-band rules
 * (`[data-section-id] h3{…}`, the hidden-subtree `display:none` selectors) are
 * emitted there, not in a `.css` file, and omitting them reported every band
 * rule as missing.
 */
export function auditClassCoverage( html: string, css: string ): ClassCoverage {
	const inlineCss = [ ...html.matchAll( /<style\b[^>]*>([\s\S]*?)<\/style>/gi ) ]
		.map( ( match ) => match[ 1 ] )
		.join( '\n' );
	const { names, attributeMatchers } = classSelectorsFromCss( `${ css }\n${ inlineCss }` );
	const perBand = classesByBand( html );
	const unresolved = new Map< string, UnresolvedClass >();
	for ( const [ bandId, counts ] of perBand ) {
		for ( const [ name, count ] of counts ) {
			const covered = names.has( name ) || attributeMatchers.some( ( matcher ) => matchesAttribute( name, matcher ) );
			if ( covered ) {
				continue;
			}
			const entry = unresolved.get( name ) ?? { name, count: 0, bands: [] };
			entry.count += count;
			if ( ! entry.bands.includes( bandId ) ) {
				entry.bands.push( bandId );
			}
			unresolved.set( name, entry );
		}
	}
	const list = [ ...unresolved.values() ].sort( ( a, b ) => b.count - a.count || a.name.localeCompare( b.name ) );
	const distinct = new Set< string >();
	for ( const counts of perBand.values() ) {
		for ( const name of counts.keys() ) {
			distinct.add( name );
		}
	}
	return { used: distinct.size, resolved: distinct.size - list.length, unresolved: list };
}
