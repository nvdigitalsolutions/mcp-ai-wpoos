// SPDX-License-Identifier: MIT
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

import { Parser, DomHandler } from 'htmlparser2';

import { analyzeSections, fetchDesignInput } from './design.ts';
import type { ForgePageBands, ForgeSection, SiteProfile } from './types.ts';

/** The shape htmlparser2 hands back — only what the lane walk reads. */
type DomNode = {
	type?: string;
	attribs?: Record< string, string >;
	children?: DomNode[];
};

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
 * The lane image URLS in a page's stylesheet, keyed by the element token.
 *
 * The browser pass records `image: true` for a lane that paints a photo, but
 * the URL lives in the band's CSS — not in the markup — so the rebuild dropped
 * the lane entirely (eca.directory's "Why choose" and "Get in touch" photo
 * columns, 2026-11-25). One pass over the rules is enough: the FIRST declaration
 * for a token wins, which is the cascade's own preference for the base rule.
 */
export function laneImageUrls( css: string ): Map< string, string > {
	const out = new Map< string, string >();
	for ( const rule of css.matchAll( /([^{}]+)\{([^{}]*)\}/g ) ) {
		const url = /background-image\s*:\s*url\(["']?([^"')]+)/i.exec( rule[ 2 ] );
		if ( ! url ) {
			continue;
		}
		for ( const token of rule[ 1 ].matchAll( /elementor-element-([0-9a-f]{4,})/gi ) ) {
			const key = `elementor-element-${ token[ 1 ].toLowerCase() }`;
			if ( ! out.has( key ) ) {
				out.set( key, url[ 1 ].trim() );
			}
		}
	}
	return out;
}

/**
 * The image a lane paints, resolved through its SUBTREE.
 *
 * The browser pass records `image: true` for a lane that paints a photo, but the
 * URL lives in the band's CSS rather than the markup. Elementor also does not
 * always paint it on the lane itself: an absolutely-positioned child carries it
 * instead, and the lane's own `elementor-element-<id>` then has no rule at all
 * (eca.directory's about-us "Why choose" photo lane, 2026-11-25 — the photo sits
 * on a child spacer with `elementor-absolute`). The browser sees ONE painted
 * box; the stylesheet names the child.
 *
 * So a lane resolves to the first background photo on its own token or any
 * descendant, in DOCUMENT order — the same box the browser measured. Returns a
 * lookup keyed by the lane's own token; a lane with no subtree photo and a full
 * stylesheet still resolves through the flat map.
 */
export function laneImageResolver(
	html: string,
	css: string,
	base?: string
): ( selector: string ) => string | undefined {
	const urls = laneImageUrls( css );
	const resolved = new Map< string, string >();
	const absolute = ( url: string ): string => {
		if ( ! base || /^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test( url ) ) {
			return url;
		}
		try {
			return new URL( url, base ).toString();
		} catch {
			return url;
		}
	};
	const ownTokens = ( node: DomNode ): string[] => {
		const classes = ( node.attribs?.class ?? '' ).split( /\s+/ );
		const out: string[] = [];
		for ( const token of classes ) {
			if ( /^elementor-element-[0-9a-f]{4,}$/i.test( token ) ) {
				out.push( token.toLowerCase() );
			}
		}
		return out;
	};
	// Pre-order: the node's own paint wins over anything nested inside it. A
	// DECORATIVE spacer only ranks last — Elementor paints blobs and shapes on
	// absolute spacers beside the picture, and the rebuild wants the picture
	// (eca.directory's about-us lane carries four blob spacers AND the photo).
	const isSpacerWidget = ( node: DomNode ): boolean =>
		( node.attribs?.class ?? '' ).split( /\s+/ ).includes( 'elementor-widget-spacer' );
	const collectPainted = ( node: DomNode, out: Array< { url: string; decorative: boolean } > ): void => {
		for ( const token of ownTokens( node ) ) {
			const url = urls.get( token );
			if ( url ) {
				out.push( { url, decorative: isSpacerWidget( node ) } );
			}
		}
		for ( const child of node.children ?? [] ) {
			if ( child.type === 'tag' ) {
				collectPainted( child, out );
			}
		}
	};
	const firstUrl = ( node: DomNode ): string | undefined => {
		const found: Array< { url: string; decorative: boolean } > = [];
		collectPainted( node, found );
		return found.find( ( entry ) => ! entry.decorative )?.url ?? found[ 0 ]?.url;
	};
	if ( urls.size > 0 && html.trim() !== '' ) {
		const handler = new DomHandler( undefined, {} );
		const parser = new Parser( handler, { lowerCaseTags: true, lowerCaseAttributeNames: true } );
		parser.write( html );
		parser.end();
		const walk = ( node: DomNode ): void => {
			for ( const token of ownTokens( node ) ) {
				if ( resolved.has( token ) ) {
					continue;
				}
				const url = firstUrl( node );
				if ( url ) {
					resolved.set( token, absolute( url ) );
				}
			}
			for ( const child of node.children ?? [] ) {
				if ( child.type === 'tag' ) {
					walk( child );
				}
			}
		};
		for ( const node of ( handler.dom ?? [] ) as DomNode[] ) {
			walk( node );
		}
	}
	return ( selector: string ): string | undefined => {
		const key = String( selector ?? '' ).toLowerCase();
		const url = resolved.get( key ) ?? urls.get( key );
		return url ? absolute( url ) : undefined;
	};
}

/**
 * Merge one page's design facts onto its measured sections.
 *
 * Returns a NEW entry; the input is not mutated (the caller decides about
 * `--write`). `css` lets a measured photo lane find the URL the browser saw but
 * the markup does not carry.
 */
export function mergePageDesign(
	entry: ForgePageBands,
	facts: ForgeSection[],
	css?: string,
	html?: string,
	base?: string
): { entry: ForgePageBands; matched: number; added: string[]; unnamed: string[] } {
	const sections: ForgeSection[] = ( entry.sections ?? [] ).map( ( section ) => ( { ...section } ) );
	const byId = new Map< string, ForgeSection >();
	for ( const section of sections ) {
		if ( section.id ) {
			byId.set( section.id, section );
		}
	}
	const laneImage = css && css.trim() !== '' ? laneImageResolver( html ?? '', css, base ) : undefined;
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
		// A stored `columns: []` is residue from a pass that knew nothing about the
		// band, not a measurement — drop it before the measured keys are read.
		const storedColumns = ( target as Record< string, unknown > ).columns;
		if ( Array.isArray( storedColumns ) && storedColumns.length === 0 ) {
			delete ( target as Record< string, unknown > ).columns;
		}
		const merged: Record< string, unknown > = { ...fact };
		// An EMPTY lane list is the extractor saying nothing, not measured layout.
		// Keeping it would tell the renderer the band is a zero-lane grid.
		if ( Array.isArray( merged.columns ) && merged.columns.length === 0 ) {
			delete merged.columns;
		}
		for ( const key of MEASURED_KEYS ) {
			delete merged[ key ];
			const measured = ( target as Record< string, unknown > )[ key ];
			if ( measured !== undefined ) {
				merged[ key ] = measured;
			}
		}
		Object.assign( target, merged );
		// A lane the browser saw as a PHOTO gets its URL from the page's own CSS.
		// Without it the lane carries `image: true` and nothing to paint, so the
		// rebuild drops the column (and the split collapses).
		const lanes = entry.bandFacts?.[ fact.id ]?.columns?.desktop;
		// Only onto an EXISTING measured lane list. A page whose geometry recorded
		// no lanes has no ratios to attach a picture to, and splicing one in by index
		// produced a hole (`[null, {bgImage}]`) that read as a layout fact. The
		// split hero's picture comes from the band's element facts instead.
		const measuredLanes = Array.isArray( target.columns ) ? target.columns : [];
		if ( Array.isArray( lanes ) && laneImage && measuredLanes.length >= lanes.length ) {
			lanes.forEach( ( lane, index ) => {
				if ( ! lane || lane.image !== true || ! lane.selector ) {
					return;
				}
				const url = laneImage( String( lane.selector ) );
				if ( ! url ) {
					return;
				}
				const column = measuredLanes[ index ];
				if ( ! column ) {
					return;
				}
				// The resolver is the authority: a previous pass may have stored a
				// decorative blob (the first painted spacer) where the picture lives.
				column.bgImage = url;
			} );
		}
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
export interface PageDesignExtraction {
	facts: ForgeSection[];
	/** The page's stylesheets, joined — see `laneImageUrls`. */
	css: string;
	/** The page's own HTML — see `laneImageResolver`. */
	html: string;
}

/**
 * Fetch one page and extract its design facts. Throws on a fetch failure — the
 * caller reports per page rather than aborting the whole run.
 *
 * `includeBare` keeps the bands that state no fact: the merge matches by id, and
 * a band the extractor drops can never be matched.
 */
export async function analyzePageDesign(
	url: string,
	fetchImpl: typeof fetch = fetch
): Promise< PageDesignExtraction > {
	const input = await fetchDesignInput( url, fetchImpl, {} );
	return {
		facts: analyzeSections( { ...input, includeBare: true } ),
		css: input.css.join( '\n' ),
		html: input.html,
	};
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
			const extraction = await analyzePageDesign( url, options.fetchImpl );
			const merged = mergePageDesign( entry, extraction.facts, extraction.css, extraction.html, url );
			next[ slug ] = merged.entry;
			const report: PageDesignReport = {
				...base,
				bands: extraction.facts.filter( ( fact ) => fact.id ).length,
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
