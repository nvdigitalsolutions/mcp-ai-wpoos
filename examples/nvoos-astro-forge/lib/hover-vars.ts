// ---------------------------------------------------------------------------
// hover-vars.ts — the band's measured HOVER deltas as CSS variables.
//
// `ForgeInteractionFact` records what a SOURCE control did on hover, keyed to the
// source's own classes. A design-rebuilt band does not carry those classes, so
// the scoped interaction rules emitted from the facts match nothing and the state
// is lost: virtuallysrilanka reported 16 of 28 expected states missing — its
// buttons never turned black and its four wifi tiles never took their dark slab.
//
// The VALUES are real measurements, so they ride variables the stylesheet applies
// to the components the classifier rebuilt:
//
//   `--ds-btn-*`  a control that repaints its own background (a button/link);
//   `--ds-card-*` a CHILD that repaints its background while the parent is in the
//                 state (a builder's icon-box wrapper IS the tile).
//
// Vendored into every generated project (`FORGE_LIB_FILES`) and deliberately
// free of Playwright: the measuring half lives in `interactions.ts`, which only
// the CLI runs.
// ---------------------------------------------------------------------------

/**
 * The state strings this module reads — a structural subset of
 * `ForgeInteractionFact`, so the mapping can be exercised without inventing the
 * selectors it deliberately ignores.
 */
export interface HoverStateSource {
	transition?: string;
	hover?: string;
	active?: string;
	children?: Array< { selector?: string; transition?: string; hover?: string; active?: string } >;
}

/** One declaration's value out of a `prop:value;prop:value` string. */
const declarationOf = ( css: string | undefined, property: string ): string | undefined => {
	if ( ! css ) {
		return undefined;
	}
	const escaped = property.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	const match = new RegExp( `(?:^|;)\\s*${ escaped }\\s*:\\s*([^;]+)`, 'i' ).exec( css );
	return match?.[ 1 ]?.trim() || undefined;
};

/**
 * The declarations that carry a band's measured hover states onto the rebuilt
 * components. Empty when the band recorded no states, so the stylesheet's own
 * fallback (a `brightness()` shift) still applies.
 */
export function hoverComponentVars( facts: HoverStateSource[] | undefined ): string[] {
	const vars: string[] = [];
	for ( const fact of facts ?? [] ) {
		const background =
			declarationOf( fact.hover, 'background-color' ) ??
			declarationOf( fact.hover, 'background' );
		const ink = declarationOf( fact.hover, 'color' );
		const shadow = declarationOf( fact.hover, 'box-shadow' );
		if ( background ) {
			vars.push( `--ds-btn-bg-hover:${ background }` );
			// A measured repaint replaces the generic brightness shift; keeping it
			// would darken the measured ink along with the fill.
			vars.push( '--ds-btn-filter-hover:none' );
		}
		if ( ink ) {
			vars.push( `--ds-btn-ink-hover:${ ink }` );
		}
		if ( shadow ) {
			vars.push( `--ds-btn-shadow-hover:${ shadow }` );
		}
		if ( ( background || ink || shadow ) && fact.transition ) {
			vars.push( `--ds-btn-transition:${ fact.transition }` );
		}
		for ( const child of fact.children ?? [] ) {
			const childBackground =
				declarationOf( child.hover, 'background-color' ) ??
				declarationOf( child.active, 'background-color' );
			if ( childBackground ) {
				vars.push( `--ds-card-bg-hover:${ childBackground }` );
				if ( child.transition ) {
					vars.push( `--ds-card-transition:${ child.transition }` );
				}
			}
		}
	}
	return vars;
}
