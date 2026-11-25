// ---------------------------------------------------------------------------
// page-design.ts — the per-PAGE design facts a sub-page never had.
//
// The homepage is measured twice: the browser pass (`measurePageBands` →
// geometry, band facts, region colours) and the design pass (`analyzeSections`
// → `design.sections`: surfaces, panels, layout, comparison, media). Sub-pages
// only ever got the first half, so `pageBands[slug].sections` carried an id and
// a column list and nothing else — which is why eca.directory's about-us and
// contact-us rebuilt with no hero photo, no band backgrounds and a collapsed
// split while their FAQ band (whose facts happen to be structural) looked right.
//
// This pass closes the asymmetry: fetch each page, run the SAME extractor the
// homepage uses, and merge the design facts onto that page's sections BY ID.
//
// Two rules:
//
//  1. **Measured geometry wins.** `columns` (and `vendorId`) come from the
//     browser pass and are never overwritten; the design extraction only fills
//     facts the page did not have.
//  2. **Match by id, never by position.** A section the extractor does not name
//     is reported, not guessed; a fact for an id the page does not list is
//     APPENDED (the renderer matches by id first) and reported.
// ---------------------------------------------------------------------------

import { analyzeSections, fetchDesignInput } from './design.ts';
import type { ForgePageBands, ForgeSection, SiteProfile } from './types.ts';

export interface PageDesignReport {
	slug: string;
	url: string;
	/** Bands the page's own design extraction found. */
	bands: number;
	/** Sections whose facts were merged. */
	matched: number;
	/** Facts for ids the page did not list (appended). */
	added: string[];
	/** Page sections the extractor did not name. */
	unnamed: string[];
	error?: string;
}

/** Facts the browser pass owns — the design extraction must not replace them. */
const MEASURED_KEYS = [ 'id', 'vendorId', 'columns' ] as const;

/**
 * Merge one page's design facts onto its measured sections.
 *
 * Returns a NEW entry; the input is not mutated (the caller decides about
 * `--write`).
 */
export function mergePageDesign(
	entry: ForgePageBands,
	facts: ForgeSection[]
): { entry: ForgePageBands; matched: number; added: string[]; unnamed: string[] } {
	const sections: ForgeSection[] = ( entry.sections ?? [] ).map( ( section ) => ( { ...section } ) );
	const byId = new Map< string, ForgeSection >();
	for ( const section of sections ) {
		if ( section.id ) {
			byId.set( section.id, section );
		}
	}
	let matched = 0;
	const added: string[] = [];
	for ( const fact of facts ) {
		if ( ! fact.id ) {
			continue;
		}
		const target = byId.get( fact.id );
		if ( ! target ) {
			// A band the outline pass missed keeps its measured geometry absent but
			// still gets its surfaces; the renderer matches by id first.
			sections.push( { ...fact } );
			byId.set( fact.id, sections[ sections.length - 1 ] );
			added.push( fact.id );
			continue;
		}
		const merged: Record< string, unknown > = { ...fact };
		for ( const key of MEASURED_KEYS ) {
			delete merged[ key ];
			const measured = ( target as Record< string, unknown > )[ key ];
			if ( measured !== undefined ) {
				merged[ key ] = measured;
			}
		}
		Object.assign( target, merged );
		matched += 1;
	}
	const named = new Set( facts.map( ( fact ) => fact.id ).filter( Boolean ) );
	const unnamed = sections
		.map( ( section ) => section.id )
		.filter( ( id ): id is string => Boolean( id ) && ! named.has( id ) );
	return { entry: { ...entry, sections }, matched, added, unnamed };
}

/**
 * Fetch one page and extract its design facts. Throws on a fetch failure — the
 * caller reports per page rather than aborting the whole run.
 */
export async function analyzePageDesign(
	url: string,
	fetchImpl: typeof fetch = fetch
): Promise< ForgeSection[] > {
	const input = await fetchDesignInput( url, fetchImpl, {} );
	return analyzeSections( input );
}

export interface PageDesignOptions {
	/** Restrict to these slugs (default: every measured page). */
	slugs?: string[];
	fetchImpl?: typeof fetch;
	onPage?: ( report: PageDesignReport ) => void;
}

/**
 * Run the design pass over a profile's measured pages and return a NEW profile.
 * Never throws: a page that fails is reported with `error` and left untouched.
 */
export async function analyzePageDesigns(
	profile: SiteProfile,
	options: PageDesignOptions = {}
): Promise< { profile: SiteProfile; reports: PageDesignReport[] } > {
	const pages = profile.pageBands ?? {};
	const wanted = options.slugs && options.slugs.length > 0 ? new Set( options.slugs ) : undefined;
	const next: Record< string, ForgePageBands > = { ...pages };
	const reports: PageDesignReport[] = [];
	for ( const [ slug, entry ] of Object.entries( pages ) ) {
		if ( wanted && ! wanted.has( slug ) ) {
			continue;
		}
		const url = typeof entry.url === 'string' ? entry.url : `${ profile.url.replace( /\/+$/, '' ) }/${ slug }/`;
		const base: PageDesignReport = { slug, url, bands: 0, matched: 0, added: [], unnamed: [] };
		try {
			const facts = await analyzePageDesign( url, options.fetchImpl );
			const merged = mergePageDesign( entry, facts );
			next[ slug ] = merged.entry;
			const report: PageDesignReport = {
				...base,
				bands: facts.filter( ( fact ) => fact.id ).length,
				matched: merged.matched,
				added: merged.added,
				unnamed: merged.unnamed,
			};
			reports.push( report );
			options.onPage?.( report );
		} catch ( error ) {
			const report: PageDesignReport = {
				...base,
				error: error instanceof Error ? error.message : String( error ),
			};
			reports.push( report );
			options.onPage?.( report );
		}
	}
	return { profile: { ...profile, pageBands: next }, reports };
}
