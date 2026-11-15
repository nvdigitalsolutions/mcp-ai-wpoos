// ---------------------------------------------------------------------------
// fact-audit.ts — did the build actually render the facts it was given?
//
// The 125 section facts live in the profile and reach the build through
// `sections.generated.ts`. A component can simply not read them: jayainc's
// feature grid had titleStyle/titleDivider/titleAlign baked into the profile
// and still rendered a plain left-aligned heading, because FeatureGrid was
// never passed the section theme. No existing gate noticed — the band was
// present, the CSS language scored fine, nothing compared facts to output.
//
// This is a static check: for each fact a band declares, the band's slice of
// the built HTML must carry the marker that fact produces. A fact that can
// render OUTSIDE that slice (a device-scoped padding emits a `<style>` rule
// ahead of the band) supplies its own `present` search instead.
// ---------------------------------------------------------------------------

import type { ForgeSection } from './types.ts';
import { isSectionKind } from './types.ts';
import { scopedDeclarationOf, scopedPaddingOf } from './scoped-css.ts';

export interface UnrenderedFact {
	sectionId: string;
	fact: string;
	marker: string;
}

interface FactMarker {
	/** The section field that carries the fact. */
	key: string;
	/** Human label used in the report. */
	label: string;
	/** Text the build must contain when the fact is present. */
	marker: string;
	/** Only audit when the value matches (e.g. centred alignment). */
	when?: ( value: unknown ) => boolean;
	/** Override the marker search for facts that can render OUTSIDE the band's
	 * own slice — a device-scoped padding emits a `[data-section-id]` rule in a
	 * `<style>` block, which sits before the band element. */
	present?: ( ctx: {
		html: string;
		sectionId: string;
		value: unknown;
		scopedPadding: Map< string, string >;
		scopedMeasure: Map< string, string >;
	} ) => boolean;
}

/**
 * Only facts with a stable, unambiguous output marker — a marker that can
 * appear for another reason would make the audit lie. Anything speculative is
 * left out deliberately.
 */
const FACT_MARKERS: FactMarker[] = [
	{ key: 'bgGradient', label: 'bgGradient', marker: 'gradient' },
	{ key: 'bgColor', label: 'bgColor', marker: 'background' },
	{
		key: 'padding',
		label: 'padding',
		marker: 'padding:',
		// A plain-string padding is inline on the band; a device-scoped map can
		// only be a `[data-section-id=…]{padding:…}` rule in a <style> block
		// ahead of it. Each form is checked against its own marker, so a stray
		// inline `padding:` cannot mask a map that emitted no scoped rule.
		present: ( { html, sectionId, value, scopedPadding } ) =>
			typeof value === 'string' ? html.includes( 'padding:' ) : scopedPadding.has( sectionId ),
	},
	{ key: 'measure', label: 'measure', marker: '--ds-measure',
		// A measured container is now a per-device map (1440 / 834 / 390), which
		// cannot be inline; a plain string stays inline. Check both channels.
		present: ( { html, sectionId, value, scopedMeasure } ) =>
			typeof value === 'string' ? html.includes( '--ds-measure' ) || scopedMeasure.has( sectionId ) : scopedMeasure.has( sectionId ),
	},
	{
		key: 'titleAlign',
		label: 'titleAlign: center',
		marker: 'ds-body--center',
		when: ( value ) => value === 'center',
	},
	{ key: 'titleStyle', label: 'titleStyle', marker: '--ds-title-size' },
	// The measured band typography: emitted as a scoped `[data-section-id] h3{…}`
	// rule in a <style> block AHEAD of the band, so it can never appear in the
	// band's own slice.
	{
		key: 'type',
		label: 'band typography',
		marker: '[data-section-id] h…{font-size}',
		present: ( { html, sectionId } ) =>
			new RegExp( `\\[data-section-id="${ sectionId.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }"\\]\\s+h[1-6]\\{` ).test(
				html
			),
	},
	{ key: 'titleDivider', label: 'titleDivider', marker: 'ds-title-divider' },
	{ key: 'catalog', label: 'catalog', marker: 'ds-catalog' },
	// Proposal 136 §4.2 — the band's classified kind. A DECLARED kind must reach
	// the build as `data-section="<kind>"` on the band itself.
	{
		key: 'kind',
		label: 'section kind',
		marker: 'data-section=',
		present: ( { html, value } ) => html.includes( `data-section="${ String( value ) }"` ),
	},
];

/** The `data-section` value the built band carries, if any. */
function sectionKindOf( html: string ): string | undefined {
	return /data-section="([^"]*)"/.exec( html )?.[ 1 ];
}

/**
 * the built HTML belonging to one band: from its element tag to the next
 * band's element tag. Matching the TAG matters — a bare `data-section-id`
 * search hits the responsive-margin `<style>` block first
 * (`[data-section-id="…"]{…}`), which would slice pure CSS and report every
 * fact as unrendered.
 */
function sectionHtml( html: string, id: string ): string | undefined {
	const tags = [ ...html.matchAll( /<[a-z][^>]*\sdata-section-id="([^"]+)"[^>]*>/g ) ];
	const index = tags.findIndex( ( tag ) => tag[ 1 ] === id );
	if ( index === -1 ) {
		return undefined;
	}
	const start = tags[ index ].index ?? 0;
	const next = tags[ index + 1 ];
	return html.slice( start, next?.index ?? html.length );
}

/**
 * Every declared fact the built page does not render. A band absent from the
 * build is skipped — the band-flow gate already reports dropped bands, and
 * reporting it twice would bury the fact findings.
 */
export function auditFacts( sections: ForgeSection[], builtHtml: string ): UnrenderedFact[] {
	const unrendered: UnrenderedFact[] = [];
	const scopedPadding = scopedPaddingOf( builtHtml );
	const scopedMeasure = scopedDeclarationOf( builtHtml, '--ds-measure' );
	for ( const section of sections ) {
		if ( ! section.id ) {
			continue;
		}
		const html = sectionHtml( builtHtml, section.id );
		if ( ! html ) {
			continue;
		}
		const fields = section as unknown as Record< string, unknown >;
		for ( const fact of FACT_MARKERS ) {
			const value = fields[ fact.key ];
			if ( value === undefined || value === null || value === '' ) {
				continue;
			}
			if ( fact.when && ! fact.when( value ) ) {
				continue;
			}
			const rendered = fact.present
				? fact.present( { html, sectionId: section.id, value, scopedPadding, scopedMeasure } )
				: html.includes( fact.marker );
			if ( ! rendered ) {
				unrendered.push( { sectionId: section.id, fact: fact.label, marker: fact.marker } );
			}
		}
		// Proposal 136 §4.2 — every built band carries a kind, and it is one of
		// the closed vocabulary. This is what makes the output classifiable by an
		// agent without re-running the analyzer. Checked independently of the
		// `kind` marker above: a band that declares no kind must still emit one,
		// and an out-of-enum value is a failure even when the strings happen to
		// match a declared value elsewhere.
		const emittedKind = sectionKindOf( html );
		if ( ! emittedKind ) {
			unrendered.push( { sectionId: section.id, fact: 'section kind', marker: 'data-section=' } );
		} else if ( ! isSectionKind( emittedKind ) ) {
			unrendered.push( { sectionId: section.id, fact: `section kind (out of vocabulary: ${ emittedKind })`, marker: 'data-section=' } );
		}
	}
	return unrendered;
}
