// ---------------------------------------------------------------------------
// page-bands.ts — per-PAGE band facts (the multi-page half of `--geometry`).
//
// A positional band list is per-PAGE data. `analyze --geometry` measured the
// homepage only, so `sections.generated.ts` (positional for every builder whose
// bands carry no Elementor id) painted the front page's `minHeight`, padding and
// typography onto every sub-page: a doomsday article rendered as a 7,497px band
// (monicarajiv.com, 2026-11-11).
//
// This module measures each page the way the homepage is measured — bands from
// the rendered HTML, boxes at three viewports, presentation facts, and the
// band's own rendered surface — and the scaffold bakes the result into
// `src/lib/pages.generated.ts`. The renderer picks the page's own list
// positionally, which is exactly how the homepage list is applied.
//
// Fail-soft per page: one unreachable sub-page never blocks the baseline.
// ---------------------------------------------------------------------------

import { extractBandsFromHtml } from './bands.ts';
import { promoteOutlineBands } from './design.ts';
import { measureBandFacts, measureGeometry } from './geometry.ts';
import { measureRegionColors, applyBandSurfaces } from './region-colors.ts';
import type { ForgePageBands, ForgeSection } from './types.ts';

/** One page to measure. `link` from wp/v2 (absolute) is the fetch target. */
export interface PageBandsTarget {
	slug: string;
	url: string;
}

export interface MeasurePagesOptions {
	pages: PageBandsTarget[];
	/** Hard cap on measured pages (defense against a runaway site). */
	limit?: number;
	/** Per-page progress callback (the CLI prints it). */
	onPage?: ( slug: string, result: ForgePageBands | undefined, error?: unknown ) => void;
}

/** The band root selector map the measurement helpers take. */
function selectorsFor( sections: ForgeSection[] ): Record< string, string > {
	return Object.fromEntries(
		sections
			.filter( ( section ) => section.id && section.anchor )
			.map( ( section ) => [ section.id as string, `#${ section.anchor }` ] )
	);
}

/**
 * Measure every page's bands. Returns the pages that produced at least one
 * measurable band; a page whose markup yields no bands is reported and skipped
 * (the wireframe placeholders, a page the builder renders client-side only).
 */
export async function measurePageBands( options: MeasurePagesOptions ): Promise< Record< string, ForgePageBands > > {
	const limit = Math.max( 1, options.limit ?? 8 );
	const out: Record< string, ForgePageBands > = {};
	for ( const page of options.pages.slice( 0, limit ) ) {
		try {
			const response = await fetch( page.url );
			if ( ! response.ok ) {
				throw new Error( `HTTP ${ response.status }` );
			}
			const html = await response.text();
			const { bands, builder, adapter } = extractBandsFromHtml( html );
			if ( bands.length === 0 ) {
				options.onPage?.( page.slug, undefined );
				continue;
			}
			const sections = promoteOutlineBands( bands );
			const ids = sections.map( ( section ) => section.id ).filter( ( id ): id is string => Boolean( id ) );
			const selectors = selectorsFor( sections );
			const geometry = await measureGeometry( { url: page.url, ids, kind: 'source', selectors } );
			const measured = await measureBandFacts( { url: page.url, ids, kind: 'source', selectors } );
			const bandFacts = measured.bands;
			// The band's own surface: a composited background equal to the page body
			// is "transparent" (the site surface already paints it), so it is not
			// recorded — otherwise every band would carry the body colour.
			try {
				const regions = [
					{ name: '__body', selectors: [ 'body' ] },
					...sections
						.filter( ( section ) => section.id && section.anchor )
						.map( ( section ) => ( {
							name: section.id as string,
							selectors: [ `#${ section.anchor }` ],
							descendantFill: true,
						} ) ),
				];
				const colors = await measureRegionColors( { url: page.url, modes: [ 'light' ], regions, effective: true } );
				const light = colors.light ?? {};
				const bodyBg = light.__body?.background;
				applyBandSurfaces(
					sections,
					( section ) => ( section.id ? light[ section.id ] : undefined ),
					bodyBg
				);
			} catch {
				// Colour measurement is an enhancement; boxes and facts still ship.
			}
			out[ page.slug ] = {
				url: page.url,
				measuredAt: new Date().toISOString(),
				...( builder ?? adapter ? { builder: builder ?? adapter } : {} ),
				sections,
				geometry,
				bandFacts,
			};
			options.onPage?.( page.slug, out[ page.slug ] );
		} catch ( error ) {
			options.onPage?.( page.slug, undefined, error );
		}
	}
	return out;
}
