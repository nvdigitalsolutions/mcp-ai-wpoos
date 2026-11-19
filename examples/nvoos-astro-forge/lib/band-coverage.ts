// ---------------------------------------------------------------------------
// band-coverage.ts — did a measurement pass actually MATCH anything?
//
// The forge is fail-soft by design: one unreachable page must not block a
// baseline. That is right for errors, but it turned a whole class of staleness
// into silence. monicarajiv's sub-pages carried `interactions: []` for every
// band because Flatsome re-issues each `<section id>` on every render and the
// stored anchors had gone stale — every pass returned `{}` and the pipeline
// proceeded as if the site simply had no controls (2026-11-20).
//
// A pass that is asked for N band roots and matches 0 is not "a site with no
// facts"; it is an unmeasured page. These helpers make the caller say so.
// ---------------------------------------------------------------------------

/** What a pass was asked for, and what it actually resolved. */
export interface BandCoverage {
	/** How many band roots the pass requested. */
	requested: number;
	/** How many of them produced at least one fact. */
	matched: number;
	/** The ids that resolved to nothing. */
	missing: string[];
}

/**
 * Compare the requested band ids with the ids a pass produced facts for.
 *
 * `found` is the pass's result keyed by band id, so a band that was measured but
 * legitimately produced no *state* (a band with no interactive element) counts as
 * MATCHED. Only a band root that could not be resolved is missing.
 */
export function bandCoverage( requested: string[], found: Record< string, unknown > ): BandCoverage {
	const matched = requested.filter( ( id ) => Object.hasOwn( found, id ) );
	return {
		requested: requested.length,
		matched: matched.length,
		missing: requested.filter( ( id ) => ! Object.hasOwn( found, id ) ),
	};
}

/**
 * A one-line verdict for the caller to print, or `undefined` when every
 * requested root resolved (the common case, which stays quiet).
 *
 * The zero-match case reads as an ERROR, not a warning: continuing with it
 * silently is what produced a profile whose facts were empty rather than absent.
 */
export function describeBandCoverage( label: string, coverage: BandCoverage ): string | undefined {
	if ( coverage.requested === 0 || coverage.matched === coverage.requested ) {
		return undefined;
	}
	const sample = coverage.missing.slice( 0, 4 ).join( ', ' );
	const more = coverage.missing.length > 4 ? `, +${ coverage.missing.length - 4 } more` : '';
	if ( coverage.matched === 0 ) {
		return (
			`✗ ${ label }: 0 of ${ coverage.requested } band root(s) resolved ` +
			`(${ sample }${ more }). The page was almost certainly re-rendered under ` +
			'different ids — a builder re-issues them per render and the cache window ' +
			'has rolled — so this pass measured NOTHING. Treat the profile as unmeasured, ' +
			'not as a site with no facts.'
		);
	}
	return (
		`⚠ ${ label }: ${ coverage.missing.length } of ${ coverage.requested } band root(s) ` +
		`resolved nothing (${ sample }${ more }) — those bands carry no facts.`
	);
}
