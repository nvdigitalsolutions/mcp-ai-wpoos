// SPDX-License-Identifier: MIT
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
import { extractElementCss } from './element-css.ts';
import { promoteOutlineBands } from './design.ts';
import { measureBandFacts, measureGeometry } from './geometry.ts';
import { measureRegionColors, applyBandSurfaces, bandRegionName, bandRegionSelector } from './region-colors.ts';
import { measureInteractions } from './interactions.ts';
import { bandCoverage, describeBandCoverage } from './band-coverage.ts';
import { createHash } from 'node:crypto';
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
			.map( ( section ) => {
				const selector = bandRegionSelector( section );
				return section.id && selector ? [ section.id, selector ] : undefined;
			} )
			.filter( ( entry ): entry is [ string, string ] => Boolean( entry ) )
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
			// The page's own per-element rules. See `ForgePageBands.elementCss`: the
			// homepage harvest cannot cover a sub-page, because the two differ in
			// layer classes even when the layout is the same.
			const elementCss = extractElementCss( html );
			const ids = sections.map( ( section ) => section.id ).filter( ( id ): id is string => Boolean( id ) );
			const selectors = selectorsFor( sections );
			const geometry = await measureGeometry( { url: page.url, ids, kind: 'source', selectors } );
			const measured = await measureBandFacts( { url: page.url, ids, kind: 'source', selectors } );
			const bandFacts = measured.bands;
			// FAIL LOUD. A pass that resolves nothing has not measured "a page with
			// no facts"; it has failed, and continuing quietly is what shipped a
			// profile whose facts were EMPTY rather than absent.
			{
				const coverage = describeBandCoverage( `band facts (${ page.slug })`, bandCoverage( ids, bandFacts ) );
				if ( coverage ) {
					console.warn( `  ${ coverage }` );
				}
			}
			// Interaction states (hover / focus / focus-visible / active) are part of the
			// band's measured truth, not a homepage extra. The vendor sheet never ships, and
			// a control's BASE declarations ride the same facts — `.button.is-outline` keeps
			// its 2px border and its ink only through them. Without this pass a sub-page
			// button rendered borderless and inherited the accent colour.
			try {
				const { facts: interactions } = await measureInteractions( {
					url: page.url,
					ids,
					selectors,
				} );
				const coverage = describeBandCoverage(
					`interaction states (${ page.slug })`,
					bandCoverage( ids, interactions )
				);
				if ( coverage ) {
					console.warn( `  ${ coverage }` );
				}
				for ( const [ id, facts ] of Object.entries( interactions ) ) {
					bandFacts[ id ] = { ...( bandFacts[ id ] ?? {} ), interactions: facts };
				}
			} catch {
				// States are an enhancement; boxes and facts still ship.
			}
			// The band's own surface: a composited background equal to the page body
			// is "transparent" (the site surface already paints it), so it is not
			// recorded — otherwise every band would carry the body colour.
			try {
				const regions: Array< { name: string; selectors: string[]; descendantFill?: boolean } > = [
					{ name: '__body', selectors: [ 'body' ] },
				];
				for ( const section of sections ) {
					const selector = bandRegionSelector( section );
					const name = bandRegionName( section );
					if ( selector && name ) {
						regions.push( { name, selectors: [ selector ], descendantFill: true } );
					}
				}
				const colors = await measureRegionColors( { url: page.url, modes: [ 'light' ], regions, effective: true } );
				const light = colors.light ?? {};
				const bodyBg = light.__body?.background;
				applyBandSurfaces(
					sections,
					( section ) => {
						const name = bandRegionName( section );
						return name ? light[ name ] : undefined;
					},
					bodyBg
				);
			} catch {
				// Colour measurement is an enhancement; boxes and facts still ship.
			}
			// The structural adapter wins over the fingerprint "mention" pass: a
			// Flatsome page usually carries a stray `wp-block-*` class, so `builder`
			// came out as `gutenberg` while every band was segmented by `flatsome`.
			// Builder utilities are keyed by this id, so the mislabel made
			// `text-center` (and every other Flatsome convention) inert on sub-pages.
			const structural =
				adapter && adapter !== 'semantic' && adapter !== 'visual' ? adapter : builder;
			out[ page.slug ] = {
				url: page.url,
				measuredAt: new Date().toISOString(),
				// The snapshot these facts describe (see `ForgePageBands.sourceHash`).
				sourceHash: createHash( 'sha256' ).update( html ).digest( 'hex' ).slice( 0, 16 ),
				...( structural ? { builder: structural } : {} ),
				sections,
				geometry,
				bandFacts,
				...( elementCss.length > 0 ? { elementCss } : {} ),
			};
			options.onPage?.( page.slug, out[ page.slug ] );
		} catch ( error ) {
			options.onPage?.( page.slug, undefined, error );
		}
	}
	return out;
}
