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
// the built HTML must carry the marker that fact produces.
// ---------------------------------------------------------------------------

import type { ForgeSection } from './types.ts';

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
}

/**
 * Only facts with a stable, unambiguous output marker — a marker that can
 * appear for another reason would make the audit lie. Anything speculative is
 * left out deliberately.
 */
const FACT_MARKERS: FactMarker[] = [
	{ key: 'bgGradient', label: 'bgGradient', marker: 'gradient' },
	{ key: 'bgColor', label: 'bgColor', marker: 'background' },
	{ key: 'padding', label: 'padding', marker: 'padding:' },
	{ key: 'measure', label: 'measure', marker: '--ds-measure' },
	{
		key: 'titleAlign',
		label: 'titleAlign: center',
		marker: 'ds-body--center',
		when: ( value ) => value === 'center',
	},
	{ key: 'titleStyle', label: 'titleStyle', marker: '--ds-title-size' },
	{ key: 'titleDivider', label: 'titleDivider', marker: 'ds-title-divider' },
	{ key: 'catalog', label: 'catalog', marker: 'ds-catalog' },
];

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
			if ( ! html.includes( fact.marker ) ) {
				unrendered.push( { sectionId: section.id, fact: fact.label, marker: fact.marker } );
			}
		}
	}
	return unrendered;
}
