// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// property-coverage.ts — a measured fact that never reaches the stylesheet.
//
// The band audit checks that DECLARED facts render, which cannot see a fact the
// scaffold dropped before it was ever declared. In one feature that class of gap
// produced four separate regressions:
//
//   veil        dropped by the element serializer AND by the emitted type
//   logoAlign   dropped by the chrome serializer
//   lineHeight  read at rest, absent from the paint allowlist
//
// A hero rendered unshaded and an outline button lost its border, and both
// builds were green. This walks the facts the PROFILE carries and asks the built
// stylesheet whether it ever declares them.
// ---------------------------------------------------------------------------

/** Which measured properties the built stylesheet actually declares. */
export interface PropertyCoverage {
	/** Normalised property names the profile's facts carry. */
	measured: string[];
	/** Of those, the ones no rule in the built CSS declares. */
	unrendered: string[];
}

/**
 * Pull the property names out of a CSS declaration list.
 *
 * Only the property NAME matters here (the value is compared elsewhere), so a
 * declaration is anything before a `:` at the start of a statement.
 */
export function declarationProperties( css: string ): string[] {
	const out = new Set< string >();
	for ( const match of String( css ?? '' ).matchAll( /(?:^|[;{])\s*([-a-zA-Z]+)\s*:/g ) ) {
		out.add( match[ 1 ].toLowerCase() );
	}
	return [ ...out ];
}

/**
 * Compare the properties the profile's facts carry with the properties the built
 * stylesheet declares.
 *
 * Deliberately ONE-DIRECTIONAL: a stylesheet declaring properties the profile
 * never measured is the template doing its job, not drift. The signal is a
 * measured property that appears nowhere.
 */
export function propertyCoverage( measuredDeclarations: string[], builtCss: string ): PropertyCoverage {
	const measured = [ ...new Set( measuredDeclarations.flatMap( ( css ) => declarationProperties( css ) ) ) ].sort();
	const declared = new Set( declarationProperties( builtCss ) );
	return {
		measured,
		unrendered: measured.filter( ( property ) => ! declared.has( property ) ),
	};
}

/** A one-line verdict, or `undefined` when every measured property renders. */
export function describePropertyCoverage( coverage: PropertyCoverage ): string | undefined {
	if ( coverage.unrendered.length === 0 ) {
		return undefined;
	}
	return `⚠ ${ coverage.unrendered.length } measured propert(ies) never reach the stylesheet: ${ coverage.unrendered.join( ', ' ) }`;
}
