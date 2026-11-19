// ---------------------------------------------------------------------------
// css-lint.ts — rules that are declared and do nothing.
//
// `.site-header--brand-center .site-nav-shell { grid-column: 1 }` was written to
// put the header disclosure on the left. It did nothing: `.site-nav-shell` is
// `display: contents`, so it has no box for the grid to place, and the toggle
// auto-placed itself beside the logo (x=524 against the source's x=195,
// monicarajiv 2026-11-20). Nothing failed — the rule looked correct.
//
// A `display: contents` element has no box, so ANY box-affecting property on it
// is inert: grid placement, padding, background, borders. This finds the ones
// that matter and would otherwise be trusted.
// ---------------------------------------------------------------------------

/** One inert declaration: a box property on a box-less element. */
export interface InertDeclaration {
	/** The selector that declares the box property. */
	selector: string;
	/** The property that cannot apply (`grid-column`). */
	property: string;
	/** The class whose rule makes the element box-less (`site-nav-shell`). */
	contentsClass: string;
}

/** Box-affecting properties that a `display: contents` element cannot honour. */
const INERT_PROPERTIES = new Set( [
	'grid-column',
	'grid-column-start',
	'grid-column-end',
	'grid-row',
	'grid-row-start',
	'grid-row-end',
	'grid-area',
	'padding',
	'padding-top',
	'padding-right',
	'padding-bottom',
	'padding-left',
	'background',
	'background-color',
	'border',
	'border-width',
	'width',
	'height',
	'margin',
] );

/** Class tokens in a selector, ignoring anything inside a pseudo-class. */
export function classTokens( selector: string ): string[] {
	const out = new Set< string >();
	for ( const match of String( selector ?? '' ).matchAll( /\.(-?[a-zA-Z_][\w-]*)/g ) ) {
		out.add( match[ 1 ] );
	}
	return [ ...out ];
}

/**
 * The SUBJECT of a selector — its last compound, after the final combinator.
 *
 * CSS applies a rule to the subject only, so `.contents > .child { padding }`
 * is valid: it pads the child. Only `.parent .contents { padding }` is inert.
 * Combinators inside an attribute selector (`[data-x="a b"]`) are ignored.
 */
export function subjectOf( selector: string ): string {
	const text = String( selector ?? '' );
	let depth = 0;
	let start = 0;
	for ( let index = 0; index < text.length; index++ ) {
		const character = text[ index ];
		if ( character === '[' || character === '(' ) {
			depth++;
		} else if ( character === ']' || character === ')' ) {
			depth = Math.max( 0, depth - 1 );
		} else if ( depth === 0 && ( character === '>' || character === '+' || character === '~' || /\s/.test( character ) ) ) {
			start = index + 1;
		}
	}
	return text.slice( start ).trim();
}

/** Strip comments so a declaration inside one is never read as live CSS. */
function stripComments( css: string ): string {
	return String( css ?? '' ).replace( /\/\*[\s\S]*?\*\//g, '' );
}

interface FlatRule {
	selector: string;
	declarations: string;
}

/**
 * Every `selector { declarations }` pair, at any nesting depth.
 *
 * At-rules are walked INTO rather than skipped: a media query wrapping a
 * placement rule changes nothing about whether the rule can apply.
 */
function flattenRules( css: string ): FlatRule[] {
	const rules: FlatRule[] = [];
	const pattern = /([^{}]+)\{([^{}]*)\}/g;
	for ( const match of stripComments( css ).matchAll( pattern ) ) {
		const selector = match[ 1 ].trim();
		// An at-rule header (`@media (…)`) is not a selector and its block is
		// re-scanned by this same pass, so it is skipped rather than recorded.
		if ( selector.startsWith( '@' ) ) {
			continue;
		}
		rules.push( { selector, declarations: match[ 2 ] } );
	}
	return rules;
}

/**
 * Find box properties declared on `display: contents` elements.
 *
 * A rule may list several selectors; the inert one is reported on its own so the
 * message names the rule that must move.
 */
export function findInertDeclarations( css: string ): InertDeclaration[] {
	const rules = flattenRules( css );

	// The class tokens whose OWN rule makes the element box-less.
	const contentsClasses = new Set< string >();
	for ( const rule of rules ) {
		if ( ! /(?:^|;)\s*display\s*:\s*contents\b/i.test( rule.declarations ) ) {
			continue;
		}
		for ( const selector of rule.selector.split( ',' ) ) {
			for ( const token of classTokens( subjectOf( selector ) ) ) {
				contentsClasses.add( token );
			}
		}
	}
	if ( contentsClasses.size === 0 ) {
		return [];
	}

	const findings: InertDeclaration[] = [];
	for ( const rule of rules ) {
		// Declaration order, not INERT_PROPERTIES order: the property the author
		// wrote first is the one to name.
		let first: string | undefined;
		for ( const match of rule.declarations.matchAll( /(?:^|;)\s*([-a-z]+)\s*:/g ) ) {
			const property = match[ 1 ].toLowerCase();
			if ( first === undefined && INERT_PROPERTIES.has( property ) ) {
				first = property;
			}
		}
		if ( first === undefined ) {
			continue;
		}
		for ( const selector of rule.selector.split( ',' ).map( ( part ) => part.trim() ) ) {
			// Only the SUBJECT can be inert; a descendant of a contents element has
			// a box of its own.
			const hit = classTokens( subjectOf( selector ) ).find( ( token ) => contentsClasses.has( token ) );
			if ( ! hit ) {
				continue;
			}
			// One finding per rule is enough: every property in it is inert for
			// the same reason, and repeating them buries the signal.
			findings.push( { selector, property: first, contentsClass: hit } );
			break;
		}
	}
	return findings;
}

/** A one-line verdict, or `undefined` when no rule is inert. */
export function describeInertDeclarations( findings: InertDeclaration[] ): string | undefined {
	if ( findings.length === 0 ) {
		return undefined;
	}
	const named = findings
		.slice( 0, 4 )
		.map( ( finding ) => `${ finding.selector } { ${ finding.property } } — .${ finding.contentsClass } is display:contents` );
	const more = findings.length > 4 ? `, +${ findings.length - 4 } more` : '';
	return `${ findings.length } inert declaration(s): ${ named.join( '; ' ) }${ more }`;
}
