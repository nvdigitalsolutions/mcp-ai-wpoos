// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// box-ambiguity.ts — the classes whose measured box was DROPPED.
//
// `collectBandBoxFacts` refuses to record a class that measures differently on
// two instances: one rule cannot express both, and picking either paints one
// element's padding onto the other. That refusal is right — but it was only a
// console line during `analyze`, and the value was then simply absent. A
// rebuild renders the template default, which is indistinguishable from a source
// that never declared a box:
//
//   monicarajiv's `.col-inner` is `50px 180px 60px 108px` on the Rajiv column
//   and 0 elsewhere, so the class was dropped and EVERY column rendered flush —
//   a 432px content box became 720px (2026-11-20).
//
// The ambiguity is a precise signal: the value belongs in a per-INSTANCE channel
// (which is how it was fixed for columns). Surfacing it in `verify` means the
// next one is visible in the scorecard rather than in a scrolled-away log line.
// ---------------------------------------------------------------------------

import type { SiteProfile } from './types.ts';

/** One band with at least one class whose box could not be recorded. */
export interface AmbiguousBox {
	/** The page the band belongs to (`front page`, or the page slug). */
	scope: string;
	/** The band id. */
	band: string;
	/** The classes that were dropped. */
	classes: string[];
}

/**
 * Every band in the profile whose box pass dropped a class.
 *
 * Both fact stores are read: the front page keeps its bands in `bandFacts`, and
 * every other page keeps them in `pageBands[slug].bandFacts`. A profile can carry
 * either without the other.
 */
export function collectAmbiguousBoxes( profile: SiteProfile ): AmbiguousBox[] {
	const found: AmbiguousBox[] = [];

	const note = ( scope: string, bandFacts: Record< string, { boxWarnings?: string[] } > | undefined ): void => {
		for ( const [ band, facts ] of Object.entries( bandFacts ?? {} ) ) {
			const classes = facts?.boxWarnings ?? [];
			if ( classes.length > 0 ) {
				found.push( { scope, band, classes: [ ...classes ].sort() } );
			}
		}
	};

	note( 'front page', profile.bandFacts );
	for ( const [ slug, page ] of Object.entries( profile.pageBands ?? {} ) ) {
		note( slug, page?.bandFacts );
	}
	return found;
}

/** A one-line verdict, or `undefined` when every measured box was recorded. */
export function describeAmbiguousBoxes( boxes: AmbiguousBox[] ): string | undefined {
	if ( boxes.length === 0 ) {
		return undefined;
	}
	const total = boxes.reduce( ( count, box ) => count + box.classes.length, 0 );
	const named = boxes
		.slice( 0, 3 )
		.map( ( box ) => `${ box.classes.map( ( token ) => `.${ token }` ).join( ', ' ) } (${ box.scope }/${ box.band })` );
	const more = boxes.length > 3 ? `, +${ boxes.length - 3 } more` : '';
	return (
		`${ total } class box(es) dropped as ambiguous: ${ named.join( '; ' ) }${ more }. ` +
		'One rule cannot express two different boxes, so the value needs a per-instance channel ' +
		'(as `columnInner` now provides for a padded column).'
	);
}
